<?php

namespace App\Modules\Inventory\Contracts;

/**
 * Deterministic stock figures for Reporting and the AI (read only). Money is LKR as decimal strings at the average cost the
 * stock ledger holds; quantities are in each item's base unit.
 */
interface StockMetrics
{
    /**
     * Stock on hand now, valued at average cost.
     *
     * @return array{total_value: string, items_in_stock: int, by_warehouse: list<array{warehouse_id: int, code: string, name: string, value: string}>}
     */
    public function valuation(): array;

    /** @return list<array{item_id: int, code: string, name: string, uom: string, qty: string, value: string}> the items holding the most value, largest first */
    public function topValueItems(int $limit = 10): array;

    /**
     * What came into and went out of stock in a period (transfers between locations are neither), with the kinds of movement.
     *
     * @return array{inflow: string, outflow: string, net: string, by_reason: list<array{type: string, reason: string, value: string}>}
     */
    public function movement(string $from, string $to): array;

    /**
     * Items below the minimum set in their reorder rule.
     *
     * @return array{count: int, list: list<array{item_id: int, code: string, name: string, uom: string, warehouse: string|null, available: string, min_qty: string, shortfall: string}>}
     */
    public function belowReorder(int $limit = 10): array;

    /**
     * Lots with stock that expire within $days (or already have): how many lots, what value they hold, the soonest first.
     *
     * @return array{lots: int, value: string, expired_lots: int, expired_value: string, list: list<array{item_id: int, code: string, name: string, lot_no: string, expiry_date: string, qty: string, expired: bool}>}
     */
    public function expiring(int $days = 30, int $limit = 10): array;
}
