<?php

namespace App\Modules\Finance\Actions;

use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\AccountMapping;
use App\Modules\Finance\Services\AccountMap;

/** Points an automatic-posting key at an account of a suitable class. */
final class SaveMapping
{
    /** key => classes that make sense */
    public const CLASSES = [
        'inventory' => ['asset'], 'wip' => ['asset'], 'grni' => ['liability'], 'receivable_control' => ['asset'], 'payable_control' => ['liability'],
        'sales_revenue' => ['income'], 'cogs' => ['expense'], 'stock_adjustment' => ['expense'], 'sample_expense' => ['expense'], 'purchase_variance' => ['expense'],
        'discount_given' => ['income', 'expense'], 'discount_received' => ['income'], 'vat_output' => ['liability'], 'sscl_payable' => ['liability'], 'nbt_payable' => ['liability'],
        'vat_input' => ['asset'], 'opening_balance_equity' => ['equity'], 'retained_earnings' => ['equity'], 'year_result' => ['equity'], 'fx_gain' => ['income'], 'fx_loss' => ['expense'],
    ];

    public function __invoke(string $key, int $accountId): AccountMapping
    {
        if (! in_array($key, AccountMap::keys(), true)) {
            throw FinanceException::make('finance.mapping_unknown', 'Unknown posting key.', 422, 'key');
        }
        $account = Account::query()->find($accountId);
        if ($account === null || $account->is_group || ! $account->is_active) {
            throw FinanceException::make('finance.account_invalid', 'Choose an active account (not a group).', 422, 'account');
        }
        if (! in_array($account->class, self::CLASSES[$key], true)) {
            throw FinanceException::make('finance.mapping_class', "'{$key}' needs an account of class ".implode(' or ', self::CLASSES[$key]).", not {$account->class}.", 422, 'account');
        }

        return AccountMapping::query()->updateOrCreate(['key' => $key], ['account_id' => $account->id]);
    }
}
