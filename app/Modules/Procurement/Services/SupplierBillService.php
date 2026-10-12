<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Core\Contracts\NumberingService;
use App\Modules\Finance\Contracts\OpenItemPoster;
use App\Modules\Finance\DTOs\InvoicePosting;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\DTOs\ItemInfo;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\SupplierBill;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Supplier bills, matched against the order and what was received (three-way match):
 *  - a bill may not be for more than has been received and not yet billed (services: not more than ordered);
 *  - the price may differ from the order's by up to the tolerance; beyond that someone with the override permission
 *    must accept it with a reason;
 *  - the goods-received-not-invoiced account is cleared at the order's cost, the price difference goes to the purchase
 *    price variance account, and input VAT is claimed per line.
 * Posted to Finance in the same transaction: the supplier's invoice number becomes the payable's document number.
 */
final class SupplierBillService
{
    public function __construct(
        private readonly DocumentHeader $header,
        private readonly PurchaseCalculator $calculator,
        private readonly GrniQueries $grni,
        private readonly NumberingService $numbering,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
        private readonly OpenItemPoster $finance,
        private readonly ItemCatalog $items,
        private readonly PurchaseOrderService $orders,
    ) {}

    /**
     * @param  array<string, mixed>  $data  supplier_invoice_no, bill_date?, due_date?, notes?, override_reason?, lines? [{line, qty, unit_price?}] — everything billable at the order's prices when omitted
     *
     * @throws BusinessException
     */
    public function create(PurchaseOrder $order, array $data, ?int $by = null, bool $canOverride = false): SupplierBill
    {
        return DB::transaction(function () use ($order, $data, $by, $canOverride): SupplierBill {
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'approved') {
                throw ProcurementException::state('order', $order->status, 'approved');
            }
            $invoiceNo = trim((string) ($data['supplier_invoice_no'] ?? ''));
            if ($invoiceNo === '' || mb_strlen($invoiceNo) > 60) {
                throw ProcurementException::make('procurement.invoice_no_required', "Enter the supplier's invoice number (up to 60 characters).", 422, 'supplier_invoice_no');
            }
            if (SupplierBill::query()->where('supplier_id', $order->supplier_id)->where('supplier_invoice_no', $invoiceNo)->exists()) {
                throw ProcurementException::make('procurement.bill_exists', "A bill with the supplier's invoice number {$invoiceNo} has already been entered.", 422, 'supplier_invoice_no');
            }
            $date = $this->header->date($data, 'bill_date', $this->header->today());
            $due = $this->header->date($data, 'due_date', CarbonImmutable::parse($date)->addDays($order->payment_terms_days)->toDateString());
            if ($due < $date) {
                throw ProcurementException::make('procurement.due_date_invalid', 'The due date cannot be before the bill date.', 422, 'due_date');
            }
            $on = CarbonImmutable::parse($date, config('core.display_timezone'));

            $orderLines = $order->lines()->get()->keyBy('line_no');
            $items = $this->items->findMany($orderLines->pluck('item_id')->all());
            $picked = $this->pick($data['lines'] ?? null, $orderLines, $items);
            if ($picked === []) {
                throw ProcurementException::make('procurement.nothing_to_bill', 'There is nothing received and not yet billed on this order.', 422, 'lines');
            }

            $rows = [];
            $violations = [];
            foreach ($picked as $p) {
                /** @var PurchaseOrderLine $line */
                $line = $p['line'];
                $stocked = $p['stocked'];
                $orderPrice = Decimal::round(Decimal::div($line->net_amount, $line->qty), 6);
                $price = $p['price'] ?? $orderPrice;
                $billedBefore = $line->qty_billed;

                if ($p['price'] === null && Decimal::compare(Decimal::add($billedBefore, $p['qty']), $line->qty) === 0) {
                    $net = Decimal::sub($line->net_amount, $this->grni->netBilled($line->id));   // the bill that completes a line takes the exact remainder
                } else {
                    $net = Decimal::mul($p['qty'], $price);
                }
                $net = Decimal::round($net, 2);

                if ($this->outsideTolerance($price, $orderPrice)) {
                    $violations[] = "line {$line->line_no}: {$price} against the order's {$orderPrice}";
                }

                $grni = '0.0000';
                if ($stocked) {
                    $available = Decimal::sub(Decimal::sub($line->qty_received, $line->qty_returned), $billedBefore);
                    $left = $this->grni->remaining($line->id);
                    $grni = Decimal::compare($p['qty'], $available) === 0
                        ? $left
                        : Decimal::round(Decimal::mul($p['qty'], $orderPrice), 4);
                    if (Decimal::compare($grni, $left, 4) > 0) {
                        $grni = $left;
                    }
                }
                $variance = $stocked ? Decimal::round(Decimal::sub($net, $grni), 4) : '0.0000';
                $vat = $this->calculator->vat($line->tax_code_id, $net, $on);

                $rows[] = ['line' => $line, 'qty' => $p['qty'], 'price' => $price, 'net' => $net, 'grni' => $grni, 'variance' => $variance, 'vat' => $vat, 'stocked' => $stocked];
            }

            $override = null;
            if ($violations !== []) {
                $reason = trim((string) ($data['override_reason'] ?? ''));
                if (! $canOverride || $reason === '') {
                    throw ProcurementException::make(
                        'procurement.price_variance_exceeded',
                        'The price is more than '.config('procurement.price_tolerance_pct').' % away from the order ('.implode('; ', $violations).').'
                        .($canOverride ? ' Give a reason to accept it.' : ' Ask someone allowed to accept price differences.'),
                        422,
                        'price',
                    );
                }
                $override = mb_substr($reason, 0, 255);
            }

            $net = array_reduce($rows, fn (string $c, array $r) => Decimal::add($c, $r['net']), '0');
            $vat = array_reduce($rows, fn (string $c, array $r) => Decimal::add($c, $r['vat']['amount']), '0');
            $variance = array_reduce($rows, fn (string $c, array $r) => Decimal::add($c, $r['variance']), '0');
            $net = Decimal::round($net, 2);
            $vat = Decimal::round($vat, 2);
            $gross = Decimal::round(Decimal::add($net, $vat), 2);
            if (Decimal::compare($gross, '0', 2) <= 0) {
                throw ProcurementException::make('procurement.bill_zero', 'A bill must be for more than zero.', 422);
            }

            $this->numbering->define('procurement.bill', 'BILL/{FY}/{SEQ}', 5, 'fiscal_yearly');
            $documentNo = $this->numbering->next('procurement.bill', null, CarbonImmutable::parse($date));
            $publicId = (string) Str::uuid();

            $posted = $this->finance->postInvoice(new InvoicePosting(
                side: 'payable', partyId: $order->supplier_id, documentNo: $invoiceNo, documentDate: $date, dueDate: $due,
                lines: $this->journalLines($rows, $vat, $invoiceNo), sourceType: 'purchase_bill', sourceId: $publicId,
                description: "Supplier bill {$invoiceNo} ({$order->document_no})", createdBy: $by,
            ));

            $bill = new SupplierBill([
                'document_no' => $documentNo, 'supplier_invoice_no' => $invoiceNo, 'order_id' => $order->id, 'supplier_id' => $order->supplier_id, 'bill_date' => $date, 'due_date' => $due,
                'notes' => $this->text($data['notes'] ?? null, 2000), 'net_total' => $net, 'vat_total' => $vat, 'gross_total' => $gross, 'variance_total' => Decimal::round($variance, 4),
                'override_reason' => $override, 'override_by' => $override !== null ? $by : null, 'journal_id' => $posted->id, 'created_by' => $by,
            ]);
            $bill->public_id = $publicId;
            $bill->save();

            foreach ($rows as $i => $r) {
                $bill->lines()->create([
                    'line_no' => $i + 1, 'order_line_id' => $r['line']->id, 'item_id' => $r['line']->item_id, 'description' => $r['line']->description, 'uom_id' => $r['line']->uom_id,
                    'qty' => $r['qty'], 'unit_price' => $r['price'], 'net_amount' => $r['net'], 'grni_amount' => $r['grni'], 'variance_amount' => $r['variance'],
                    'tax_code_id' => $r['line']->tax_code_id, 'vat_rate' => $r['vat']['rate'], 'vat_amount' => $r['vat']['amount'], 'stocked' => $r['stocked'],
                ]);
                PurchaseOrderLine::query()->whereKey($r['line']->id)->update(['qty_billed' => Decimal::add($r['line']->qty_billed, $r['qty'])]);
            }

            $order->unsetRelation('lines');
            $this->orders->refreshStatus($order);

            $this->audit->record('procurement.bill_posted', $bill, [], [
                'document_no' => $documentNo, 'supplier_invoice_no' => $invoiceNo, 'order' => $order->document_no, 'gross_total' => $gross, 'variance_total' => $bill->variance_total, 'price_override' => $override, 'journal_no' => $posted->journalNo,
            ]);
            $this->outbox->record('procurement.bill.posted', ['bill_id' => $bill->id, 'document_no' => $documentNo, 'supplier_id' => $order->supplier_id, 'gross_total' => $gross], 'supplier_bill', $bill->id);

            return $bill->refresh();
        });
    }

    private function outsideTolerance(string $price, string $orderPrice): bool
    {
        if (Decimal::compare($price, $orderPrice, 6) === 0) {
            return false;
        }
        if (Decimal::compare($orderPrice, '0', 6) === 0) {
            return true;
        }
        $diff = Decimal::sub($price, $orderPrice);
        $diff = str_starts_with($diff, '-') ? substr($diff, 1) : $diff;
        $pct = Decimal::mul(Decimal::div($diff, $orderPrice), '100');

        return Decimal::compare($pct, (string) config('procurement.price_tolerance_pct'), 6) > 0;
    }

    /**
     * Debit side: goods received cleared, price difference (debit when dearer, credit when cheaper), services, input VAT.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<JournalLineData>
     */
    private function journalLines(array $rows, string $vat, string $invoiceNo): array
    {
        $grni = '0';
        $variance = '0';
        $expense = '0';
        foreach ($rows as $r) {
            $grni = Decimal::add($grni, $r['grni']);
            $variance = Decimal::add($variance, $r['variance']);
            if (! $r['stocked']) {
                $expense = Decimal::add($expense, $r['net']);
            }
        }

        $lines = [];
        if (Decimal::compare($grni, '0', 4) > 0) {
            $lines[] = JournalLineData::debit(Decimal::round($grni, 4), 'grni', null, "Goods received {$invoiceNo}");
        }
        if (Decimal::compare($expense, '0', 4) > 0) {
            $lines[] = JournalLineData::debit(Decimal::round($expense, 4), 'purchase_expense', null, "Purchases {$invoiceNo}");
        }
        if (Decimal::compare($variance, '0', 4) > 0) {
            $lines[] = JournalLineData::debit(Decimal::round($variance, 4), 'purchase_variance', null, "Price variance {$invoiceNo}");
        } elseif (Decimal::compare($variance, '0', 4) < 0) {
            $lines[] = JournalLineData::credit(Decimal::round(ltrim($variance, '-'), 4), 'purchase_variance', null, "Price variance {$invoiceNo}");
        }
        if (Decimal::compare($vat, '0', 2) > 0) {
            $lines[] = JournalLineData::debit(Decimal::round($vat, 4), 'vat_input', null, "Input VAT {$invoiceNo}");
        }

        return $lines;
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $orderLines  keyed by line number
     * @param  array<int, ItemInfo>  $items
     * @return list<array{line: PurchaseOrderLine, qty: string, price: ?string, stocked: bool}>
     */
    private function pick(mixed $requested, $orderLines, array $items): array
    {
        $stocked = fn (PurchaseOrderLine $l): bool => ($items[$l->item_id] ?? null)?->isStocked() ?? false;
        $billable = function (PurchaseOrderLine $l) use ($stocked): string {
            $left = $stocked($l) ? Decimal::sub(Decimal::sub($l->qty_received, $l->qty_returned), $l->qty_billed) : Decimal::sub($l->qty, $l->qty_billed);

            return Decimal::compare($left, '0') < 0 ? '0' : $left;
        };

        $out = [];
        if ($requested === null || $requested === []) {
            foreach ($orderLines as $line) {
                $qty = $billable($line);
                if (Decimal::compare($qty, '0') > 0) {
                    $out[] = ['line' => $line, 'qty' => Decimal::round($qty, 6), 'price' => null, 'stocked' => $stocked($line)];
                }
            }

            return $out;
        }

        $seen = [];
        foreach ((array) $requested as $i => $row) {
            $field = "lines.{$i}";
            $no = (int) ($row['line'] ?? 0);
            $line = $orderLines->get($no);
            if ($line === null || isset($seen[$no])) {
                throw ProcurementException::make('procurement.line_invalid', 'Choose each order line once.', 422, "{$field}.line");
            }
            $seen[$no] = true;
            $qty = trim((string) ($row['qty'] ?? ''));
            $max = $billable($line);
            if (! Decimal::isDecimal($qty, 6) || Decimal::compare($qty, '0') <= 0) {
                throw ProcurementException::make('procurement.qty_invalid', 'Enter a quantity above zero.', 422, "{$field}.qty");
            }
            if (Decimal::compare($qty, $max) > 0) {
                throw ProcurementException::make('procurement.qty_exceeds_billable', 'Only '.Decimal::trim(Decimal::round($max, 6))." of line {$no} can be billed now (received and not yet billed).", 422, "{$field}.qty");
            }
            $price = isset($row['unit_price']) && trim((string) $row['unit_price']) !== '' ? trim((string) $row['unit_price']) : null;
            if ($price !== null && (! Decimal::isDecimal($price, 6) || Decimal::compare($price, '0') < 0)) {
                throw ProcurementException::make('procurement.price_invalid', 'A price is zero or more, with at most 6 decimal places.', 422, "{$field}.unit_price");
            }
            $out[] = ['line' => $line, 'qty' => Decimal::round($qty, 6), 'price' => $price !== null ? Decimal::round($price, 6) : null, 'stocked' => $stocked($line)];
        }

        return $out;
    }

    private function text(mixed $value, int $max): ?string
    {
        $v = $value === null ? '' : trim((string) $value);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
