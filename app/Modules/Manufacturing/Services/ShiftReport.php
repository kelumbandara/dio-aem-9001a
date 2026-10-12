<?php

namespace App\Modules\Manufacturing\Services;

use App\Modules\Manufacturing\Models\Shift;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Output and downtime by shift: for each shift date and shift, the units made, how many were rejected or sent to rework,
 * units back from rework, and the minutes machines stood still. Voided entries are left out.
 */
final class ShiftReport
{
    /**
     * @return list<array{date: string, shift: string, shift_name: string, reports: int, produced: string, rejected: string, rework: string, rework_done: string, good: string, downtime_minutes: string, downtime_events: int}>
     */
    public function summary(string $from, string $to, ?int $shiftId = null): array
    {
        $out = [];
        $key = fn (string $date, int $shift) => "{$date}|{$shift}";

        $reports = DB::table('production_reports')->whereNull('voided_at')->whereBetween('report_date', [$from, $to])
            ->when($shiftId, fn ($q) => $q->where('shift_id', $shiftId))
            ->groupBy('report_date', 'shift_id')
            ->selectRaw("report_date, shift_id, count(*) as reports,
                coalesce(sum(case when kind = 'output' then produced_qty else 0 end), 0) as produced,
                coalesce(sum(case when kind = 'output' then reject_qty else 0 end), 0) as rejected,
                coalesce(sum(case when kind = 'output' then rework_qty else 0 end), 0) as rework,
                coalesce(sum(case when kind = 'rework_done' then produced_qty else 0 end), 0) as rework_done")
            ->get();
        foreach ($reports as $r) {
            $out[$key((string) $r->report_date, (int) $r->shift_id)] = [
                'date' => (string) $r->report_date, 'shift_id' => (int) $r->shift_id, 'reports' => (int) $r->reports, 'produced' => (string) $r->produced, 'rejected' => (string) $r->rejected,
                'rework' => (string) $r->rework, 'rework_done' => (string) $r->rework_done, 'downtime_minutes' => '0', 'downtime_events' => 0,
            ];
        }

        $downtime = DB::table('downtime_events')->whereNull('voided_at')->whereBetween('shift_date', [$from, $to])
            ->when($shiftId, fn ($q) => $q->where('shift_id', $shiftId))
            ->groupBy('shift_date', 'shift_id')->selectRaw('shift_date, shift_id, count(*) as events, coalesce(sum(minutes), 0) as minutes')->get();
        foreach ($downtime as $d) {
            $k = $key((string) $d->shift_date, (int) $d->shift_id);
            $out[$k] ??= ['date' => (string) $d->shift_date, 'shift_id' => (int) $d->shift_id, 'reports' => 0, 'produced' => '0', 'rejected' => '0', 'rework' => '0', 'rework_done' => '0'];
            $out[$k]['downtime_minutes'] = (string) $d->minutes;
            $out[$k]['downtime_events'] = (int) $d->events;
        }

        $shifts = Shift::query()->whereIn('id', array_unique(array_column($out, 'shift_id')))->get()->keyBy('id');
        $rows = [];
        foreach ($out as $r) {
            $shift = $shifts[$r['shift_id']];
            $good = Decimal::sub(Decimal::sub((string) $r['produced'], (string) $r['rejected']), (string) $r['rework']);
            $rows[] = [
                'date' => $r['date'], 'shift' => $shift->code, 'shift_name' => $shift->name, 'reports' => $r['reports'],
                'produced' => Decimal::trim(Decimal::round((string) $r['produced'], 6)), 'rejected' => Decimal::trim(Decimal::round((string) $r['rejected'], 6)),
                'rework' => Decimal::trim(Decimal::round((string) $r['rework'], 6)), 'rework_done' => Decimal::trim(Decimal::round((string) $r['rework_done'], 6)),
                'good' => Decimal::trim(Decimal::round($good, 6)), 'downtime_minutes' => Decimal::trim(Decimal::round((string) $r['downtime_minutes'], 2)), 'downtime_events' => $r['downtime_events'],
            ];
        }
        usort($rows, fn (array $a, array $b) => [$b['date'], $a['shift']] <=> [$a['date'], $b['shift']]);

        return $rows;
    }

    /**
     * The individual entries of one shift on one date: output reports and downtime, oldest first.
     *
     * @return array{reports: list<array<string, mixed>>, downtime: list<array<string, mixed>>}
     */
    public function entries(string $date, int $shiftId): array
    {
        $reports = DB::table('production_reports as r')->join('production_orders as o', 'o.id', '=', 'r.order_id')->leftJoin('machines as m', 'm.id', '=', 'r.machine_id')
            ->where('r.report_date', $date)->where('r.shift_id', $shiftId)->orderBy('r.id')
            ->get(['r.public_id as id', 'o.order_no', 'o.item_id', 'r.kind', 'r.produced_qty', 'r.reject_qty', 'r.rework_qty', 'm.code as machine', 'r.notes', 'r.voided_at', 'r.void_reason', 'r.created_at'])
            ->map(fn ($r) => (array) $r)->all();
        $downtime = DB::table('downtime_events as d')->join('machines as m', 'm.id', '=', 'd.machine_id')->join('downtime_reasons as x', 'x.id', '=', 'd.reason_id')->leftJoin('production_orders as o', 'o.id', '=', 'd.order_id')
            ->where('d.shift_date', $date)->where('d.shift_id', $shiftId)->orderBy('d.started_at')
            ->get(['d.public_id as id', 'm.code as machine', 'x.code as reason', 'x.name as reason_name', 'x.category', 'd.started_at', 'd.ended_at', 'd.minutes', 'o.order_no', 'd.notes', 'd.voided_at', 'd.void_reason'])
            ->map(fn ($r) => (array) $r)->all();

        return ['reports' => $reports, 'downtime' => $downtime];
    }
}
