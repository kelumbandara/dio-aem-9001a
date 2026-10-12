<?php

namespace App\Modules\Sales\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Core\Contracts\NumberingService;
use App\Modules\Finance\Contracts\CreditNotePoster;
use App\Modules\Finance\DTOs\CreditNotePosting;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\Inventory\Contracts\StockAvailability;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\Inventory\DTOs\StockLine;
use App\Modules\Inventory\DTOs\StockMovement;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\Contracts\UomConverter;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Models\CreditNote;
use App\Modules\Sales\Models\CreditNoteTax;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\InvoiceLine;
use App\Modules\Sales\Models\Order;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Credit notes correct a posted invoice (price error, goods returned, goodwill). They give back the revenue, VAT and
 * levies at the rates the invoice was issued with, reduce what the customer owes and, when goods come back, can put
 * the stock back into a warehouse. The sum of all credit notes can never exceed the invoice, line by line and tax by tax.
 */
final class SalesCreditNoteService
{
    private const TAX_ACCOUNT = ['vat' => 'vat_output', 'sscl' => 'sscl_payable', 'nbt' => 'nbt_payable'];

    public function __construct(
        private readonly DocumentHeader $header,
        private readonly NumberingService $numbering,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
        private readonly CreditNotePoster $finance,
        private readonly StockPoster $stock,
        private readonly StockAvailability $availability,
        private readonly WarehouseDirectory $warehouses,
        private readonly ItemCatalog $items,
        private readonly UomConverter $uoms,
    ) {}

