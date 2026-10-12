<?php

namespace App\Modules\Finance\Contracts;

/**
 * Deterministic finance figures for Reporting and the AI (read only), straight from the general ledger and the open items.
 * Dates are Colombo calendar days, `from` and `to` both included; money is LKR as decimal strings.
 */
interface FinanceMetrics
{
    /**
     * Income, cost of sales and expenses of the period. Gross profit = income − cost of sales; net profit = income − all expenses.
     *
     * @return array{income: string, cost_of_sales: string, gross_profit: string, other_expenses: string, expenses: string, net_profit: string}
     */
    public function profit(string $from, string $to): array;

    /**
     * What customers owe (side "receivable") or what is owed to suppliers (side "payable") as at today, by how overdue it is.
     *
     * @return array{total: string, overdue: string, buckets: array{current: string, d1_30: string, d31_60: string, d61_90: string, d90_plus: string}, top: list<array{party_id: int, party: string, total: string, overdue: string}>}
     */
    public function openItems(string $side, int $topLimit = 10): array;

    /**
     * Bank and cash balances as at today, and the money that moved through them in a period.
     *
     * @return array{total: string, accounts: list<array{name: string, kind: string, balance: string}>, received: string, paid: string, net_movement: string}
     */
    public function cash(string $from, string $to): array;
}
