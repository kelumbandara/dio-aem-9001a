<?php

namespace App\Modules\Sales\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Core\Contracts\NumberingService;
use App\Modules\Finance\Contracts\ReceivablePosition;
use App\Modules\Inventory\Contracts\StockAvailability;
use App\Modules\Inventory\Contracts\StockReserver;
use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\Contracts\UomConverter;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderLine;
use App\Modules\Sales\Models\Quotation;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sales orders. A draft is edited freely; confirming it checks the customer's credit limit (a hard block unless a
 * person with the override permission gives a reason) and promises available stock to the order. The order then drives
 * deliveries and invoices and completes itself when everything is delivered and invoiced.
 */
final class OrderService
{
    public function __construct(
        private readonly DocumentHeader $header,
        private readonly LineBuilder $builder,
        private readonly NumberingService $numbering,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
        private readonly ReceivablePosition $receivables,
        private readonly WarehouseDirectory $warehouses,
        private readonly StockReserver $reserver,
        private readonly StockAvailability $availability,
        private readonly ItemCatalog $items,
        private readonly UomConverter $uoms,
    ) {}

    /**
     * @param  array<string, mixed>  $data  customer|customer_id, warehouse|warehouse_id, order_date?, required_date?, customer_ref?, payment_terms_days?, notes?, lines, version? (update)
     *
     * @throws BusinessException
     */
    public function save(array $data, ?Order $order = null, ?int $by = null, ?Quotation $from = null): Order
    {
        return DB::transaction(function () use ($data, $order, $by, $from): Order {
            if ($order !== null) {
                $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($order->status !== 'draft') {
                    throw SalesException::state('order', $order->status, 'a draft');
                }
                if (isset($data['version']) && (int) $data['version'] !== $order->version) {
                    throw SalesException::stale('order');
                }
            }

            $customer = $this->header->customer($data);
            $warehouseId = $this->warehouseId($data);
            $orderDate = $this->header->date($data, 'order_date', $this->header->today());
            $required = $this->header->date($data, 'required_date', null, false);
            if ($required !== null && $required < $orderDate) {
                throw SalesException::make('sales.required_date_invalid', 'The required date cannot be before the order date.', 422, 'required_date');
            }
            $terms = isset($data['payment_terms_days']) && $data['payment_terms_days'] !== '' ? (int) $data['payment_terms_days'] : (int) ($customer->paymentTermsDays ?? 0);
            if ($terms < 0 || $terms > 365) {
                throw SalesException::make('sales.terms_invalid', 'Payment terms are between 0 and 365 days.', 422, 'payment_terms_days');
            }
            $inclusive = $order !== null ? $order->prices_include_vat : ($from !== null ? $from->prices_include_vat : (bool) config('sales.prices_include_vat'));
            $built = $this->builder->build((array) ($data['lines'] ?? []), $customer->id, $orderDate, $inclusive);

            $values = [
                'customer_id' => $customer->id, 'warehouse_id' => $warehouseId, 'order_date' => $orderDate, 'required_date' => $required,
                'customer_ref' => $this->text($data['customer_ref'] ?? null, 100), 'payment_terms_days' => $terms, 'notes' => $this->text($data['notes'] ?? null, 2000),
                'net_total' => $built['totals']['net'], 'vat_total' => $built['totals']['vat'], 'levy_total' => $built['totals']['levy'], 'gross_total' => $built['totals']['gross'],
            ];

            if ($order === null) {
                $this->numbering->define('sales.order', 'SO/{FY}/{SEQ}', 5, 'fiscal_yearly');
                $order = Order::query()->create($values + [
                    'document_no' => $this->numbering->next('sales.order', null, CarbonImmutable::parse($orderDate)), 'status' => 'draft', 'version' => 1,
                    'quotation_id' => $from?->id, 'created_by' => $by, 'prices_include_vat' => $inclusive,
                ]);
            } else {
                $order->forceFill($values + ['version' => $order->version + 1])->save();
                $order->lines()->delete();
            }
            foreach ($built['lines'] as $line) {
                $order->lines()->create($line + ['qty_delivered' => '0', 'qty_invoiced' => '0']);
            }

            $this->audit->record('sales.order_saved', $order, [], ['document_no' => $order->document_no, 'customer_id' => $customer->id, 'gross_total' => $order->gross_total]);

            return $order->refresh();
        });
    }

