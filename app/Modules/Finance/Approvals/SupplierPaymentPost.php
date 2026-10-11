<?php

namespace App\Modules\Finance\Approvals;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\DTOs\ExecutionToken;
use App\Modules\Finance\Services\SettlementService;
use Illuminate\Support\Facades\DB;

/**
 * Approval-gated action "pay a supplier" (money leaves the company: fraud-sensitive). With no policy for this key a
 * payment is posted at once. Re-checked on execution: the bills may have been settled or the period closed meanwhile.
 */
final class SupplierPaymentPost implements Approvable
{
    public const KEY = 'finance.payment.post';

    public function __construct(private readonly SettlementService $settlements) {}

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
        return $payload['payment']['amount'] ?? null;
    }

    public function summary(array $payload): string
    {
        return 'Pay supplier '.($payload['supplier_code'] ?? '').' LKR '.($payload['payment']['amount'] ?? '0').(($payload['payment']['reference'] ?? null) ? ' ('.$payload['payment']['reference'].')' : '');
    }

    public function revalidate(array $payload, int $requestedBy): void
    {
        // Same checks and arithmetic as posting, undone afterwards.
        DB::beginTransaction();

        try {
            $this->settlements->post($payload['payment'], $requestedBy);
        } finally {
            DB::rollBack();
        }
    }

    public function execute(array $payload, ExecutionToken $token): array
    {
        $payment = $this->settlements->post($payload['payment'], $token->requestedBy);

        return ['settlement_id' => $payment->id, 'document_no' => $payment->document_no];
    }
}
