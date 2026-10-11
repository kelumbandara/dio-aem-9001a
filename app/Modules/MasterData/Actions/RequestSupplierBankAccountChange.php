<?php

namespace App\Modules\MasterData\Actions;

use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\MasterData\Approvals\SupplierBankAccountChange;
use App\Modules\MasterData\DTOs\ChangeOutcome;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Services\BankAccountChangeValidator;

/** Every bank account change goes through here: applied now, or opens an approval request if a policy says so. */
final class RequestSupplierBankAccountChange
{
    public function __construct(
        private readonly BankAccountChangeValidator $validator,
        private readonly ApprovalService $approvals,
        private readonly ApplySupplierBankAccountChange $apply,
    ) {}

    /** @param  array<string, mixed>  $change  op, supplier_id, account_id?, bank_name, branch_name, account_name, account_no, currency_id, is_default */
    public function __invoke(array $change, int $requestedBy, string $channel = 'web', ?string $idempotencyKey = null): ChangeOutcome
    {
        $clean = $this->validator->validate($change);
        $supplier = Supplier::query()->findOrFail($clean['supplier_id']);

        $decision = $this->approvals->gate(
            SupplierBankAccountChange::KEY,
            $clean + ['supplier_code' => $supplier->code],
            $requestedBy,
            subject: $supplier,
            idempotencyKey: $idempotencyKey,
            channel: $channel,
        );

        if ($decision->requiresApproval) {
            return ChangeOutcome::pending((string) $decision->requestId, (string) $decision->documentNo);
        }

        ($this->apply)($clean);

        return ChangeOutcome::applied();
    }
}
