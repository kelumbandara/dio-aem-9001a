<?php

namespace App\Modules\Finance\Actions;

use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Finance\Approvals\SupplierPaymentPost;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Services\SettlementService;
use App\Modules\Finance\Services\SubledgerQueries;
use Illuminate\Support\Facades\DB;

/**
 * Entry point for people recording a receipt or a payment. Receipts are posted at once. Payments to suppliers are
 * approval-gated (money leaves the company): posted at once only when no policy asks for approval.
 */
final class PostSettlement
{
    public function __construct(
        private readonly SettlementService $settlements,
        private readonly ApprovalService $approvals,
        private readonly SubledgerQueries $queries,
    ) {}

    /**
     * @param  array<string, mixed>  $in  see SettlementService::post()
     * @return Settlement|array{approval_request_id: string, document_no: string}
     */
    public function __invoke(array $in, int $by, string $channel = 'web', ?string $idempotencyKey = null): Settlement|array
    {
        if (($in['side'] ?? null) !== OpenItem::PAYABLE) {
            return $this->settlements->post($in, $by);
        }

        // Check first (same checks and arithmetic, then undone): a bad payment is refused now, not queued for approval.
        DB::beginTransaction();

        try {
            $this->settlements->post($in, $by);
        } finally {
            DB::rollBack();
        }

        $decision = $this->approvals->gate(
            SupplierPaymentPost::KEY,
            ['payment' => $in, 'supplier_code' => explode(' — ', $this->queries->names(OpenItem::PAYABLE, [(int) $in['party_id']])[(int) $in['party_id']] ?? '')[0]],
            $by,
            idempotencyKey: $idempotencyKey,
            channel: $channel,
        );

        if ($decision->requiresApproval) {
            return ['approval_request_id' => (string) $decision->requestId, 'document_no' => (string) $decision->documentNo];
        }

        return $this->settlements->post($in, $by);
    }
}