    /**
     * Make a draft order from an accepted quotation, keeping the quoted prices.
     *
     * @param  array<string, mixed>  $data  warehouse|warehouse_id, order_date?, required_date?, customer_ref?, payment_terms_days?, notes?
     *
     * @throws BusinessException
     */
    public function fromQuotation(Quotation $quotation, array $data, ?int $by = null): Order
    {
        return DB::transaction(function () use ($quotation, $data, $by): Order {
            $quotation = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($quotation->status !== 'accepted') {
                throw SalesException::state('quotation', $quotation->status, 'accepted');
            }
            $lines = $quotation->lines->map(fn ($l) => [
                'item_id' => $l->item_id, 'qty' => Decimal::trim($l->qty), 'uom_id' => $l->uom_id, 'unit_price' => $l->unit_price, 'discount_pct' => $l->discount_pct,
                'tax_code_id' => $l->tax_code_id, 'description' => $l->description,
            ])->all();

            $order = $this->save($data + ['customer_id' => $quotation->customer_id, 'customer_ref' => $data['customer_ref'] ?? $quotation->reference, 'lines' => $lines], null, $by, $quotation);
            $quotation->forceFill(['status' => 'converted', 'version' => $quotation->version + 1])->save();
            $this->audit->record('sales.quotation_converted', $quotation, ['status' => 'accepted'], ['status' => 'converted', 'order' => $order->document_no]);

            return $order;
        });
    }

