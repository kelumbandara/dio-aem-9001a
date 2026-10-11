<?php

namespace App\Modules\Sales\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\NumberingService;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Models\Quotation;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Quotations: an offer to a customer. Only a draft can be edited; sent → accepted | rejected; an accepted quotation is
 * turned into a sales order (and then shows as converted). Nothing here touches stock or the ledgers.
 */
final class QuotationService
{
    private const FLOW = [
        'draft' => ['sent', 'cancelled'],
        'sent' => ['accepted', 'rejected', 'cancelled'],
        'accepted' => ['cancelled'],
    ];

    public function __construct(
        private readonly DocumentHeader $header,
        private readonly LineBuilder $builder,
        private readonly NumberingService $numbering,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create a draft, or replace a draft's content (the version must match what the person was looking at).
     *
     * @param  array<string, mixed>  $data  customer|customer_id, quote_date?, valid_until?, reference?, notes?, terms?, lines, version? (update)
     *
     * @throws BusinessException
     */
    public function save(array $data, ?Quotation $quotation = null, ?int $by = null): Quotation
    {
        return DB::transaction(function () use ($data, $quotation, $by): Quotation {
            if ($quotation !== null) {
                $quotation = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
                if ($quotation->status !== 'draft') {
                    throw SalesException::state('quotation', $quotation->status, 'a draft');
                }
                if (isset($data['version']) && (int) $data['version'] !== $quotation->version) {
                    throw SalesException::stale('quotation');
                }
            }

            $customer = $this->header->customer($data);
            $quoteDate = $this->header->date($data, 'quote_date', $this->header->today());
            $validUntil = $this->header->date($data, 'valid_until', CarbonImmutable::parse($quoteDate)->addDays(30)->toDateString());
            if ($validUntil < $quoteDate) {
                throw SalesException::make('sales.valid_until_invalid', 'A quotation cannot expire before its date.', 422, 'valid_until');
            }
            $inclusive = $quotation !== null ? $quotation->prices_include_vat : (bool) config('sales.prices_include_vat');
            $built = $this->builder->build((array) ($data['lines'] ?? []), $customer->id, $quoteDate, $inclusive);

            $values = [
                'customer_id' => $customer->id, 'quote_date' => $quoteDate, 'valid_until' => $validUntil,
                'reference' => $this->text($data['reference'] ?? null, 100), 'notes' => $this->text($data['notes'] ?? null, 2000), 'terms' => $this->text($data['terms'] ?? null, 2000),
                'net_total' => $built['totals']['net'], 'vat_total' => $built['totals']['vat'], 'levy_total' => $built['totals']['levy'], 'gross_total' => $built['totals']['gross'],
            ];

            if ($quotation === null) {
                $this->numbering->define('sales.quotation', 'QUO/{FY}/{SEQ}', 5, 'fiscal_yearly');
                $quotation = Quotation::query()->create($values + [
                    'document_no' => $this->numbering->next('sales.quotation', null, CarbonImmutable::parse($quoteDate)), 'status' => 'draft', 'version' => 1, 'created_by' => $by, 'prices_include_vat' => $inclusive,
                ]);
            } else {
                $quotation->forceFill($values + ['version' => $quotation->version + 1])->save();
                $quotation->lines()->delete();
            }
            foreach ($built['lines'] as $line) {
                $quotation->lines()->create($line);
            }

            $this->audit->record('sales.quotation_saved', $quotation, [], ['document_no' => $quotation->document_no, 'customer_id' => $customer->id, 'gross_total' => $quotation->gross_total]);

            return $quotation->refresh();
        });
    }

    /** @throws BusinessException */
    public function transition(Quotation $quotation, string $to, ?int $by = null): Quotation
    {
        return DB::transaction(function () use ($quotation, $to, $by): Quotation {
            $quotation = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if (! in_array($to, self::FLOW[$quotation->status] ?? [], true)) {
                throw SalesException::make('sales.invalid_state', "A {$quotation->status} quotation cannot become {$to}.", 409);
            }
            if ($to === 'accepted' && $quotation->valid_until->toDateString() < $this->header->today()) {
                throw SalesException::make('sales.quotation_expired', 'This quotation has expired; make a new one.', 422);
            }
            $from = $quotation->status;
            $quotation->forceFill(['status' => $to, 'version' => $quotation->version + 1])->save();
            $this->audit->record('sales.quotation_'.$to, $quotation, ['status' => $from], ['status' => $to, 'document_no' => $quotation->document_no, 'by' => $by]);

            return $quotation->refresh();
        });
    }

    private function text(mixed $value, int $max): ?string
    {
        $v = $value === null ? '' : trim((string) $value);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
