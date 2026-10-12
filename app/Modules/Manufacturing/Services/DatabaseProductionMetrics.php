<?php

namespace App\Modules\Manufacturing\Services;

use App\Modules\Manufacturing\Contracts\ProductionMetrics;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseProductionMetrics implements ProductionMetrics
{
    public function __construct(private readonly ItemCatalog $items, private readonly ShiftReport $shifts) {}

    public function summary(string $from, string $to): array
    {
        $today = $this->today();
        $open = DB::selectOne("SELECT COUNT(*) AS n, COUNT(*) FILTER (WHERE status = 'released') AS released, COUNT(*) FILTER (WHERE status = 'in_progress') AS running, COUNT(*) FILTER (WHERE planned_end < ?) AS late FROM production_orders WHERE status IN ('released', 'in_progress')", [$today]);
        $done = DB::selectOne("SELECT COUNT(*) AS n, COUNT(*) FILTER (WHERE (completed_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Colombo')::date <= planned_end) AS on_time FROM production_orders WHERE status = 'completed' AND completed_at IS NOT NULL AND (completed_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Colombo')::date BETWEEN ? AND ?", [$from, $to]);
        $wip = DB::selectOne("SELECT COALESCE(SUM(m.issued_value - m.returned_value), 0) AS v FROM production_order_materials m JOIN production_orders o ON o.id = m.order_id WHERE o.status IN ('released', 'in_progress')");
        $fgHeld = DB::selectOne("SELECT COALESCE(SUM(fg_value), 0) AS v FROM production_orders WHERE status IN ('released', 'in_progress')");
        $out = DB::selectOne("SELECT COALESCE(SUM(produced_qty), 0) AS made, COALESCE(SUM(reject_qty), 0) AS rej, COALESCE(SUM(rework_qty), 0) AS rew FROM production_reports WHERE voided_at IS NULL AND kind = 'output' AND report_date BETWEEN ? AND ?", [$from, $to]);
        $fg = DB::selectOne('SELECT COALESCE(SUM(qty), 0) AS q, COALESCE(SUM(value), 0) AS v FROM finished_goods_receipts WHERE received_on BETWEEN ? AND ?', [$from, $to]);

        $made = (string) $out->made;
        $good = Decimal::sub(Decimal::sub($made, (string) $out->rej), (string) $out->rew);
        $pct = fn (string $part, string $whole): ?string => Decimal::compare($whole, '0') === 0 ? null : Decimal::round(Decimal::div(Decimal::mul($part, '100'), $whole), 1);

        return [
            'open_orders' => (int) $open->n, 'released' => (int) $open->released, 'in_progress' => (int) $open->running, 'late_orders' => (int) $open->late,
            'completed' => (int) $done->n, 'completed_on_time' => (int) $done->on_time, 'on_time_rate' => (int) $done->n === 0 ? null : Decimal::round(Decimal::div((string) ((int) $done->on_time * 100), (string) $done->n), 1),
            'wip_value' => Decimal::round(Decimal::sub((string) $wip->v, (string) $fgHeld->v), 2),
            'produced' => Decimal::trim(Decimal::round($made, 6)), 'good' => Decimal::trim(Decimal::round($good, 6)), 'rejected' => Decimal::trim(Decimal::round((string) $out->rej, 6)), 'rework' => Decimal::trim(Decimal::round((string) $out->rew, 6)),
            'reject_rate' => $pct((string) $out->rej, $made), 'rework_rate' => $pct((string) $out->rew, $made), 'first_pass_yield' => $pct($good, $made),
            'received' => Decimal::trim(Decimal::round((string) $fg->q, 6)), 'received_value' => Decimal::round((string) $fg->v, 2),
        ];
    }

    public function dailyGood(string $from, string $to): array
    {
        $good = [];
        foreach (DB::select("SELECT report_date AS d, SUM(produced_qty - reject_qty - rework_qty) AS v FROM production_reports WHERE voided_at IS NULL AND kind = 'output' AND report_date BETWEEN ? AND ? GROUP BY report_date", [$from, $to]) as $r) {
            $good[(string) $r->d] = (string) $r->v;
        }
        $out = [];
        for ($d = CarbonImmutable::parse($from); $d->lessThanOrEqualTo(CarbonImmutable::parse($to)); $d = $d->addDay()) {
            $out[] = ['date' => $d->toDateString(), 'good' => Decimal::trim(Decimal::round($good[$d->toDateString()] ?? '0', 6))];
        }

        return $out;
    }

    public function downtime(string $from, string $to, int $limit = 5): array
    {
        $total = DB::selectOne('SELECT COALESCE(SUM(minutes), 0) AS m, COUNT(*) AS n FROM downtime_events WHERE voided_at IS NULL AND shift_date BETWEEN ? AND ?', [$from, $to]);
        $cat = DB::select('SELECT r.category, SUM(d.minutes) AS m FROM downtime_events d JOIN downtime_reasons r ON r.id = d.reason_id WHERE d.voided_at IS NULL AND d.shift_date BETWEEN ? AND ? GROUP BY r.category ORDER BY SUM(d.minutes) DESC, r.category', [$from, $to]);
        $reasons = DB::select('SELECT r.code, r.name, r.category, SUM(d.minutes) AS m, COUNT(*) AS n FROM downtime_events d JOIN downtime_reasons r ON r.id = d.reason_id WHERE d.voided_at IS NULL AND d.shift_date BETWEEN ? AND ? GROUP BY r.id, r.code, r.name, r.category ORDER BY SUM(d.minutes) DESC, r.code LIMIT ?', [$from, $to, $limit]);
        $machines = DB::select('SELECT m.code, m.name, SUM(d.minutes) AS mins, COUNT(*) AS n FROM downtime_events d JOIN machines m ON m.id = d.machine_id WHERE d.voided_at IS NULL AND d.shift_date BETWEEN ? AND ? GROUP BY m.id, m.code, m.name ORDER BY SUM(d.minutes) DESC, m.code LIMIT ?', [$from, $to, $limit]);

        return [
            'minutes' => Decimal::trim(Decimal::round((string) $total->m, 2)), 'events' => (int) $total->n,
            'by_category' => array_map(fn ($r) => ['category' => (string) $r->category, 'minutes' => Decimal::trim(Decimal::round((string) $r->m, 2))], $cat),
            'top_reasons' => array_map(fn ($r) => ['code' => (string) $r->code, 'name' => (string) $r->name, 'category' => (string) $r->category, 'minutes' => Decimal::trim(Decimal::round((string) $r->m, 2)), 'events' => (int) $r->n], $reasons),
            'top_machines' => array_map(fn ($r) => ['code' => (string) $r->code, 'name' => (string) $r->name, 'minutes' => Decimal::trim(Decimal::round((string) $r->mins, 2)), 'events' => (int) $r->n], $machines),
        ];
    }

    public function targets(string $from, string $to, int $limit = 5): array
    {
        $target = $good = '0';
        $shifts = 0;
        $below = [];
        foreach ($this->shifts->summary($from, $to) as $row) {
            foreach ($row['items'] as $i) {
                if ($i['target'] === null) {
                    continue;
                }
                $shifts++;
                $target = Decimal::add($target, $i['target']);
                $good = Decimal::add($good, $i['good']);
                $below[] = ['date' => $row['date'], 'shift' => $row['shift'], 'item' => $i['item'], 'good' => $i['good'], 'target' => $i['target'], 'achievement' => (string) $i['achievement']];
            }
        }
        usort($below, fn (array $a, array $b) => Decimal::compare($a['achievement'], $b['achievement'], 1) ?: strcmp($b['date'], $a['date']) ?: strcmp($a['shift'].$a['item'], $b['shift'].$b['item']));

        return [
            'target' => Decimal::trim(Decimal::round($target, 6)), 'good' => Decimal::trim(Decimal::round($good, 6)),
            'achievement' => Decimal::compare($target, '0') === 0 ? null : Decimal::round(Decimal::div(Decimal::mul($good, '100'), $target), 1),
            'shifts' => $shifts, 'worst' => array_slice(array_values(array_filter($below, fn (array $b) => Decimal::compare($b['achievement'], '100', 1) < 0)), 0, $limit),
        ];
    }

    public function quality(string $from, string $to, int $limit = 5): array
    {
        $insp = DB::selectOne('SELECT COALESCE(SUM(accepted_qty), 0) AS a, COALESCE(SUM(rejected_qty), 0) AS r, COALESCE(SUM(rework_qty), 0) AS w FROM quality_inspections WHERE inspected_on BETWEEN ? AND ?', [$from, $to]);
        $reasons = DB::select(
            "SELECT reason, SUM(q) AS q FROM (
                SELECT COALESCE(NULLIF(reject_reason, ''), '-') AS reason, rejected_qty + rework_qty AS q FROM quality_inspections WHERE inspected_on BETWEEN ? AND ? AND rejected_qty + rework_qty > 0
             ) t GROUP BY reason ORDER BY SUM(q) DESC, reason LIMIT ?",
            [$from, $to, $limit],
        );
        $products = DB::select(
            "SELECT o.item_id, SUM(r.produced_qty) AS made, SUM(r.reject_qty) AS rej FROM production_reports r JOIN production_orders o ON o.id = r.order_id
             WHERE r.voided_at IS NULL AND r.kind = 'output' AND r.report_date BETWEEN ? AND ? GROUP BY o.item_id HAVING SUM(r.reject_qty) > 0
             ORDER BY SUM(r.reject_qty) / SUM(r.produced_qty) DESC, o.item_id LIMIT ?",
            [$from, $to, $limit],
        );
        $info = $this->items->findMany(array_map(fn ($r) => (int) $r->item_id, $products));

        return [
            'accepted' => Decimal::trim(Decimal::round((string) $insp->a, 6)), 'rejected' => Decimal::trim(Decimal::round((string) $insp->r, 6)), 'rework' => Decimal::trim(Decimal::round((string) $insp->w, 6)),
            'reasons' => array_map(fn ($r) => ['reason' => (string) $r->reason, 'qty' => Decimal::trim(Decimal::round((string) $r->q, 6))], $reasons),
            'products' => array_map(fn ($r) => [
                'code' => $info[(int) $r->item_id]->code ?? '', 'name' => $info[(int) $r->item_id]->name ?? '', 'produced' => Decimal::trim(Decimal::round((string) $r->made, 6)),
                'rejected' => Decimal::trim(Decimal::round((string) $r->rej, 6)), 'reject_rate' => Decimal::round(Decimal::div(Decimal::mul((string) $r->rej, '100'), (string) $r->made), 1),
            ], $products),
        ];
    }

    public function lateOrders(int $limit = 10): array
    {
        $today = $this->today();
        $rows = DB::select(
            "SELECT o.public_id, o.order_no, o.item_id, o.status, o.planned_end, o.planned_qty, o.accepted_qty, (?::date - o.planned_end) AS late
             FROM production_orders o WHERE o.status IN ('released', 'in_progress') AND o.planned_end < ? ORDER BY o.planned_end, o.id LIMIT ?",
            [$today, $today, $limit],
        );
        $info = $this->items->findMany(array_map(fn ($r) => (int) $r->item_id, $rows));

        return array_map(fn ($r) => [
            'id' => (string) $r->public_id, 'order_no' => (string) $r->order_no, 'item' => $info[(int) $r->item_id]->code ?? '', 'status' => (string) $r->status, 'planned_end' => (string) $r->planned_end,
            'days_late' => (int) $r->late, 'planned_qty' => Decimal::trim(Decimal::round((string) $r->planned_qty, 6)), 'good_qty' => Decimal::trim(Decimal::round((string) $r->accepted_qty, 6)),
        ], $rows);
    }

    private function today(): string
    {
        return CarbonImmutable::now(config('core.display_timezone'))->toDateString();
    }
}