    /**
     * Confirm a draft: the credit check, then stock reservation.
     *
     * @param  bool  $canOverride  whether the person may exceed the credit limit (then `$overrideReason` is required)
     *
     * @throws BusinessException sales.credit_limit_exceeded
     */
    public function confirm(Order $order, ?int $by = null, bool $canOverride = false, ?string $overrideReason = null): Order
    {
        return DB::transaction(function () use ($order, $by, $canOverride, $overrideReason): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'draft') {
                throw SalesException::state('order', $order->status, 'a draft');
            }
            $customer = $this->header->customer(['customer_id' => $order->customer_id]);

            // One confirmation per customer at a time, so two orders cannot both squeeze under the same limit.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['sales.credit.'.$customer->id]);

            $override = null;
            if ($customer->creditLimit !== null) {
                $exposure = Decimal::add(Decimal::add($this->receivables->outstanding($customer->id), $this->uninvoiced($customer->id, $order->id)), $order->gross_total);
                if (Decimal::compare($exposure, $customer->creditLimit, 4) > 0) {
                    $reason = $overrideReason !== null ? trim($overrideReason) : '';
                    if (! $canOverride || $reason === '') {
                        $message = sprintf(
                            '%s would owe LKR %s against a credit limit of LKR %s; the order cannot be confirmed.%s',
                            $customer->name, number_format((float) $exposure, 2), number_format((float) $customer->creditLimit, 2),
                            $canOverride ? ' Give a reason to override the limit.' : ' Ask someone allowed to override the limit.',
                        );
                        throw SalesException::make('sales.credit_limit_exceeded', $message, 422, 'credit');
                    }
                    $override = mb_substr($reason, 0, 255);
                }
            }

            $order->forceFill([
                'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by' => $by, 'version' => $order->version + 1,
                'credit_override_reason' => $override, 'credit_override_by' => $override !== null ? $by : null,
            ])->save();
            $this->rebalanceReservations($order, $by);

            $this->audit->record('sales.order_confirmed', $order, ['status' => 'draft'], ['status' => 'confirmed', 'document_no' => $order->document_no, 'credit_override' => $override]);
            $this->outbox->record('sales.order.confirmed', ['order_id' => $order->id, 'document_no' => $order->document_no, 'customer_id' => $order->customer_id, 'gross_total' => $order->gross_total], 'sales_order', $order->id);

            return $order->refresh();
        });
    }

    /** @throws BusinessException */
    public function cancel(Order $order, string $reason, ?int $by = null): Order
    {
        return DB::transaction(function () use ($order, $reason, $by): Order {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $reason = trim($reason);
            if (! in_array($order->status, ['draft', 'confirmed'], true)) {
                throw SalesException::state('order', $order->status, 'a draft or confirmed');
            }
            if ($reason === '') {
                throw SalesException::make('sales.reason_required', 'Give a reason for cancelling.', 422, 'reason');
            }
            if ($order->lines()->where(fn ($q) => $q->where('qty_delivered', '>', 0)->orWhere('qty_invoiced', '>', 0))->exists()) {
                throw SalesException::make('sales.order_in_progress', 'Part of this order has been delivered or invoiced; it cannot be cancelled.', 422);
            }

            $this->reserver->releaseBySource('sales_order', $order->public_id);
            $order->lines()->update(['reservation_id' => null]);
            $from = $order->status;
            $order->forceFill(['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 255), 'version' => $order->version + 1])->save();
            $this->audit->record('sales.order_cancelled', $order, ['status' => $from], ['status' => 'cancelled', 'document_no' => $order->document_no, 'reason' => $reason, 'by' => $by]);

            return $order->refresh();
        });
    }

    /**
     * Re-promise stock for what is still to be delivered: give back each line's reservation and reserve again what is
     * available, up to the open quantity. Runs inside the caller's transaction (confirm, delivery, delivery reversal).
     */
    public function rebalanceReservations(Order $order, ?int $by = null): void
    {
        $lines = $order->lines()->get();
        $items = $this->items->findMany($lines->pluck('item_id')->all());

        foreach ($lines as $line) {
            $item = $items[$line->item_id] ?? null;
            if ($item === null || ! $item->isStocked()) {
                continue;
            }
            if ($line->reservation_id !== null) {
                $this->reserver->release($line->reservation_id);
            }
            $open = Decimal::sub($line->qty, $line->qty_delivered);
            $reservationId = null;
            if ($order->status === 'confirmed' && Decimal::compare($open, '0') > 0) {
                $openBase = $this->uoms->toBase($open, $line->item_id, $line->uom_id);
                $available = $this->availability->level($line->item_id, $order->warehouse_id)->available;
                $take = Decimal::compare($openBase, $available) <= 0 ? $openBase : $available;
                if (Decimal::compare($take, '0') > 0) {
                    try {
                        $reservationId = $this->reserver->reserve($line->item_id, $order->warehouse_id, Decimal::trim($take), 'sales_order', $order->public_id, $by);
                    } catch (BusinessException $e) {
                        if ($e->errorCode !== 'inventory.insufficient_available') {
                            throw $e;
                        }
                    }
                }
            }
            OrderLine::query()->whereKey($line->id)->update(['reservation_id' => $reservationId]);
        }
    }

    /** Close the order once everything on it is delivered (stock items) and invoiced. */
    public function refreshStatus(Order $order): void
    {
        if ($order->status !== 'confirmed') {
            return;
        }
        $lines = $order->lines()->get();
        $items = $this->items->findMany($lines->pluck('item_id')->all());
        $done = $lines->every(function (OrderLine $l) use ($items) {
            $stocked = ($items[$l->item_id] ?? null)?->isStocked() ?? false;

            return Decimal::compare($l->qty_invoiced, $l->qty) === 0 && (! $stocked || Decimal::compare($l->qty_delivered, $l->qty) === 0);
        });
        if ($done) {
            $order->forceFill(['status' => 'completed', 'version' => $order->version + 1])->save();
            $this->audit->record('sales.order_completed', $order, ['status' => 'confirmed'], ['status' => 'completed', 'document_no' => $order->document_no]);
        }
    }

    /** What the customer's other confirmed orders will still be invoiced for (gross). */
    public function uninvoiced(int $customerId, ?int $exceptOrderId = null): string
    {
        $row = DB::selectOne(
            "SELECT COALESCE(SUM(GREATEST(o.gross_total - COALESCE((SELECT SUM(i.gross_total) FROM sales_invoices i WHERE i.order_id = o.id), 0), 0)), 0) AS v
             FROM sales_orders o WHERE o.customer_id = ? AND o.status = 'confirmed' AND o.id <> ?",
            [$customerId, $exceptOrderId ?? 0],
        );

        return Decimal::round((string) $row->v, 4);
    }

    /** @param  array<string, mixed>  $data */
    private function warehouseId(array $data): int
    {
        $id = isset($data['warehouse_id']) && $data['warehouse_id'] !== '' ? (int) $data['warehouse_id'] : (isset($data['warehouse']) ? $this->warehouses->idByCode((string) $data['warehouse']) : null);
        if ($id === null || ! $this->warehouses->isActive($id)) {
            throw SalesException::make('sales.warehouse_required', 'Choose the warehouse the goods are sent from.', 422, 'warehouse');
        }

        return $id;
    }

    private function text(mixed $value, int $max): ?string
    {
        $v = $value === null ? '' : trim((string) $value);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
