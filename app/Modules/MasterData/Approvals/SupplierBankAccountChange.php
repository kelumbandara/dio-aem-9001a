<?php

namespace App\Modules\MasterData\Approvals;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\DTOs\ExecutionToken;
use App\Modules\MasterData\Actions\ApplySupplierBankAccountChange;
use App\Modules\MasterData\Services\BankAccountChangeValidator;

/** Approval-gated action "create, change or deactivate a supplier bank account" (fraud-sensitive). */
final class SupplierBankAccountChange implements Approvable
{
    public const KEY = 'masterdata.supplier.bank_account.change';

    public function __construct(
        private readonly ApplySupplierBankAccountChange $apply,
        private readonly BankAccountChangeValidator $validator,
    ) {}

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
        return null;
    }

    public function summary(array $payload): string
    {
        $what = ['create' => 'Add', 'update' => 'Change', 'deactivate' => 'Deactivate'][$payload['op']] ?? 'Change';

        return "{$what} bank account of supplier {$payload['supplier_code']}"
            .(isset($payload['bank_name']) ? " ({$payload['bank_name']})" : '');
    }

    public function revalidate(array $payload, int $requestedBy): void
    {
        $this->validator->validate($payload);
    }

    public function execute(array $payload, ExecutionToken $token): array
    {
        $account = ($this->apply)($payload);

        return ['bank_account_id' => $account->id, 'op' => $payload['op']];
    }
}
