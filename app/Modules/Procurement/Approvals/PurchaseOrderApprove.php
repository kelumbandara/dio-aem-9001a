<?php

namespace App\Modules\Procurement\Approvals;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\DTOs\ExecutionToken;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\PurchaseOrderService;

/**
 * Approval-gated action "approve a purchase order" (amount = the order's gross total). With no policy for this key an
 * order is approved the moment it is submitted. The approval is for one version of one order: if the order changed
 * (it cannot while waiting, but it could be withdrawn and edited), the old request no longer applies.
 */
final class PurchaseOrderApprove implements Approvable
{
    public const KEY = 'procurement.po.approve';

    public function __construct(private readonly PurchaseOrderService $orders) {}

    public static function approvalKey(): string
    {
        return self::KEY;
    }

    public function riskLevel(): string
    {
        return 'R3';
    }

    public function amount(array $payload): ?string
    {
        return $payload['gross_total'] ?? null;
    }

    public function summary(array $payload): string
    {
        return "Purchase order {$payload['document_no']} for LKR ".($payload['gross_total'] ?? '0');
    }

    public function revalidate(array $payload, int $requestedBy): void
    {
        $order = PurchaseOrder::query()->find((int) ($payload['order_id'] ?? 0));
        if ($order === null || $order->status !== 'pending_approval' || $order->version !== (int) ($payload['version'] ?? 0) + 1) {
            throw ProcurementException::make('procurement.approval_stale', 'The order is no longer waiting for this approval (it was withdrawn or changed).', 409);
        }
    }

    public function execute(array $payload, ExecutionToken $token): array
    {
        $order = $this->orders->approve((int) $payload['order_id'], null);   // approved through a request: the approval record names the approvers

        return ['document_id' => $order->id, 'document_no' => $order->document_no];
    }
}
