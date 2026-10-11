<?php

namespace App\Modules\Finance\Database\Seeders;

use App\Modules\Core\Contracts\CompanyInfo;
use App\Modules\Finance\Actions\CreateFiscalYear;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\AccountMapping;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\FiscalYear;
use App\Modules\Finance\Services\AccountMap;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Chart of accounts, automatic-posting account mapping, bank/cash accounts and the current fiscal year.
 * Idempotent and gentle: it only adds what is missing and never overwrites an account the accountant has edited.
 */
class FinanceSeeder extends Seeder
{
    /** Names of accounts that exist only to say "do not use". */
    private const INACTIVE = ['2214'];

    /** Bank/cash accounts in the starting chart: account code => [kind, display name]. */
    private const MONEY = [
        '1150' => ['bank', 'Bank of Ceylon - USD', 'USD'], '1155' => ['bank', 'Bank of Ceylon - LKR', 'LKR'],
        '1160' => ['cash', 'Petty cash - SKY', 'LKR'], '1161' => ['cash', 'Petty cash - MNT', 'LKR'], '1162' => ['cash', 'Petty cash - IT', 'LKR'], '1165' => ['cash', 'Cash in hand', 'LKR'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->chart();
            $this->mappings();
            $this->bankAccounts();
            $this->fiscalYear();
        });
    }

    private function chart(): void
    {
        /** @var array<string, array{0: string, 1: string, 2: list<array<int, mixed>>}> $groups */
        $groups = require __DIR__.'/chart-of-accounts.php';

        foreach ($groups as $code => [$name, $class, $accounts]) {
            $group = Account::query()->firstOrCreate(['code' => $code], ['name' => $name, 'class' => $class, 'is_group' => true]);
            foreach ($accounts as $row) {
                Account::query()->firstOrCreate(['code' => $row[0]], [
                    'name' => $row[1], 'class' => $class, 'parent_id' => $group->id, 'is_group' => false, 'subtype' => $row[2] ?? null,
                    'is_active' => ! in_array($row[0], self::INACTIVE, true), 'notes' => $row[3] ?? null,
                ]);
            }
        }
    }

    private function mappings(): void
    {
        foreach (AccountMap::DEFAULTS as $key => $code) {
            $account = Account::query()->where('code', $code)->first();
            if ($account !== null) {
                AccountMapping::query()->firstOrCreate(['key' => $key], ['account_id' => $account->id]);
            }
        }
    }

    private function bankAccounts(): void
    {
        foreach (self::MONEY as $code => [$kind, $name, $currency]) {
            $account = Account::query()->where('code', $code)->first();
            if ($account !== null) {
                BankAccount::query()->firstOrCreate(['account_id' => $account->id], ['name' => $name, 'kind' => $kind, 'currency' => $currency]);
            }
        }
    }

    private function fiscalYear(): void
    {
        if (FiscalYear::query()->exists()) {
            return;
        }
        $startMonth = app(CompanyInfo::class)->fiscalYearStartMonth();
        $today = now((string) config('core.display_timezone'));
        $startYear = $today->month >= $startMonth ? $today->year : $today->year - 1;

        app(CreateFiscalYear::class)($startYear, $startMonth);
    }
}
