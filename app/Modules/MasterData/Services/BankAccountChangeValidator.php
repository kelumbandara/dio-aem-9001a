<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Models\SupplierBankAccount;

/** Checks and normalises a supplier bank account change; run before requesting it and again before applying it. */
final class BankAccountChangeValidator
{
    public const OPS = ['create', 'update', 'deactivate'];

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    public function validate(array $change): array
    {
        $op = $change['op'] ?? '';
        if (! in_array($op, self::OPS, true)) {
            throw MasterDataException::make('masterdata.bank_op_invalid', 'Unknown bank account operation.', 422, 'op');
        }

        $supplier = Supplier::query()->find($change['supplier_id'] ?? 0) ?? throw MasterDataException::notFound('supplier');
        if (! $supplier->is_active) {
            throw MasterDataException::inactive('supplier');
        }

        $clean = ['op' => $op, 'supplier_id' => $supplier->id];

        if ($op !== 'create') {
            $account = SupplierBankAccount::query()->where('supplier_id', $supplier->id)->find($change['account_id'] ?? 0)
                ?? throw MasterDataException::make('masterdata.bank_account_not_found', 'This supplier has no such bank account.', 404);
            $clean['account_id'] = $account->id;
        }

        if ($op === 'deactivate') {
            return $clean;
        }

        $bank = trim((string) ($change['bank_name'] ?? ''));
        $holder = trim((string) ($change['account_name'] ?? ''));
        if ($bank === '' || $holder === '') {
            throw MasterDataException::make('masterdata.bank_fields_required', 'Bank name and account name are required.', 422, 'bank_name');
        }

        $no = preg_replace('/\s+/', '', (string) ($change['account_no'] ?? ''));
        if (preg_match('/^[A-Za-z0-9\-]{4,34}$/', (string) $no) !== 1) {
            throw MasterDataException::make('masterdata.account_no_invalid', 'The account number must be 4–34 letters, digits or dashes.', 422, 'account_no');
        }

        $currencyId = $change['currency_id'] ?? null;
        if ($currencyId !== null && ! Currency::query()->whereKey((int) $currencyId)->where('is_active', true)->exists()) {
            throw MasterDataException::make('masterdata.reference_invalid', 'The currency does not exist or is inactive.', 422, 'currency_id');
        }

        return $clean + [
            'bank_name' => $bank,
            'branch_name' => ($b = trim((string) ($change['branch_name'] ?? ''))) === '' ? null : $b,
            'account_name' => $holder,
            'account_no' => $no,
            'currency_id' => $currencyId !== null ? (int) $currencyId : null,
            'is_default' => (bool) ($change['is_default'] ?? false),
        ];
    }
}
