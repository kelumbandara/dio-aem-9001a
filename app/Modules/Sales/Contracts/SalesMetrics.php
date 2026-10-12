<?php

namespace App\Modules\Sales\Contracts;

/**
 * Deterministic sales figures for Reporting and the AI (read only). Dates are Colombo calendar days, `from` and `to`
 * both included. Money is LKR as decimal strings; "net" excludes VAT and levies. Cancelled and draft documents never count.
 */
interface SalesMetrics
{
    /**
     * @return array{invoices: int, net_invoiced: string, gross_invoiced: string, credit_notes: int, net_credited: string, net_sales: string, gross_sales: string, average_invoice: string, quotations: int, quotations_value: string, orders_confirmed: int, orders_value: string, deliveries: int}
     */
    public function summary(string $from, string $to): array;

    /**
     * Net sales (invoices less credit notes) for every day of the period, days without sales as zero.
     *
     * @return list<array{date: string, net: string}>
     */
    public function dailyNet(string $from, string $to): array;

    /** @return list<array{customer_id: int, public_id: string, code: string, name: string, invoices: int, net: string}> best first, credit notes deducted */
    public function topCustomers(string $from, string $to, int $limit = 10): array;

    /** @return list<array{item_id: int, code: string, name: string, uom: string, qty: string, net: string}> best first by net, credit notes deducted */
    public function topItems(string $from, string $to, int $limit = 10): array;

    /** @return list<array{id: string, document_no: string, customer: string, date: string, gross: string}> largest first */
    public function largestInvoices(string $from, string $to, int $limit = 10): array;

    /**
     * Confirmed orders not yet fully invoiced: how many, what is still to invoice (gross), how many are past their required date.
     *
     * @return array{orders: int, to_invoice: string, overdue: int, list: list<array{id: string, document_no: string, customer: string, required_date: string|null, to_invoice: string, overdue: bool}>}
     */
    public function openOrders(int $limit = 10): array;
}
