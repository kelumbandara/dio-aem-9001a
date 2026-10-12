<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Core\Contracts\NumberingService;
use App\Modules\Finance\Contracts\DebitNotePoster;
use App\Modules\Finance\DTOs\DebitNotePosting;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\DTOs\StockLine;
use App\Modules\Inventory\DTOs\StockMovement;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\DTOs\ItemInfo;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\PurchaseReturn;
use App\Modules\Procurement\Models\SupplierBill;
use App\Modules\Procurement\Models\SupplierBillLine;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Goods sent back to the supplier. The stock leaves the order's warehouse (a stock issue with reason "return to
 * supplier", which Finance books against goods-received-not-invoiced). When the goods were already billed, name the
 * bill: a supplier debit note is posted for the same quantities at the bill's own prices and VAT, which reduces what
 * we owe, and the quantities become billable again. The debit note can never exceed the bill, line by line.
 */
final class PurchaseReturnService
{
    public function __construct(
        private readonly DocumentHeader $header,
        private readonly NumberingService $numbering,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
        private readonly StockPoster $stock,
        private readonly DebitNotePoster $finance,
        private readonly ItemCatalog $items,
        private readonly PurchaseOrderService $orders,
    ) {}

    /**
     * @param  array<string, mixed>  $data  reason, return_date?, bill? (the supplier's invoice number, to credit it too), lines [{line, qty}]
     *
     * @throws BusinessException
     */
    public function create(PurchaseOrder $order, array $data, ?int $by = null): PurchaseReturn
    {
        return DB::transaction(function () use ($order, $data, $by): PurchaseReturn {
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['approved', 'completed'], true)) {
                throw ProcurementException::state('order', $order->status, 'approved or completed');
            }
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') {
                throw ProcurementException::make('procurement.reason_required', 'Give a reason for the return.', 422, 'reason');
            }
            $date = $this->header->date($data, 'return_date', $this->header->today());

            $bill = null;
            if (isset($data['bill']) && trim((string) $data['bill']) !== '') {
                $bill = SupplierBill::query()->where('order_id', $order->id)->where('supplier_invoice_no', trim((string) $data['bill']))->first()
                    ?? throw ProcurementException::make('procurement.bill_not_found', 'That bill was not found on this order.', 422, 'bill');
            }

            $orderLines = $order->lines()->get()->keyBy('line_no');
            $items = $this->items->findMany($orderLines->pluck('item_id')->all());
            $billLines = $bill?->lines()->get()->keyBy('order_line_id') ?? collect();
            $picked = $this->pick($data['lines'] ?? null, $orderLines, $items, $billLines, $bill !== null);
            if ($picked === []) {
                throw ProcurementException::make('procurement.nothing_to_return', 'Choose at least one line and quantity to return.', 422, 'lines');
            }

            $this->numbering->define('procurement.return', 'PRN/{FY}/{SEQ}', 5, 'fiscal_yearly');
            $documentNo = $this->numbering->next('procurement.return', null, CarbonImmutable::parse($date));

            $return = new PurchaseReturn([
                'document_no' => $documentNo, 'order_id' => $order->id, 'supplier_id' => $order->supplier_id, 'bill_id' => $bill?->id, 'warehouse_id' => $order->warehouse_id,
                'return_date' => $date, 'reason' => mb_substr($reason, 0, 255), 'created_by' => $by,
            ]);
            $return->public_id = (string) Str::uuid();

            // 1. the goods leave
            $stockLines = [];
            $parts = [];
            foreach ($picked as $p) {
                /** @var PurchaseOrderLine $line */
                $line = $p['line'];
                $stocked = $p['stocked'];
                $unitCost = Decimal::round(Decimal::div($line->net_amount, $line->qty), 6);
                $grniValue = $stocked ? Decimal::round(Decimal::mul($p['qty'], $unitCost), 4) : '0.0000';
                if ($stocked) {
                    $stockLines[] = new StockLine(itemId: $line->item_id, qty: Decimal::trim($p['qty']), uomId: $line->uom_id, warehouseId: $order->warehouse_id);
                }
                $parts[] = ['p' => $p, 'grni_value' => $grniValue] + $this->debitParts($p, $bill !== null ? $p['bill_line'] : null);
            }

            $stockDocumentId = null;
            if ($stockLines !== []) {
                $posted = $this->stock->post(new StockMovement(
                    type: 'issue', postingDate: $date, reason: 'supplier_return', lines: $stockLines, reference: $documentNo,
                    notes: "Return {$documentNo} for {$order->document_no}", sourceType: 'procurement_return', sourceId: $return->public_id, createdBy: $by,
                ));
                $stockDocumentId = $posted->documentId;
            }

