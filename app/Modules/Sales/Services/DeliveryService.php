<?php

namespace App\Modules\Sales\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Core\Contracts\NumberingService;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\Contracts\StockReserver;
use App\Modules\Inventory\DTOs\StockLine;
use App\Modules\Inventory\DTOs\StockMovement;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\DTOs\ItemInfo;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderLine;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deliveries send stock to the customer: the goods leave the order's warehouse as a stock issue (reason "sale"), which
 * is what posts the cost of sales automatically. Stock can never go negative, so a delivery of more than is on hand
 * fails as a whole. A delivery that has not been invoiced yet can be reversed.
 */
final class DeliveryService
{
    public function __construct(
        private readonly DocumentHeader $header,
        private readonly NumberingService $numbering,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
        private readonly StockPoster $stock,
        private readonly StockReserver $reserver,
        private readonly ItemCatalog $items,
        private readonly OrderService $orders,
    ) {}

    /**
     * @param  array<string, mixed>  $data  delivery_date?, notes?, lines? [{line: line number, qty}] — all open stock lines when omitted
     *
     * @throws BusinessException
     */
    public function post(Order $order, array $data, ?int $by = null): Delivery
    {
        return DB::transaction(function () use ($order, $data, $by): Delivery {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'confirmed') {
                throw SalesException::state('order', $order->status, 'confirmed');
            }
            $date = $this->header->date($data, 'delivery_date', $this->header->today());
            $orderLines = $order->lines()->get()->keyBy('line_no');
            $items = $this->items->findMany($orderLines->pluck('item_id')->all());

            $picked = $this->pick($data['lines'] ?? null, $orderLines, $items);
            if ($picked === []) {
                throw SalesException::make('sales.nothing_to_deliver', 'There is nothing left to deliver on this order.', 422, 'lines');
            }

            $this->numbering->define('sales.delivery', 'DN/{FY}/{SEQ}', 5, 'fiscal_yearly');
            $delivery = Delivery::query()->create([
                'document_no' => $this->numbering->next('sales.delivery', null, CarbonImmutable::parse($date)), 'order_id' => $order->id, 'customer_id' => $order->customer_id,
                'warehouse_id' => $order->warehouse_id, 'delivery_date' => $date, 'status' => 'posted',
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? mb_substr(trim((string) $data['notes']), 0, 2000) : null, 'created_by' => $by,
            ]);

            $stockLines = [];
            foreach ($picked as $p) {
                /** @var OrderLine $line */
                $line = $p['line'];
                $delivery->lines()->create(['order_line_id' => $line->id, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'qty' => $p['qty']]);
                $stockLines[] = new StockLine(itemId: $line->item_id, qty: Decimal::trim($p['qty']), uomId: $line->uom_id, warehouseId: $order->warehouse_id);
                // The goods leave against what was promised to this order; release it first so the issue sees that stock as free.
                if ($line->reservation_id !== null) {
                    $this->reserver->release($line->reservation_id);
                }
            }

            $posted = $this->stock->post(new StockMovement(
                type: 'issue', postingDate: $date, reason: 'sale', lines: $stockLines, reference: $delivery->document_no,
                notes: "Delivery {$delivery->document_no} for {$order->document_no}", sourceType: 'sales_delivery', sourceId: $delivery->public_id, createdBy: $by,
            ));
            $delivery->forceFill(['stock_document_id' => $posted->documentId])->save();

            foreach ($picked as $p) {
                OrderLine::query()->whereKey($p['line']->id)->update(['qty_delivered' => Decimal::add($p['line']->qty_delivered, $p['qty'])]);
            }
            $order->unsetRelation('lines');
            $this->orders->rebalanceReservations($order, $by);
            $this->orders->refreshStatus($order);

            $this->audit->record('sales.delivery_posted', $delivery, [], ['document_no' => $delivery->document_no, 'order' => $order->document_no, 'stock_document' => $posted->documentNo, 'lines' => count($picked)]);
            $this->outbox->record('sales.delivery.posted', ['delivery_id' => $delivery->id, 'document_no' => $delivery->document_no, 'order_id' => $order->id, 'customer_id' => $order->customer_id], 'sales_delivery', $delivery->id);

            return $delivery->refresh();
        });
    }

    /** @throws BusinessException */
    public function reverse(Delivery $delivery, ?string $note = null, ?int $by = null): Delivery
    {
        return DB::transaction(function () use ($delivery, $note, $by): Delivery {
            $order = Order::query()->whereKey($delivery->order_id)->lockForUpdate()->firstOrFail();
            $delivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($delivery->status !== 'posted') {
                throw SalesException::make('sales.already_reversed', 'This delivery has already been reversed.', 409);
            }
            if ($order->status === 'cancelled') {
                throw SalesException::state('order', $order->status, 'open');
            }

            foreach ($delivery->lines as $dl) {
                $ol = OrderLine::query()->whereKey($dl->order_line_id)->lockForUpdate()->firstOrFail();
                if (Decimal::compare(Decimal::sub($ol->qty_delivered, $dl->qty), $ol->qty_invoiced) < 0) {
                    throw SalesException::make('sales.delivery_invoiced', 'Part of this delivery has been invoiced; credit the invoice instead of reversing the delivery.', 422);
                }
            }

            $reversal = $this->stock->reverse($delivery->stock_document_id, $note, $by);
            foreach ($delivery->lines as $dl) {
                $ol = OrderLine::query()->whereKey($dl->order_line_id)->firstOrFail();
                $ol->forceFill(['qty_delivered' => Decimal::sub($ol->qty_delivered, $dl->qty)])->save();
            }
            $delivery->forceFill(['status' => 'reversed', 'reversal_document_id' => $reversal->documentId])->save();
            $this->orders->rebalanceReservations($order, $by);

            $this->audit->record('sales.delivery_reversed', $delivery, ['status' => 'posted'], ['status' => 'reversed', 'document_no' => $delivery->document_no, 'note' => $note]);

            return $delivery->refresh();
        });
    }

    /**
     * @param  Collection<int, OrderLine>  $orderLines  keyed by line number
     * @param  array<int, ItemInfo>  $items
     * @return list<array{line: OrderLine, qty: string}>
     */
    private function pick(mixed $requested, $orderLines, array $items): array
    {
        $out = [];
        if ($requested === null || $requested === []) {
            foreach ($orderLines as $line) {
                $open = Decimal::sub($line->qty, $line->qty_delivered);
                if (($items[$line->item_id] ?? null)?->isStocked() && Decimal::compare($open, '0') > 0) {
                    $out[] = ['line' => $line, 'qty' => Decimal::round($open, 6)];
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
            $item = $items[$line->item_id] ?? null;
            if ($item === null || ! $item->isStocked()) {
                throw SalesException::make('sales.line_not_deliverable', 'Only stock items are delivered; services are just invoiced.', 422, "{$field}.line");
            }
            $qty = trim((string) ($row['qty'] ?? ''));
            $open = Decimal::sub($line->qty, $line->qty_delivered);
            if (! Decimal::isDecimal($qty, 6) || Decimal::compare($qty, '0') <= 0) {
                throw SalesException::make('sales.qty_invalid', 'Enter a quantity above zero.', 422, "{$field}.qty");
            }
            if (Decimal::compare($qty, $open) > 0) {
                throw SalesException::make('sales.qty_exceeds_open', "Only {$open} of line {$no} is left to deliver.", 422, "{$field}.qty");
            }
            $out[] = ['line' => $line, 'qty' => Decimal::round($qty, 6)];
        }

        return $out;
    }
}
