<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Core\Contracts\NumberingService;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\DTOs\StockLine;
use App\Modules\Inventory\DTOs\StockMovement;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\DTOs\ItemInfo;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Goods receipts: stock arrives against an approved order. Each line is received into the order's warehouse as a stock
 * receipt (reason "purchase") valued at the order's net price per unit; Finance books it as stock against "goods received,
 * not yet invoiced" until the supplier's bill arrives. Receiving more than is still open on the order is refused.
 */
final class GoodsReceiptService
{
    public function __construct(
        private readonly DocumentHeader $header,
        private readonly NumberingService $numbering,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
        private readonly StockPoster $stock,
        private readonly ItemCatalog $items,
        private readonly PurchaseOrderService $orders,
    ) {}

    /**
     * @param  array<string, mixed>  $data  receipt_date?, supplier_dn_ref?, notes?, lines? [{line, qty, lot?, expiry_date?}] — all open stock lines when omitted
     *
     * @throws BusinessException
     */
    public function post(PurchaseOrder $order, array $data, ?int $by = null): GoodsReceipt
    {
        return DB::transaction(function () use ($order, $data, $by): GoodsReceipt {
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'approved') {
                throw ProcurementException::state('order', $order->status, 'approved');
            }
            $date = $this->header->date($data, 'receipt_date', $this->header->today());
            $orderLines = $order->lines()->get()->keyBy('line_no');
            $items = $this->items->findMany($orderLines->pluck('item_id')->all());

            $picked = $this->pick($data['lines'] ?? null, $orderLines, $items);
            if ($picked === []) {
                throw ProcurementException::make('procurement.nothing_to_receive', 'There is nothing left to receive on this order.', 422, 'lines');
            }

            $this->numbering->define('procurement.receipt', 'GRN/{FY}/{SEQ}', 5, 'fiscal_yearly');
            $receipt = GoodsReceipt::query()->create([
                'document_no' => $this->numbering->next('procurement.receipt', null, CarbonImmutable::parse($date)), 'order_id' => $order->id, 'supplier_id' => $order->supplier_id,
                'warehouse_id' => $order->warehouse_id, 'receipt_date' => $date, 'status' => 'posted',
                'supplier_dn_ref' => $this->text($data['supplier_dn_ref'] ?? null, 100), 'notes' => $this->text($data['notes'] ?? null, 2000), 'created_by' => $by,
            ]);

            $stockLines = [];
            foreach ($picked as $p) {
                /** @var PurchaseOrderLine $line */
                $line = $p['line'];
                $unitCost = Decimal::round(Decimal::div($line->net_amount, $line->qty), 6);
                $value = Decimal::round(Decimal::mul($p['qty'], $unitCost), 4);
                $receipt->lines()->create([
                    'order_line_id' => $line->id, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'qty' => $p['qty'], 'unit_cost' => $unitCost, 'value' => $value,
                    'lot_no' => $p['lot'], 'expiry_date' => $p['expiry'],
                ]);
                $stockLines[] = new StockLine(
                    itemId: $line->item_id, qty: Decimal::trim($p['qty']), uomId: $line->uom_id, warehouseId: $order->warehouse_id,
                    lotNo: $p['lot'], expiryDate: $p['expiry'], unitCost: $unitCost,
                );
            }

            $posted = $this->stock->post(new StockMovement(
                type: 'receipt', postingDate: $date, reason: 'purchase', lines: $stockLines, reference: $receipt->document_no,
                notes: "Receipt {$receipt->document_no} for {$order->document_no}", sourceType: 'procurement_receipt', sourceId: $receipt->public_id, createdBy: $by,
            ));
            $receipt->forceFill(['stock_document_id' => $posted->documentId])->save();

            foreach ($picked as $p) {
                PurchaseOrderLine::query()->whereKey($p['line']->id)->update(['qty_received' => Decimal::add($p['line']->qty_received, $p['qty'])]);
            }

            $order->unsetRelation('lines');
            $this->orders->refreshStatus($order);   // a replacement delivery can complete an order that was already billed

            $this->audit->record('procurement.receipt_posted', $receipt, [], ['document_no' => $receipt->document_no, 'order' => $order->document_no, 'stock_document' => $posted->documentNo, 'lines' => count($picked)]);
            $this->outbox->record('procurement.receipt.posted', ['receipt_id' => $receipt->id, 'document_no' => $receipt->document_no, 'order_id' => $order->id, 'supplier_id' => $order->supplier_id], 'goods_receipt', $receipt->id);

            return $receipt->refresh();
        });
    }

    /** Undo a receipt that has not been billed or returned (the stock leaves again at exactly the value it came in at). */
    public function reverse(GoodsReceipt $receipt, ?string $note = null, ?int $by = null): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $note, $by): GoodsReceipt {
            $order = PurchaseOrder::query()->whereKey($receipt->order_id)->lockForUpdate()->firstOrFail();
            $receipt = GoodsReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->status !== 'posted') {
                throw ProcurementException::make('procurement.already_reversed', 'This receipt has already been reversed.', 409);
            }
            if ($order->status === 'cancelled') {
                throw ProcurementException::state('order', $order->status, 'open');
            }

            foreach ($receipt->lines as $rl) {
                $ol = PurchaseOrderLine::query()->whereKey($rl->order_line_id)->lockForUpdate()->firstOrFail();
                $remaining = Decimal::sub($ol->qty_received, $rl->qty);
                if (Decimal::compare($remaining, Decimal::add($ol->qty_returned, $ol->qty_billed), 6) < 0) {
                    throw ProcurementException::make('procurement.receipt_used', 'Part of this receipt has been billed or returned; return the goods or credit the bill instead of reversing the receipt.', 422);
                }
            }

            $reversal = $this->stock->reverse($receipt->stock_document_id, $note, $by);
            foreach ($receipt->lines as $rl) {
                $ol = PurchaseOrderLine::query()->whereKey($rl->order_line_id)->firstOrFail();
                $ol->forceFill(['qty_received' => Decimal::sub($ol->qty_received, $rl->qty)])->save();
            }
            $receipt->forceFill(['status' => 'reversed', 'reversal_document_id' => $reversal->documentId])->save();
            $this->audit->record('procurement.receipt_reversed', $receipt, ['status' => 'posted'], ['status' => 'reversed', 'document_no' => $receipt->document_no, 'note' => $note]);

            return $receipt->refresh();
        });
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $orderLines  keyed by line number
     * @param  array<int, ItemInfo>  $items
     * @return list<array{line: PurchaseOrderLine, qty: string, lot: ?string, expiry: ?string}>
     */
    private function pick(mixed $requested, $orderLines, array $items): array
    {
        $open = fn (PurchaseOrderLine $l): string => Decimal::sub(Decimal::add(Decimal::sub($l->qty, $l->qty_received), $l->qty_returned), '0');
        $out = [];

        if ($requested === null || $requested === []) {
            foreach ($orderLines as $line) {
                $left = $open($line);
                if (($items[$line->item_id] ?? null)?->isStocked() && Decimal::compare($left, '0') > 0 && ! ($items[$line->item_id]->tracksLots)) {
                    $out[] = ['line' => $line, 'qty' => Decimal::round($left, 6), 'lot' => null, 'expiry' => null];
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
            $item = $items[$line->item_id] ?? null;
            if ($item === null || ! $item->isStocked()) {
                throw ProcurementException::make('procurement.line_not_receivable', 'Only stock items are received; services are just billed.', 422, "{$field}.line");
            }
            $qty = trim((string) ($row['qty'] ?? ''));
            $left = $open($line);
            if (! Decimal::isDecimal($qty, 6) || Decimal::compare($qty, '0') <= 0) {
                throw ProcurementException::make('procurement.qty_invalid', 'Enter a quantity above zero.', 422, "{$field}.qty");
            }
            if (Decimal::compare($qty, $left) > 0) {
                throw ProcurementException::make('procurement.qty_exceeds_open', 'Only '.Decimal::trim(Decimal::round($left, 6))." of line {$no} is still open on the order.", 422, "{$field}.qty");
            }
            $lot = $this->text($row['lot'] ?? null, 60);
            $expiry = $this->text($row['expiry_date'] ?? null, 10);
            $out[] = ['line' => $line, 'qty' => Decimal::round($qty, 6), 'lot' => $lot, 'expiry' => $expiry];
        }

        return $out;
    }

    private function text(mixed $value, int $max): ?string
    {
        $v = $value === null ? '' : trim((string) $value);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
