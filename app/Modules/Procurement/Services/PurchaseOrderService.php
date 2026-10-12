<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Core\Contracts\NumberingService;
use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\Procurement\Approvals\PurchaseOrderApprove;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Purchase orders. A draft is edited freely. Submitting it goes through the approval gate: with no approval policy for
 * `procurement.po.approve` the order is approved at once, otherwise it waits (read-only) for the approvers and can be
 * withdrawn back to a draft. Only an approved order can receive goods or be billed.
 */
final class PurchaseOrderService
{
    public function __construct(
        private readonly DocumentHeader $header,
        private readonly LineBuilder $builder,
        private readonly NumberingService $numbering,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
        private readonly WarehouseDirectory $warehouses,
        private readonly ApprovalService $approvals,
        private readonly ItemCatalog $items,
    ) {}

    /**
     * @param  array<string, mixed>  $data  supplier|supplier_id, warehouse|warehouse_id, order_date?, expected_date?, supplier_ref?, payment_terms_days?, notes?, lines, version? (update)
     *
     * @throws BusinessException
     */
    public function save(array $data, ?PurchaseOrder $order = null, ?int $by = null): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $order, $by): PurchaseOrder {
            if ($order !== null) {
                $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($order->status !== 'draft') {
                    throw ProcurementException::state('order', $order->status, 'a draft');
                }
                if (isset($data['version']) && (int) $data['version'] !== $order->version) {
                    throw ProcurementException::stale('order');
                }
            }

            $supplier = $this->header->supplier($data);
            $warehouseId = $this->warehouseId($data);
            $orderDate = $this->header->date($data, 'order_date', $this->header->today());
            $expected = $this->header->date($data, 'expected_date', null, false);
            if ($expected !== null && $expected < $orderDate) {
                throw ProcurementException::make('procurement.expected_date_invalid', 'The expected date cannot be before the order date.', 422, 'expected_date');
            }
            $terms = isset($data['payment_terms_days']) && $data['payment_terms_days'] !== '' ? (int) $data['payment_terms_days'] : (int) ($supplier->paymentTermsDays ?? 0);
            if ($terms < 0 || $terms > 365) {
                throw ProcurementException::make('procurement.terms_invalid', 'Payment terms are between 0 and 365 days.', 422, 'payment_terms_days');
            }
            $inclusive = $order !== null ? $order->prices_include_vat : (bool) config('procurement.prices_include_vat');
            $built = $this->builder->build((array) ($data['lines'] ?? []), $supplier->id, $orderDate, $inclusive);

            $values = [
                'supplier_id' => $supplier->id, 'warehouse_id' => $warehouseId, 'order_date' => $orderDate, 'expected_date' => $expected,
                'supplier_ref' => $this->text($data['supplier_ref'] ?? null, 100), 'payment_terms_days' => $terms, 'notes' => $this->text($data['notes'] ?? null, 2000),
                'net_total' => $built['totals']['net'], 'vat_total' => $built['totals']['vat'], 'gross_total' => $built['totals']['gross'],
            ];

            if ($order === null) {
                $this->numbering->define('procurement.order', 'PO/{FY}/{SEQ}', 5, 'fiscal_yearly');
                $order = PurchaseOrder::query()->create($values + [
                    'document_no' => $this->numbering->next('procurement.order', null, CarbonImmutable::parse($orderDate)), 'status' => 'draft', 'version' => 1, 'created_by' => $by, 'prices_include_vat' => $inclusive,
                ]);
            } else {
                $order->forceFill($values + ['version' => $order->version + 1])->save();
                $order->lines()->delete();
            }
            foreach ($built['lines'] as $line) {
                $order->lines()->create($line + ['qty_received' => '0', 'qty_returned' => '0', 'qty_billed' => '0']);
            }

            $this->audit->record('procurement.order_saved', $order, [], ['document_no' => $order->document_no, 'supplier_id' => $supplier->id, 'gross_total' => $order->gross_total]);

            return $order->refresh();
        });
    }

    /**
     * Ask for the order to be approved: at once when no policy asks for more, otherwise it waits for the approvers.
     *
     * @throws BusinessException
     */
    public function submit(PurchaseOrder $order, int $by, string $channel = 'web', ?string $idempotencyKey = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $by, $channel, $idempotencyKey): PurchaseOrder {
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'draft') {
                throw ProcurementException::state('order', $order->status, 'a draft');
            }

            $decision = $this->approvals->gate(
                PurchaseOrderApprove::KEY,
                ['order_id' => $order->id, 'version' => $order->version, 'document_no' => $order->document_no, 'gross_total' => $order->gross_total],
                $by,
                subject: $order,
                idempotencyKey: $idempotencyKey,
                channel: $channel,
            );

            if ($decision->requiresApproval) {
                $order->forceFill(['status' => 'pending_approval', 'approval_request_id' => (string) $decision->requestId, 'version' => $order->version + 1])->save();
                $this->audit->record('procurement.order_submitted', $order, ['status' => 'draft'], ['status' => 'pending_approval', 'document_no' => $order->document_no]);

                return $order->refresh();
            }

            return $this->approve($order->id, $by);
        });
    }

    /** Called when no approval is needed or when the approvers have approved. */
    public function approve(int $orderId, ?int $approvedBy): PurchaseOrder
    {
        return DB::transaction(function () use ($orderId, $approvedBy): PurchaseOrder {
            $order = PurchaseOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['draft', 'pending_approval'], true)) {
                throw ProcurementException::state('order', $order->status, 'a draft or waiting for approval');
            }
            $from = $order->status;
            $order->forceFill(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $approvedBy, 'version' => $order->version + 1])->save();
            $this->audit->record('procurement.order_approved', $order, ['status' => $from], ['status' => 'approved', 'document_no' => $order->document_no]);
            $this->outbox->record('procurement.order.approved', ['order_id' => $order->id, 'document_no' => $order->document_no, 'supplier_id' => $order->supplier_id, 'gross_total' => $order->gross_total], 'purchase_order', $order->id);

            return $order->refresh();
        });
    }

    /** Back to a draft (for example after the approvers rejected it). */
    public function withdraw(PurchaseOrder $order, ?int $by = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $by): PurchaseOrder {
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'pending_approval') {
                throw ProcurementException::state('order', $order->status, 'waiting for approval');
            }
            $order->forceFill(['status' => 'draft', 'approval_request_id' => null, 'version' => $order->version + 1])->save();
            $this->audit->record('procurement.order_withdrawn', $order, ['status' => 'pending_approval'], ['status' => 'draft', 'by' => $by]);

            return $order->refresh();
        });
    }

    /** @throws BusinessException */
    public function cancel(PurchaseOrder $order, string $reason, ?int $by = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $reason, $by): PurchaseOrder {
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $reason = trim($reason);
            if (! in_array($order->status, ['draft', 'pending_approval', 'approved'], true)) {
                throw ProcurementException::state('order', $order->status, 'open');
            }
            if ($reason === '') {
                throw ProcurementException::make('procurement.reason_required', 'Give a reason for cancelling.', 422, 'reason');
            }
            if ($order->lines()->where(fn ($q) => $q->where('qty_received', '>', 0)->orWhere('qty_billed', '>', 0))->exists()) {
                throw ProcurementException::make('procurement.order_in_progress', 'Goods have been received or billed on this order; it cannot be cancelled.', 422);
            }
            $from = $order->status;
            $order->forceFill(['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 255), 'version' => $order->version + 1])->save();
            $this->audit->record('procurement.order_cancelled', $order, ['status' => $from], ['status' => 'cancelled', 'document_no' => $order->document_no, 'reason' => $reason, 'by' => $by]);

            return $order->refresh();
        });
    }

    /** Complete the order once everything is received (stock lines) and billed, net of returns and debit notes. */
    public function refreshStatus(PurchaseOrder $order): void
    {
        if ($order->status !== 'approved') {
            return;
        }
        $lines = $order->lines()->get();
        $items = $this->items->findMany($lines->pluck('item_id')->all());
        $done = $lines->every(function (PurchaseOrderLine $l) use ($items) {
            $stocked = ($items[$l->item_id] ?? null)?->isStocked() ?? false;

            return Decimal::compare($l->qty_billed, $l->qty) === 0 && (! $stocked || Decimal::compare(Decimal::sub($l->qty_received, $l->qty_returned), $l->qty) === 0);
        });
        if ($done) {
            $order->forceFill(['status' => 'completed', 'version' => $order->version + 1])->save();
            $this->audit->record('procurement.order_completed', $order, ['status' => 'approved'], ['status' => 'completed', 'document_no' => $order->document_no]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function warehouseId(array $data): int
    {
        $id = isset($data['warehouse_id']) && $data['warehouse_id'] !== '' ? (int) $data['warehouse_id'] : (isset($data['warehouse']) ? $this->warehouses->idByCode((string) $data['warehouse']) : null);
        if ($id === null || ! $this->warehouses->isActive($id)) {
            throw ProcurementException::make('procurement.warehouse_required', 'Choose the warehouse the goods are delivered to.', 422, 'warehouse');
        }

        return $id;
    }

    private function text(mixed $value, int $max): ?string
    {
        $v = $value === null ? '' : trim((string) $value);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
