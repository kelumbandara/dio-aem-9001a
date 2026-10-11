<?php

namespace App\Modules\Finance\Actions;

use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\BankAccount;
use Illuminate\Support\Facades\DB;

/** A bank or cash account of the company, tied to one asset account of the chart. Never deleted, only deactivated. */
final class SaveBankAccount
{
    /**
     * @param  array<string, mixed>  $data  account_id (create only), name, kind, bank_name, account_no, currency, is_active
     */
    public function __invoke(array $data, ?BankAccount $bank = null): BankAccount
    {
        return DB::transaction(function () use ($data, $bank): BankAccount {
            $creating = $bank === null;

            if ($creating) {
                $account = Account::query()->find((int) ($data['account_id'] ?? 0));
                if ($account === null || $account->is_group || ! $account->is_active || $account->class !== 'asset') {
                    throw FinanceException::make('finance.account_invalid', 'Choose an active asset account.', 422, 'account');
                }
                if (BankAccount::query()->where('account_id', $account->id)->exists()) {
                    throw FinanceException::make('finance.bank_exists', 'That account is already a bank/cash account.', 422, 'account');
                }
            }

            $kind = (string) ($data['kind'] ?? $bank->kind ?? 'bank');
            $currency = strtoupper((string) ($data['currency'] ?? $bank->currency ?? 'LKR'));
            $name = trim((string) ($data['name'] ?? $bank->name ?? ''));
            if (! in_array($kind, ['bank', 'cash'], true) || ! preg_match('/^[A-Z]{3}$/', $currency) || $name === '' || mb_strlen($name) > 150) {
                throw FinanceException::make('finance.bank_invalid', 'Give a name, a kind (bank or cash) and a 3-letter currency.', 422, 'name');
            }

            $bank ??= new BankAccount(['account_id' => $account->id]);
            $bank->fill([
                'name' => $name, 'kind' => $kind, 'currency' => $currency,
                'bank_name' => array_key_exists('bank_name', $data) ? $data['bank_name'] : $bank->bank_name,
                'account_no' => array_key_exists('account_no', $data) ? $data['account_no'] : $bank->account_no,
                'is_active' => (bool) ($data['is_active'] ?? $bank->is_active ?? true),
            ])->save();

            return $bank->refresh();
        });
    }
}
