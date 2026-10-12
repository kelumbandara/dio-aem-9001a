<?php

namespace App\Modules\Sales\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Core\Contracts\NumberingService;
use App\Modules\Finance\Contracts\OpenItemPoster;
use App\Modules\Finance\DTOs\InvoicePosting;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\DTOs\ItemInfo;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderLine;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tax invoices. An invoice bills what has been delivered (stock items) or ordered (services) and not yet invoiced.
 * It is posted to Finance in the same transaction: revenue, VAT and the levies (SSCL, NBT) against the customer's
 * receivable, which creates the open item the customer will pay. A posted invoice never changes; a credit note corrects it.
 */
final class InvoiceService
{
    private const TAX_ACCOUNT = ['vat' => 'vat_output', 'sscl' => 'sscl_payable', 'nbt' => 'nbt_payable'];

    public function __construct(
        private readonly DocumentHeader $header,
        private readonly SalesCalculator $calculator,
        private readonly NumberingService $numbering,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
        private readonly OpenItemPoster $finance,
        private readonly ItemCatalog $items,
        private readonly OrderService $orders,
    ) {}

    /**
     * @param  array<string, mixed>  $data  invoice_date?, due_date?, notes?, lines? [{line: line number, qty}] — everything billable when omitted
     *
     * @throws BusinessException
     */
    public function create(Order $order, array $data, ?int $by = null): Invoice
    {
        return DB::transaction(function () use ($order, $data, $by): Invoice {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'confirmed') {
                throw SalesException::state('order', $order->status, 'confirmed');
            }
            $date = $this->header->date($data, 'invoice_date', $this->header->today());
            $due = $this->header->date($data, 'due_date', CarbonImmutable::parse($date)->addDays($order->payment_terms_days)->toDateString());
            if ($due < $date) {
                throw SalesException::make('sales.due_date_invalid', 'The due date cannot be before the invoice date.', 422, 'due_date');
            }

            $orderLines = $order->lines()->get()->keyBy('line_no');
            $items = $this->items->findMany($orderLines->pluck('item_id')->all());
            $picked = $this->pick($data['lines'] ?? null, $orderLines, $items);
            if ($picked === []) {
                throw SalesException::make('sales.nothing_to_invoice', 'There is nothing delivered and not yet invoiced on this order.', 422, 'lines');
            }

            $inclusive = $order->prices_include_vat;
            $on = CarbonImmutable::parse($date, config('core.display_timezone'));
            $rows = [];
            foreach ($picked as $p) {
                /** @var OrderLine $line */
                $line = $p['line'];
                $completes = Decimal::compare(Decimal::add($line->qty_invoiced, $p['qty']), $line->qty) === 0;
                $part = $this->calculator->lineAmounts($p['qty'], $line->unit_price, $line->discount_pct, $line->tax_code_id, $inclusive, $on);
                $net = $part['net'];
                $gross = $part['gross'];
                if ($completes) {
                    // The invoice that completes a line takes the exact remainder, so slices always add up to the order.
                    $before = DB::table('sales_invoice_lines')->where('order_line_id', $line->id)->selectRaw('coalesce(sum(net_amount), 0) as net, coalesce(sum(net_amount + vat_amount), 0) as gross')->first();
                    $net = Decimal::sub($line->net_amount, (string) $before?->net);
                    if ($gross !== null) {
                        $total = $this->calculator->lineNet($line->qty, $line->unit_price, $line->discount_pct);
                        $gross = Decimal::sub($total, (string) $before?->gross);
                    }
                }
                $rows[] = ['line' => $line, 'qty' => $p['qty'], 'net' => Decimal::round($net, 2), 'gross' => $gross !== null ? Decimal::round($gross, 2) : null];
            }
            $computed = $this->calculator->compute(array_map(fn (array $r) => ['net' => $r['net'], 'tax_code_id' => $r['line']->tax_code_id, 'gross' => $r['gross']], $rows), $on);
            if (Decimal::compare($computed['gross'], '0', 2) <= 0) {
                throw SalesException::make('sales.invoice_zero', 'An invoice must be for more than zero.', 422);
            }

            $this->numbering->define('sales.invoice', 'INV/{FY}/{SEQ}', 5, 'fiscal_yearly');
            $documentNo = $this->numbering->next('sales.invoice', null, CarbonImmutable::parse($date));
            $publicId = (string) Str::uuid();

            $posted = $this->finance->postInvoice(new InvoicePosting(
                side: 'receivable', partyId: $order->customer_id, documentNo: $documentNo, documentDate: $date, dueDate: $due,
                lines: $this->journalLines($computed, $documentNo, $order->customer_id), sourceType: 'sales_invoice', sourceId: $publicId,
                description: "Sales invoice {$documentNo} ({$order->document_no})", createdBy: $by,
            ));

            $invoice = new Invoice([
                'document_no' => $documentNo, 'order_id' => $order->id, 'customer_id' => $order->customer_id, 'invoice_date' => $date, 'due_date' => $due,
                'customer_ref' => $order->customer_ref, 'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? mb_substr(trim((string) $data['notes']), 0, 2000) : null,
                'net_total' => $computed['net'], 'vat_total' => $computed['vat'], 'levy_total' => $computed['levy'], 'gross_total' => $computed['gross'],
                'journal_id' => $posted->id, 'created_by' => $by, 'prices_include_vat' => $inclusive,
            ]);
            $invoice->public_id = $publicId;
            $invoice->save();

            foreach ($rows as $i => $r) {
                $invoice->lines()->create([
                    'line_no' => $i + 1, 'order_line_id' => $r['line']->id, 'item_id' => $r['line']->item_id, 'description' => $r['line']->description, 'uom_id' => $r['line']->uom_id,
                    'qty' => $r['qty'], 'unit_price' => $r['line']->unit_price, 'discount_pct' => $r['line']->discount_pct, 'net_amount' => $r['net'],
                    'tax_code_id' => $r['line']->tax_code_id, 'vat_rate' => $computed['lines'][$i]['vat_rate'], 'vat_amount' => $computed['lines'][$i]['vat_amount'], 'levy_liable' => $computed['lines'][$i]['levy_liable'],
                ]);
                OrderLine::query()->whereKey($r['line']->id)->update(['qty_invoiced' => Decimal::add($r['line']->qty_invoiced, $r['qty'])]);
            }
            foreach ($computed['taxes'] as $t) {
                $invoice->taxes()->create($t);
            }

            $order->unsetRelation('lines');
            $this->orders->refreshStatus($order);

            $this->audit->record('sales.invoice_posted', $invoice, [], ['document_no' => $documentNo, 'order' => $order->document_no, 'gross_total' => $computed['gross'], 'journal_no' => $posted->journalNo]);
            $this->outbox->record('sales.invoice.posted', ['invoice_id' => $invoice->id, 'document_no' => $documentNo, 'customer_id' => $order->customer_id, 'gross_total' => $computed['gross']], 'sales_invoice', $invoice->id);

            return $invoice->refresh();
        });
    }

    /**
     * Credit side of the invoice journal: revenue, then each tax to its own account. Finance adds the receivable line.
     *
     * @param  array{net: string, taxes: list<array{tax_code_id: int, kind: string, rate: string, base_amount: string, amount: string}>}  $computed
     * @return list<JournalLineData>
     */
    private function journalLines(array $computed, string $documentNo, int $customerId): array
    {
        $lines = [JournalLineData::credit($computed['net'], 'sales_revenue', null, "Sales {$documentNo}")];
        $byKind = [];
        foreach ($computed['taxes'] as $t) {
            $byKind[$t['kind']] = Decimal::add($byKind[$t['kind']] ?? '0', $t['amount']);
        }
        foreach ($byKind as $kind => $amount) {
            if (Decimal::compare($amount, '0', 2) > 0) {
                $lines[] = JournalLineData::credit(Decimal::round($amount, 2), self::TAX_ACCOUNT[$kind], null, strtoupper($kind)." {$documentNo}");
            }
        }

        return array_values(array_filter($lines, fn (JournalLineData $l) => Decimal::compare($l->credit, '0', 2) > 0));
    }

    /**
     * @param  Collection<int, OrderLine>  $orderLines  keyed by line number
     * @param  array<int, ItemInfo>  $items
     * @return list<array{line: OrderLine, qty: string}>
     */
    private function pick(mixed $requested, $orderLines, array $items): array
    {
        $billable = function (OrderLine $line) use ($items): string {
            $stocked = ($items[$line->item_id] ?? null)?->isStocked() ?? false;

            return Decimal::sub($stocked ? $line->qty_delivered : $line->qty, $line->qty_invoiced);
        };

        $out = [];
        if ($requested === null || $requested === []) {
            foreach ($orderLines as $line) {
                $qty = $billable($line);
                if (Decimal::compare($qty, '0') > 0) {
                    $out[] = ['line' => $line, 'qty' => Decimal::round($qty, 6)];
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
                throw SalesException::make('sales.line_invalid', 'Choose each order line once.', 422, "{$field}.line");
            }
            $seen[$no] = true;
            $qty = trim((string) ($row['qty'] ?? ''));
            $max = $billable($line);
            if (! Decimal::isDecimal($qty, 6) || Decimal::compare($qty, '0') <= 0) {
                throw SalesException::make('sales.qty_invalid', 'Enter a quantity above zero.', 422, "{$field}.qty");
            }
            if (Decimal::compare($qty, $max) > 0) {
                throw SalesException::make('sales.qty_exceeds_billable', 'Only '.Decimal::trim(Decimal::round($max, 6))." of line {$no} can be invoiced now (delivered and not yet invoiced).", 422, "{$field}.qty");
            }
            $out[] = ['line' => $line, 'qty' => Decimal::round($qty, 6)];
        }

        return $out;
    }
}
