<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Contracts\StockMetrics;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseStockMetrics implements StockMetrics
{
    public function __construct(private readonly ItemCatalog $items, private readonly StockQueries $queries) {}

    public function valuation(): array
    {
        $total = DB::selectOne('SELECT COALESCE(SUM(value), 0) AS v, COUNT(*) FILTER (WHERE qty > 0) AS n FROM item_costs');
        $rows = DB::select(
            'SELECT w.id, w.code, w.name, ROUND(COALESCE(SUM(b.on_hand * c.value / NULLIF(c.qty, 0)), 0), 6) AS v
             FROM stock_balances b JOIN locations l ON l.id = b.location_id JOIN warehouses w ON w.id = l.warehouse_id JOIN item_costs c ON c.item_id = b.item_id
             WHERE b.on_hand <> 0 GROUP BY w.id, w.code, w.name ORDER BY w.code',
        );

        return [
            'total_value' => Decimal::round((string) $total->v, 2), 'items_in_stock' => (int) $total->n,
            'by_warehouse' => array_map(fn ($r) => ['warehouse_id' => (int) $r->id, 'code' => (string) $r->code, 'name' => (string) $r->name, 'value' => Decimal::round((string) $r->v, 2)], $rows),
        ];
    }

    public function topValueItems(int $limit = 10): array
    {
        $rows = DB::select('SELECT item_id, qty, value FROM item_costs WHERE qty > 0 AND value > 0 ORDER BY value DESC, item_id LIMIT ?', [$limit]);
        $info = $this->items->findMany(array_map(fn ($r) => (int) $r->item_id, $rows));

        return array_map(fn ($r) => [
            'item_id' => (int) $r->item_id, 'code' => $info[(int) $r->item_id]->code ?? '', 'name' => $info[(int) $r->item_id]->name ?? '', 'uom' => $info[(int) $r->item_id]->baseUomCode ?? '',
            'qty' => Decimal::trim(Decimal::round((string) $r->qty, 6)), 'value' => Decimal::round((string) $r->value, 2),
        ], $rows);
    }

    public function movement(string $from, string $to): array
    {
        $rows = DB::select(
            "SELECT d.doc_type, d.reason, COALESCE(SUM(e.value), 0) AS v, COALESCE(SUM(e.value) FILTER (WHERE e.qty > 0), 0) AS inflow, COALESCE(-SUM(e.value) FILTER (WHERE e.qty < 0), 0) AS outflow
             FROM stock_ledger e JOIN stock_documents d ON d.id = e.document_id
             WHERE e.posting_date BETWEEN ? AND ? AND d.doc_type <> 'transfer' GROUP BY d.doc_type, d.reason ORDER BY d.doc_type, d.reason",
            [$from, $to],
        );
        $in = $out = '0';
        $byReason = [];
        foreach ($rows as $r) {
            $in = Decimal::add($in, (string) $r->inflow);
            $out = Decimal::add($out, (string) $r->outflow);
            $byReason[] = ['type' => (string) $r->doc_type, 'reason' => (string) $r->reason, 'value' => Decimal::round((string) $r->v, 2)];
        }

        return ['inflow' => Decimal::round($in, 2), 'outflow' => Decimal::round($out, 2), 'net' => Decimal::round(Decimal::sub($in, $out), 2), 'by_reason' => $byReason];
    }

    public function belowReorder(int $limit = 10): array
    {
        $all = $this->queries->belowReorder();

        return [
            'count' => count($all),
            'list' => array_map(fn (array $r) => [
                'item_id' => (int) $r['item_id'], 'code' => (string) $r['item_code'], 'name' => (string) $r['item_name'], 'uom' => (string) $r['uom'], 'warehouse' => $r['warehouse'],
                'available' => Decimal::trim(Decimal::round((string) $r['available'], 6)), 'min_qty' => Decimal::trim(Decimal::round((string) $r['min_qty'], 6)), 'shortfall' => Decimal::trim(Decimal::round((string) $r['shortfall'], 6)),
            ], array_slice($all, 0, $limit)),
        ];
    }

    public function expiring(int $days = 30, int $limit = 10): array
    {
        $today = CarbonImmutable::now(config('core.display_timezone'))->toDateString();
        $until = CarbonImmutable::parse($today)->addDays($days)->toDateString();
        $rows = DB::select(
            'SELECT l.item_id, l.lot_no, l.expiry_date, SUM(b.on_hand) AS qty, ROUND(SUM(b.on_hand * c.value / NULLIF(c.qty, 0)), 6) AS v
             FROM lots l JOIN stock_balances b ON b.lot_id = l.id JOIN item_costs c ON c.item_id = l.item_id
             WHERE l.expiry_date IS NOT NULL AND l.expiry_date <= ? GROUP BY l.id, l.item_id, l.lot_no, l.expiry_date HAVING SUM(b.on_hand) > 0 ORDER BY l.expiry_date, l.item_id, l.lot_no',
            [$until],
        );
        $info = $this->items->findMany(array_values(array_unique(array_map(fn ($r) => (int) $r->item_id, $rows))));

        $lots = $expired = 0;
        $value = $expiredValue = '0';
        foreach ($rows as $r) {
            $isExpired = (string) $r->expiry_date < $today;
            $lots += $isExpired ? 0 : 1;
            $expired += $isExpired ? 1 : 0;
            $value = $isExpired ? $value : Decimal::add($value, (string) $r->v);
            $expiredValue = $isExpired ? Decimal::add($expiredValue, (string) $r->v) : $expiredValue;
        }

        return [
            'lots' => $lots, 'value' => Decimal::round($value, 2), 'expired_lots' => $expired, 'expired_value' => Decimal::round($expiredValue, 2),
            'list' => array_map(fn ($r) => [
                'item_id' => (int) $r->item_id, 'code' => $info[(int) $r->item_id]->code ?? '', 'name' => $info[(int) $r->item_id]->name ?? '', 'lot_no' => (string) $r->lot_no,
                'expiry_date' => (string) $r->expiry_date, 'qty' => Decimal::trim(Decimal::round((string) $r->qty, 6)), 'expired' => (string) $r->expiry_date < $today,
            ], array_slice($rows, 0, $limit)),
        ];
    }
}
