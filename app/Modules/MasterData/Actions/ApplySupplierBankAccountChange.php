<?php

namespace App\Modules\MasterData\Actions;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Models\SupplierBankAccount;
use App\Modules\MasterData\Services\BankAccountChangeValidator;
use Illuminate\Support\Facades\DB;

/**
 * Applies a supplier bank account change. Called directly when no approval policy applies and by the
 * approval engine after approval. Account numbers are encrypted at rest and kept out of the audit log.
 */
final class ApplySupplierBankAccountChange
{
    public function __construct(
        private readonly BankAccountChangeValidator $validator,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
    ) {}

    /** @param  array<string, mixed>  $change */
    public function __invoke(array $change): SupplierBankAccount
    {
        return DB::transaction(function () use ($change): SupplierBankAccount {
            $clean = $this->validator->validate($change);
            $supplier = Supplier::query()->whereKey($clean['supplier_id'])->lockForUpdate()->firstOrFail();

            $account = match ($clean['op']) {
                'create' => $this->create($supplier, $clean),
                'update' => $this->update($supplier, $clean),
                default => $this->deactivate($supplier, $clean),
            };

            $this->audit->record("supplier.bank_account_{$clean['op']}", $supplier, [], [], ['account_id' => $account->id, 'bank_name' => $account->bank_name]);
            $this->outbox->record('masterdata.supplier.changed', ['supplier_id' => $supplier->id, 'code' => $supplier->code, 'bank_accounts_changed' => true], 'supplier', $supplier->id);

            return $account;
        });
    }

    /** @param  array<string, mixed>  $c */
    private function create(Supplier $supplier, array $c): SupplierBankAccount
    {
        $hasDefault = SupplierBankAccount::query()->where('supplier_id', $supplier->id)->where('is_active', true)->where('is_default', true)->exists();
        $makeDefault = $c['is_default'] || ! $hasDefault;
        if ($makeDefault) {
            $this->clearDefault($supplier);
        }

        return SupplierBankAccount::query()->create([
            'supplier_id' => $supplier->id, 'bank_name' => $c['bank_name'], 'branch_name' => $c['branch_name'],
            'account_name' => $c['account_name'], 'account_no' => $c['account_no'], 'currency_id' => $c['currency_id'],
            'is_default' => $makeDefault, 'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $c */
    private function update(Supplier $supplier, array $c): SupplierBankAccount
    {
        $account = SupplierBankAccount::query()->whereKey($c['account_id'])->lockForUpdate()->firstOrFail();
        if ($c['is_default'] && ! $account->is_default) {
            $this->clearDefault($supplier);
        }

        $account->fill([
            'bank_name' => $c['bank_name'], 'branch_name' => $c['branch_name'], 'account_name' => $c['account_name'],
            'account_no' => $c['account_no'], 'currency_id' => $c['currency_id'],
            'is_default' => $c['is_default'] || $account->is_default, 'is_active' => true,
        ])->save();

        return $account;
    }

    /** @param  array<string, mixed>  $c */
    private function deactivate(Supplier $supplier, array $c): SupplierBankAccount
    {
        $account = SupplierBankAccount::query()->whereKey($c['account_id'])->lockForUpdate()->firstOrFail();
        $wasDefault = $account->is_default;
        $account->forceFill(['is_active' => false, 'is_default' => false])->save();

        if ($wasDefault) {
            SupplierBankAccount::query()->where('supplier_id', $supplier->id)->where('is_active', true)->orderBy('id')->first()
                ?->forceFill(['is_default' => true])->save();
        }

        return $account;
    }

    private function clearDefault(Supplier $supplier): void
    {
        SupplierBankAccount::query()->where('supplier_id', $supplier->id)->where('is_default', true)->update(['is_default' => false]);
    }
}