            // 2. the supplier is credited, when a bill is named
            $journalId = null;
            $net = $vat = '0';
            if ($bill !== null) {
                foreach ($parts as $x) {
                    $net = Decimal::add($net, $x['net']);
                    $vat = Decimal::add($vat, $x['vat']);
                }
                $postedNote = $this->finance->postDebitNote(new DebitNotePosting(
                    supplierId: $order->supplier_id, documentNo: $documentNo, documentDate: $date, lines: $this->journalLines($parts, $documentNo),
                    billNo: $bill->supplier_invoice_no, sourceType: 'purchase_return', sourceId: $return->public_id, reason: mb_substr($reason, 0, 255), createdBy: $by,
                ));
                $journalId = $postedNote->id;
            }

            $return->forceFill([
                'net_total' => Decimal::round($net, 2), 'vat_total' => Decimal::round($vat, 2), 'gross_total' => Decimal::round(Decimal::add($net, $vat), 2),
                'stock_document_id' => $stockDocumentId, 'journal_id' => $journalId,
            ])->save();

            foreach ($parts as $x) {
                /** @var PurchaseOrderLine $line */
                $line = $x['p']['line'];
                $return->lines()->create([
                    'order_line_id' => $line->id, 'bill_line_id' => $x['p']['bill_line']?->id, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'qty' => $x['p']['qty'],
                    'goods_returned' => $x['p']['stocked'], 'grni_value' => $x['grni_value'], 'net_amount' => $x['net'], 'grni_amount' => $x['grni'], 'variance_amount' => $x['variance'], 'vat_amount' => $x['vat'],
                ]);
                $update = [];
                if ($x['p']['stocked']) {
                    $update['qty_returned'] = Decimal::add($line->qty_returned, $x['p']['qty']);
                }
                if ($bill !== null) {
                    $update['qty_billed'] = Decimal::sub($line->qty_billed, $x['p']['qty']);
                }
                PurchaseOrderLine::query()->whereKey($line->id)->update($update);
            }

            if ($order->status === 'completed') {
                $order->forceFill(['status' => 'approved', 'version' => $order->version + 1])->save();
            }
            $order->unsetRelation('lines');
            $this->orders->refreshStatus($order);

            $this->audit->record('procurement.return_posted', $return, [], ['document_no' => $documentNo, 'order' => $order->document_no, 'bill' => $bill?->supplier_invoice_no, 'gross_total' => $return->gross_total]);
            $this->outbox->record('procurement.return.posted', ['return_id' => $return->id, 'document_no' => $documentNo, 'order_id' => $order->id, 'supplier_id' => $order->supplier_id, 'gross_total' => $return->gross_total], 'purchase_return', $return->id);

