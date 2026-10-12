<?php

namespace App\Modules\Procurement\Services;

use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Modules\Procurement\Contracts\PurchasingMetrics;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabasePurchasingMetrics implements PurchasingMetrics
{
    public function __construct(private readonly PartyDirectory $parties) {}

    public function summary(string $from, string $to): array
    {
        $po = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(net_total), 0) AS v FROM purchase_orders WHERE approved_at IS NOT NULL AND (approved_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Colombo')::date BETWEEN ? AND ? AND status IN ('approved', 'completed')", [$from, $to]);
        $gr = DB::selectOne("SELECT COUNT(DISTINCT r.id) AS n, COALESCE(SUM(l.value), 0) AS v FROM goods_receipts r LEFT JOIN goods_receipt_lines l ON l.receipt_id = r.id WHERE r.receipt_date BETWEEN ? AND ? AND r.status = 'posted'", [$from, $to]);
        $bill = DB::selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(net_total), 0) AS net, COALESCE(SUM(gross_total), 0) AS gross, COALESCE(SUM(variance_total), 0) AS variance FROM supplier_bills WHERE bill_date BETWEEN ? AND ?', [$from, $to]);
        $ret = DB::selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(net_total), 0) AS net FROM purchase_returns WHERE return_date BETWEEN ? AND ?', [$from, $to]);

        return [
            'orders_approved' => (int) $po->n, 'orders_value' => Decimal::round((string) $po->v, 2),
            'receipts' => (int) $gr->n, 'receipts_value' => Decimal::round((string) $gr->v, 2),
            'bills' => (int) $bill->n, 'bills_net' => Decimal::round((string) $bill->net, 2), 'bills_gross' => Decimal::round((string) $bill->gross, 2),
            'returns' => (int) $ret->n, 'returns_net' => Decimal::round((string) $ret->net, 2),
            'net_purchases' => Decimal::round(Decimal::sub((string) $bill->net, (string) $ret->net), 2), 'price_variance' => Decimal::round((string) $bill->variance, 2),
        ];
    }

    public function dailyNet(string $from, string $to): array
    {
        $net = [];
        foreach (DB::select('SELECT bill_date AS d, SUM(net_total) AS v FROM supplier_bills WHERE bill_date BETWEEN ? AND ? GROUP BY bill_date', [$from, $to]) as $r) {
            $net[(string) $r->d] = (string) $r->v;
        }
        foreach (DB::select('SELECT return_date AS d, SUM(net_total) AS v FROM purchase_returns WHERE return_date BETWEEN ? AND ? GROUP BY return_date', [$from, $to]) as $r) {
            $net[(string) $r->d] = Decimal::sub($net[(string) $r->d] ?? '0', (string) $r->v);
        }

        $out = [];
        for ($d = CarbonImmutable::parse($from); $d->lessThanOrEqualTo(CarbonImmutable::parse($to)); $d = $d->addDay()) {
            $out[] = ['date' => $d->toDateString(), 'net' => Decimal::round($net[$d->toDateString()] ?? '0', 2)];
        }

        return $out;
    }

    public function topSuppliers(string $from, string $to, int $limit = 10): array
    {
        $rows = DB::select(
            'SELECT supplier_id, SUM(net) AS net, SUM(n) AS bills FROM (
                SELECT supplier_id, net_total AS net, 1 AS n FROM supplier_bills WHERE bill_date BETWEEN ? AND ?
                UNION ALL SELECT supplier_id, -net_total, 0 FROM purchase_returns WHERE return_date BETWEEN ? AND ?
             ) t GROUP BY supplier_id HAVING SUM(net) <> 0 ORDER BY SUM(net) DESC, supplier_id LIMIT ?',
            [$from, $to, $from, $to, $limit],
        );

        return array_map(function ($r) {
            $s = $this->parties->supplier((int) $r->supplier_id);

            return ['supplier_id' => (int) $r->supplier_id, 'public_id' => $s->publicId ?? '', 'code' => $s->code ?? '', 'name' => $s->name ?? '', 'bills' => (int) $r->bills, 'net' => Decimal::round((string) $r->net, 2)];
        }, $rows);
    }

    public function openOrders(int $limit = 10): array
    {
        $today = CarbonImmutable::now(config('core.display_timezone'))->toDateString();
        // what is still to arrive: ordered less received net of returns (a return re-opens its quantity, as it does for receiving), at the line's net price
        $rows = DB::select(
            "SELECT o.public_id, o.document_no, o.supplier_id, o.expected_date,
                    ROUND(COALESCE(SUM(CASE WHEN l.qty > 0 THEN l.net_amount * GREATEST(l.qty - (l.qty_received - l.qty_returned), 0) / l.qty ELSE 0 END), 0), 6) AS to_receive
             FROM purchase_orders o JOIN purchase_order_lines l ON l.document_id = o.id
             WHERE o.status = 'approved' GROUP BY o.id ORDER BY to_receive DESC, o.id",
        );
        $open = array_values(array_filter($rows, fn ($r) => Decimal::compare((string) $r->to_receive, '0') > 0));

        $total = '0';
        $overdue = 0;
        foreach ($open as $r) {
            $total = Decimal::add($total, (string) $r->to_receive);
            $overdue += $r->expected_date !== null && (string) $r->expected_date < $today ? 1 : 0;
        }

        return [
            'orders' => count($open), 'to_receive' => Decimal::round($total, 2), 'overdue' => $overdue,
            'list' => array_map(fn ($r) => [
                'id' => (string) $r->public_id, 'document_no' => (string) $r->document_no, 'supplier' => $this->parties->supplier((int) $r->supplier_id)->name ?? '',
                'expected_date' => $r->expected_date === null ? null : (string) $r->expected_date, 'to_receive' => Decimal::round((string) $r->to_receive, 2),
                'overdue' => $r->expected_date !== null && (string) $r->expected_date < $today,
            ], array_slice($open, 0, $limit)),
        ];
    }
}