    /**
     * @param  array<string, mixed>  $data  reason, note_date?, restock?, warehouse|warehouse_id? (defaults to the order's), lines? [{line: invoice line number, qty}] — everything still creditable when omitted
     *
     * @throws BusinessException
     */
    public function create(Invoice $invoice, array $data, ?int $by = null): CreditNote
    {
        return DB::transaction(function () use ($invoice, $data, $by): CreditNote {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') {
                throw SalesException::make('sales.reason_required', 'Give a reason for the credit note.', 422, 'reason');
            }
            $date = $this->header->date($data, 'note_date', $this->header->today());
            if ($date < $invoice->invoice_date->toDateString()) {
                throw SalesException::make('sales.note_date_invalid', 'A credit note cannot be dated before its invoice.', 422, 'note_date');
            }

            $lines = $invoice->lines()->get()->keyBy('line_no');
            $credited = DB::table('sales_credit_note_lines')->whereIn('invoice_line_id', $lines->pluck('id'))
                ->selectRaw('invoice_line_id, sum(qty) as qty, sum(net_amount) as net, sum(vat_amount) as vat')->groupBy('invoice_line_id')->get()->keyBy('invoice_line_id');
            $picked = $this->pick($data['lines'] ?? null, $lines, $credited);
            if ($picked === []) {
                throw SalesException::make('sales.nothing_to_credit', 'Everything on this invoice has already been credited.', 422, 'lines');
            }

            $rows = [];
            foreach ($picked as $p) {
                /** @var InvoiceLine $line */
                $line = $p['line'];
                $done = $credited->get($line->id);
                $creditedQty = (string) ($done->qty ?? '0');
                $remainingQty = Decimal::sub($line->qty, $creditedQty);
                $completes = Decimal::compare($p['qty'], $remainingQty) === 0;
                $net = $completes
                    ? Decimal::sub($line->net_amount, (string) ($done->net ?? '0'))
                    : Decimal::round(Decimal::div(Decimal::mul($line->net_amount, $p['qty']), $line->qty), 2);
                $vat = $completes
                    ? Decimal::sub($line->vat_amount, (string) ($done->vat ?? '0'))
                    : Decimal::round(Decimal::div(Decimal::mul($net, $line->vat_rate), '100'), 2);
                $rows[] = ['line' => $line, 'qty' => $p['qty'], 'net' => Decimal::round($net, 2), 'vat' => Decimal::round($vat, 2)];
            }

            $taxes = $this->taxRows($invoice, $rows, $lines, $credited);
            $net = array_reduce($rows, fn (string $c, array $r) => Decimal::add($c, $r['net']), '0');
            $vat = array_reduce($rows, fn (string $c, array $r) => Decimal::add($c, $r['vat']), '0');
            $levy = array_reduce($taxes, fn (string $c, array $t) => $t['kind'] === 'vat' ? $c : Decimal::add($c, $t['amount']), '0');
            $gross = Decimal::round(Decimal::add(Decimal::add($net, $vat), $levy), 2);
            if (Decimal::compare($gross, '0', 2) <= 0) {
                throw SalesException::make('sales.credit_zero', 'A credit note must be for more than zero.', 422);
            }

            $this->numbering->define('sales.credit_note', 'CN/{FY}/{SEQ}', 5, 'fiscal_yearly');
            $documentNo = $this->numbering->next('sales.credit_note', null, CarbonImmutable::parse($date));
            $publicId = (string) Str::uuid();

            $stockDocumentId = null;
            $warehouseId = null;
            $restock = filter_var($data['restock'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($restock) {
                [$warehouseId, $stockDocumentId] = $this->restock($invoice, $rows, $data, $date, $documentNo, $publicId, $by);
            }

            $posted = $this->finance->postCreditNote(new CreditNotePosting(
                customerId: $invoice->customer_id, documentNo: $documentNo, documentDate: $date, lines: $this->journalLines($net, $taxes, $documentNo),
                invoiceNo: $invoice->document_no, sourceType: 'sales_credit_note', sourceId: $publicId, reason: mb_substr($reason, 0, 255), createdBy: $by,
            ));

            $note = new CreditNote([
                'document_no' => $documentNo, 'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'note_date' => $date, 'reason' => mb_substr($reason, 0, 255),
                'restock' => $restock, 'warehouse_id' => $warehouseId, 'net_total' => Decimal::round($net, 2), 'vat_total' => Decimal::round($vat, 2), 'levy_total' => Decimal::round($levy, 2),
                'gross_total' => $gross, 'journal_id' => $posted->id, 'stock_document_id' => $stockDocumentId, 'created_by' => $by,
            ]);
            $note->public_id = $publicId;
            $note->save();
            foreach ($rows as $r) {
                $note->lines()->create(['invoice_line_id' => $r['line']->id, 'item_id' => $r['line']->item_id, 'uom_id' => $r['line']->uom_id, 'qty' => $r['qty'], 'net_amount' => $r['net'], 'vat_amount' => $r['vat']]);
            }
            foreach ($taxes as $t) {
                CreditNoteTax::query()->create($t + ['credit_note_id' => $note->id]);
            }

            $this->audit->record('sales.credit_note_posted', $note, [], ['document_no' => $documentNo, 'invoice' => $invoice->document_no, 'gross_total' => $gross, 'restock' => $restock, 'journal_no' => $posted->journalNo]);
            $this->outbox->record('sales.credit_note.posted', ['credit_note_id' => $note->id, 'document_no' => $documentNo, 'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'gross_total' => $gross], 'sales_credit_note', $note->id);

            return $note->refresh();
        });
    }

    /**
     * VAT per code from the lines; each levy at the invoice's own rate on the credited liable turnover, never more in
     * total than the invoice charged, and exactly the remainder when the credit notes finish the invoice.
     *
     * @param  list<array{line: InvoiceLine, qty: string, net: string, vat: string}>  $rows
     * @param  Collection<int, InvoiceLine>  $lines
     * @param  Collection<int|string, covariant \stdClass>  $credited
     * @return list<array{tax_code_id: int, kind: string, rate: string, base_amount: string, amount: string}>
     */
    private function taxRows(Invoice $invoice, array $rows, $lines, $credited): array
    {
        $out = [];
        $vatRows = [];
        $liable = '0';
        foreach ($rows as $r) {
            if ($r['line']->tax_code_id !== null && Decimal::compare($r['line']->vat_rate, '0', 4) > 0) {
                $k = $r['line']->tax_code_id;
                $vatRows[$k] ??= ['tax_code_id' => $k, 'kind' => 'vat', 'rate' => $r['line']->vat_rate, 'base_amount' => '0', 'amount' => '0'];
                $vatRows[$k]['base_amount'] = Decimal::add($vatRows[$k]['base_amount'], $r['net']);
                $vatRows[$k]['amount'] = Decimal::add($vatRows[$k]['amount'], $r['vat']);
            }
            if ($r['line']->levy_liable) {
                $liable = Decimal::add($liable, $r['net']);
            }
        }
        foreach ($vatRows as $v) {
            $out[] = ['tax_code_id' => $v['tax_code_id'], 'kind' => 'vat', 'rate' => $v['rate'], 'base_amount' => Decimal::round($v['base_amount'], 2), 'amount' => Decimal::round($v['amount'], 2)];
        }

        $finishes = $lines->every(function (InvoiceLine $l) use ($rows, $credited) {
            $now = collect($rows)->first(fn ($r) => $r['line']->id === $l->id)['qty'] ?? '0';

            return Decimal::compare(Decimal::add((string) ($credited->get($l->id)->qty ?? '0'), $now), $l->qty) === 0;
        });
        foreach ($invoice->taxes()->get() as $tax) {
            if ($tax->kind === 'vat') {
                continue;
            }
            $already = (string) CreditNoteTax::query()->where('tax_code_id', $tax->tax_code_id)
                ->whereIn('credit_note_id', CreditNote::query()->where('invoice_id', $invoice->id)->select('id'))->sum('amount');
            $cap = Decimal::sub($tax->amount, $already);
            $amount = $finishes ? $cap : Decimal::round(Decimal::div(Decimal::mul($liable, $tax->rate), '100'), 2);
            if (Decimal::compare($amount, $cap, 2) > 0) {
                $amount = $cap;
            }
            if (Decimal::compare($amount, '0', 2) > 0 || Decimal::compare($liable, '0', 2) > 0) {
                $out[] = ['tax_code_id' => $tax->tax_code_id, 'kind' => $tax->kind, 'rate' => $tax->rate, 'base_amount' => Decimal::round($liable, 2), 'amount' => Decimal::round($amount, 2)];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{tax_code_id: int, kind: string, rate: string, base_amount: string, amount: string}>  $taxes
     * @return list<JournalLineData>
     */
    private function journalLines(string $net, array $taxes, string $documentNo): array
    {
        $lines = [JournalLineData::debit(Decimal::round($net, 2), 'sales_revenue', null, "Credit {$documentNo}")];
        $byKind = [];
        foreach ($taxes as $t) {
            $byKind[$t['kind']] = Decimal::add($byKind[$t['kind']] ?? '0', $t['amount']);
        }
        foreach ($byKind as $kind => $amount) {
            $lines[] = JournalLineData::debit(Decimal::round($amount, 2), self::TAX_ACCOUNT[$kind], null, strtoupper($kind)." credit {$documentNo}");
        }

        return array_values(array_filter($lines, fn (JournalLineData $l) => Decimal::compare($l->debit, '0', 2) > 0));
    }

    /**
     * Put returned stock back at today's average cost (the cost it left at is not tracked per sale).
     *
     * @param  list<array{line: InvoiceLine, qty: string, net: string, vat: string}>  $rows
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: int}
     */
    private function restock(Invoice $invoice, array $rows, array $data, string $date, string $documentNo, string $publicId, ?int $by): array
    {
        $warehouseId = isset($data['warehouse_id']) && $data['warehouse_id'] !== '' ? (int) $data['warehouse_id']
            : (isset($data['warehouse']) ? $this->warehouses->idByCode((string) $data['warehouse']) : Order::query()->whereKey($invoice->order_id)->value('warehouse_id'));
        if ($warehouseId === null || ! $this->warehouses->isActive((int) $warehouseId)) {
            throw SalesException::make('sales.warehouse_required', 'Choose the warehouse the goods are returned to.', 422, 'warehouse');
        }

        $items = $this->items->findMany(array_map(fn (array $r) => $r['line']->item_id, $rows));
        $stockLines = [];
        foreach ($rows as $r) {
            $item = $items[$r['line']->item_id] ?? null;
            if ($item === null || ! $item->isStocked()) {
                continue;
            }
            if ($item->tracksLots) {
                throw SalesException::make('sales.restock_lot_item', "{$item->code} is tracked by lot; receive the returned goods in Inventory with their lot number instead.", 422, 'restock');
            }
            $perUnit = Decimal::round(Decimal::mul($this->availability->averageCost($item->id), $this->uoms->factor($item->id, $r['line']->uom_id)), 6);
            $stockLines[] = new StockLine(itemId: $item->id, qty: Decimal::trim($r['qty']), uomId: $r['line']->uom_id, warehouseId: (int) $warehouseId, unitCost: $perUnit);
        }
        if ($stockLines === []) {
            throw SalesException::make('sales.nothing_to_restock', 'None of these lines are stock items; untick "return goods to stock".', 422, 'restock');
        }

        $posted = $this->stock->post(new StockMovement(
            type: 'receipt', postingDate: $date, reason: 'return', lines: $stockLines, reference: $documentNo,
            notes: "Returned against {$invoice->document_no}", sourceType: 'sales_credit_note', sourceId: $publicId, createdBy: $by,
        ));

        return [(int) $warehouseId, $posted->documentId];
    }

    /**
     * @param  Collection<int, InvoiceLine>  $lines  keyed by line number
     * @param  Collection<int|string, covariant \stdClass>  $credited  keyed by invoice line id
     * @return list<array{line: InvoiceLine, qty: string}>
     */
    private function pick(mixed $requested, $lines, $credited): array
    {
        $left = fn (InvoiceLine $l): string => Decimal::sub($l->qty, (string) ($credited->get($l->id)->qty ?? '0'));

        $out = [];
        if ($requested === null || $requested === []) {
            foreach ($lines as $line) {
                if (Decimal::compare($left($line), '0') > 0) {
                    $out[] = ['line' => $line, 'qty' => Decimal::round($left($line), 6)];
                }
            }

            return $out;
        }

        $seen = [];
        foreach ((array) $requested as $i => $row) {
            $field = "lines.{$i}";
            $no = (int) ($row['line'] ?? 0);
            $line = $lines->get($no);
            if ($line === null || isset($seen[$no])) {
                throw SalesException::make('sales.line_invalid', 'Choose each invoice line once.', 422, "{$field}.line");
            }
            $seen[$no] = true;
            $qty = trim((string) ($row['qty'] ?? ''));
            if (! Decimal::isDecimal($qty, 6) || Decimal::compare($qty, '0') <= 0) {
                throw SalesException::make('sales.qty_invalid', 'Enter a quantity above zero.', 422, "{$field}.qty");
            }
            if (Decimal::compare($qty, $left($line)) > 0) {
                throw SalesException::make('sales.qty_exceeds_invoice', 'Only '.Decimal::trim(Decimal::round($left($line), 6))." of line {$no} can still be credited.", 422, "{$field}.qty");
            }
            $out[] = ['line' => $line, 'qty' => Decimal::round($qty, 6)];
        }

        return $out;
    }
}
