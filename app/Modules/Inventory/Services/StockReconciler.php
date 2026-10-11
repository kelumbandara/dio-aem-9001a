<?php

namespace App\Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;

/**
 * Proves the stock tables agree with the ledger (docs/architecture/database.md §3, invariant 2):
 * per item/location/lot the balance equals the sum of the ledger, and per item the average-cost quantity and
 * value equal the ledger totals. Read-only.
 */
final class StockReconciler
{
    /** @return list<string> one sentence per discrepancy; empty when everything agrees */
    public function discrepancies(int $limit = 200): array
    {
        $problems = [];

        $rows = DB::select(<<<'SQL'
            SELECT COALESCE(l.item_id, b.item_id) AS item_id, COALESCE(l.location_id, b.location_id) AS location_id,
                   COALESCE(l.lot_id, b.lot_id) AS lot_id, COALESCE(l.q, 0) AS ledger_qty, COALESCE(b.on_hand, 0) AS balance_qty
            FROM (SELECT item_id, location_id, lot_id, SUM(qty) AS q FROM stock_ledger GROUP BY item_id, location_id, lot_id) l
            FULL JOIN stock_balances b ON b.item_id = l.item_id AND b.location_id = l.location_id AND b.lot_id IS NOT DISTINCT FROM l.lot_id
            WHERE COALESCE(l.q, 0) <> COALESCE(b.on_hand, 0)
            LIMIT ?
        SQL, [$limit]);
        foreach ($rows as $r) {
            $problems[] = "Balance of item {$r->item_id} at location {$r->location_id}".($r->lot_id ? " (lot {$r->lot_id})" : '').": ledger says {$r->ledger_qty}, balance says {$r->balance_qty}.";
        }

        $rows = DB::select(<<<'SQL'
            SELECT COALESCE(l.item_id, c.item_id) AS item_id, COALESCE(l.q, 0) AS ledger_qty, COALESCE(l.v, 0) AS ledger_value,
                   COALESCE(c.qty, 0) AS cost_qty, COALESCE(c.value, 0) AS cost_value
            FROM (SELECT item_id, SUM(qty) AS q, SUM(value) AS v FROM stock_ledger GROUP BY item_id) l
            FULL JOIN item_costs c ON c.item_id = l.item_id
            WHERE COALESCE(l.q, 0) <> COALESCE(c.qty, 0) OR COALESCE(l.v, 0) <> COALESCE(c.value, 0)
            LIMIT ?
        SQL, [$limit]);
        foreach ($rows as $r) {
            $problems[] = "Cost of item {$r->item_id}: ledger quantity {$r->ledger_qty} / value {$r->ledger_value}, but costs say {$r->cost_qty} / {$r->cost_value}.";
        }

        $rows = DB::select(<<<'SQL'
            SELECT d.id, d.document_no FROM stock_documents d
            WHERE d.total_value <> COALESCE((SELECT SUM(l.value) FROM stock_document_lines l WHERE l.document_id = d.id), 0)
            LIMIT ?
        SQL, [$limit]);
        foreach ($rows as $r) {
            $problems[] = "Document {$r->document_no}: its total value differs from the sum of its lines.";
        }

        return $problems;
    }
}
