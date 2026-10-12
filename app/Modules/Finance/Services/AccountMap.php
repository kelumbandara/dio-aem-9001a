<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\AccountMapping;

/**
 * "Posting rules": which ledger account each kind of automatic posting uses. Looked up by key at the moment of posting
 * (no caching: the accountant may change a mapping while a queue worker is running).
 */
final class AccountMap
{
    /** key => account code in the starting chart of accounts */
    public const DEFAULTS = [
        'inventory' => '1100',
        'wip' => '1105',
        'grni' => '1510',
        'receivable_control' => '1125',
        'payable_control' => '1500',
        'sales_revenue' => '2000',
        'cogs' => '2206',
        'stock_adjustment' => '2207',
        'sample_expense' => '2203',
        'purchase_variance' => '2217',
        'production_variance' => '2208',
        'purchase_expense' => '2412',
        'discount_given' => '2100',
        'discount_received' => '2205',
        'vat_output' => '1524',
        'sscl_payable' => '1525',
        'nbt_payable' => '1526',
        'vat_input' => '1126',
        'opening_balance_equity' => '1340',
        'retained_earnings' => '1330',
        'year_result' => '2490',
        'fx_gain' => '2102',
        'fx_loss' => '2503',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    public function idOrNull(string $key): ?int
    {
        $accountId = AccountMapping::query()->where('key', $key)->value('account_id');

        return $accountId === null ? null : (int) $accountId;
    }

    public function id(string $key): int
    {
        $accountId = AccountMapping::query()->where('key', $key)->value('account_id');

        return $accountId !== null ? (int) $accountId
            : throw FinanceException::make('finance.mapping_missing', "No account is set for '{$key}'. Set it under Finance → Account mapping.", 422, 'account');
    }
}