            return $return->refresh();
        });
    }

    /**
     * The debit note's share of a bill line: proportional to the quantity, and the exact remainder for the last quantity.
     *
     * @param  array{line: PurchaseOrderLine, qty: string, stocked: bool, bill_line: ?SupplierBillLine}  $p
     * @return array{net: string, grni: string, variance: string, vat: string}
     */
    private function debitParts(array $p, ?SupplierBillLine $bl): array
    {
        if ($bl === null) {
            return ['net' => '0.0000', 'grni' => '0.0000', 'variance' => '0.0000', 'vat' => '0.0000'];
        }
        $done = DB::table('purchase_return_lines')->where('bill_line_id', $bl->id)->selectRaw('coalesce(sum(qty),0) as qty, coalesce(sum(net_amount),0) as net, coalesce(sum(grni_amount),0) as grni, coalesce(sum(vat_amount),0) as vat')->first();
        $completes = Decimal::compare(Decimal::add((string) $done?->qty, $p['qty']), $bl->qty) === 0;
        $ratio = fn (string $amount, int $places): string => Decimal::round(Decimal::div(Decimal::mul($amount, $p['qty']), $bl->qty), $places);

        $net = $completes ? Decimal::sub($bl->net_amount, (string) $done?->net) : $ratio($bl->net_amount, 2);
        $grni = $completes ? Decimal::sub($bl->grni_amount, (string) $done?->grni) : $ratio($bl->grni_amount, 4);
        $vat = $completes ? Decimal::sub($bl->vat_amount, (string) $done?->vat) : $ratio($bl->vat_amount, 2);

        return ['net' => Decimal::round($net, 4), 'grni' => Decimal::round($grni, 4), 'variance' => $p['stocked'] ? Decimal::round(Decimal::sub($net, $grni), 4) : '0.0000', 'vat' => Decimal::round($vat, 4)];
    }

    /**
     * Credit side of the debit note: goods-received value, services, the price variance the bill carried, input VAT.
     *
     * @param  list<array<string, mixed>>  $parts
     * @return list<JournalLineData>
     */
    private function journalLines(array $parts, string $documentNo): array
    {
        $grni = $variance = $expense = $vat = '0';
        foreach ($parts as $x) {
            $grni = Decimal::add($grni, $x['grni']);
            $variance = Decimal::add($variance, $x['variance']);
            $vat = Decimal::add($vat, $x['vat']);
            if (! $x['p']['stocked']) {
                $expense = Decimal::add($expense, $x['net']);
            }
        }

        $lines = [];
        if (Decimal::compare($grni, '0', 4) > 0) {
            $lines[] = JournalLineData::credit(Decimal::round($grni, 4), 'grni', null, "Return {$documentNo}");
        }
        if (Decimal::compare($expense, '0', 4) > 0) {
            $lines[] = JournalLineData::credit(Decimal::round($expense, 4), 'purchase_expense', null, "Return {$documentNo}");
        }
        if (Decimal::compare($variance, '0', 4) > 0) {
            $lines[] = JournalLineData::credit(Decimal::round($variance, 4), 'purchase_variance', null, "Price variance {$documentNo}");
        } elseif (Decimal::compare($variance, '0', 4) < 0) {
            $lines[] = JournalLineData::debit(Decimal::round(ltrim($variance, '-'), 4), 'purchase_variance', null, "Price variance {$documentNo}");
        }
        if (Decimal::compare($vat, '0', 4) > 0) {
            $lines[] = JournalLineData::credit(Decimal::round($vat, 4), 'vat_input', null, "Input VAT {$documentNo}");
        }

        return $lines;
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $orderLines  keyed by line number
     * @param  array<int, ItemInfo>  $items
     * @param  Collection<int, SupplierBillLine>  $billLines  keyed by order line id
     * @return list<array{line: PurchaseOrderLine, qty: string, stocked: bool, bill_line: ?SupplierBillLine}>
     */
    private function pick(mixed $requested, $orderLines, array $items, $billLines, bool $withBill): array
    {
        $out = [];
        $seen = [];
        foreach ((array) $requested as $i => $row) {
            $field = "lines.{$i}";
            $no = (int) ($row['line'] ?? 0);
            $line = $orderLines->get($no);
            if ($line === null || isset($seen[$no])) {
                throw ProcurementException::make('procurement.line_invalid', 'Choose each order line once.', 422, "{$field}.line");
            }
            $seen[$no] = true;
            $stocked = ($items[$line->item_id] ?? null)?->isStocked() ?? false;
            $qty = trim((string) ($row['qty'] ?? ''));
            if (! Decimal::isDecimal($qty, 6) || Decimal::compare($qty, '0') <= 0) {
                throw ProcurementException::make('procurement.qty_invalid', 'Enter a quantity above zero.', 422, "{$field}.qty");
            }
            if (! $stocked && ! $withBill) {
                throw ProcurementException::make('procurement.line_not_returnable', 'A service line can only be credited against a bill.', 422, "{$field}.line");
            }
            if ($stocked) {
                $onHand = Decimal::sub($line->qty_received, $line->qty_returned);
                if (Decimal::compare($qty, $onHand) > 0) {
                    throw ProcurementException::make('procurement.qty_exceeds_received', 'Only '.Decimal::trim(Decimal::round($onHand, 6))." of line {$no} has been received and not returned.", 422, "{$field}.qty");
                }
            }
            $billLine = null;
            if ($withBill) {
                $billLine = $billLines->get($line->id) ?? throw ProcurementException::make('procurement.line_not_on_bill', "Line {$no} is not on that bill.", 422, "{$field}.line");
                $debited = (string) DB::table('purchase_return_lines')->where('bill_line_id', $billLine->id)->sum('qty');
                $left = Decimal::sub($billLine->qty, $debited);
                if (Decimal::compare($qty, $left) > 0) {
                    throw ProcurementException::make('procurement.qty_exceeds_bill', 'Only '.Decimal::trim(Decimal::round($left, 6))." of line {$no} can still be credited on that bill.", 422, "{$field}.qty");
                }
            }
            $out[] = ['line' => $line, 'qty' => Decimal::round($qty, 6), 'stocked' => $stocked, 'bill_line' => $billLine];
        }

        return $out;
    }
}
