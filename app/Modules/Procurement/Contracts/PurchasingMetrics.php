<?php

namespace App\Modules\Procurement\Contracts;

/**
 * Deterministic purchasing figures for Reporting and the AI (read only). Dates are Colombo calendar days, `from` and `to`
 * both included; money is LKR as decimal strings; "net" excludes VAT.
 */
interface PurchasingMetrics
{
    /**
     * @return array{orders_approved: int, orders_value: string, receipts: int, receipts_value: string, bills: int, bills_net: string, bills_gross: string, returns: int, returns_net: string, net_purchases: string, price_variance: string}
     */
    public function summary(string $from, string $to): array;

    /**
     * Net purchases billed (bills less returns) for every day of the period, days without any as zero.
     *
     * @return list<array{date: string, net: string}>
     */
    public function dailyNet(string $from, string $to): array;

    /** @return list<array{supplier_id: int, public_id: string, code: string, name: string, bills: int, net: string}> largest first, returns deducted */
    public function topSuppliers(string $from, string $to, int $limit = 10): array;

    /**
     * Approved orders still to be received: how many, the net value still to arrive, how many are past their expected date.
     *
     * @return array{orders: int, to_receive: string, overdue: int, list: list<array{id: string, document_no: string, supplier: string, expected_date: string|null, to_receive: string, overdue: bool}>}
     */
    public function openOrders(int $limit = 10): array;
}
