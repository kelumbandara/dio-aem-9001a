<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Exceptions\ReportingException;
use App\Modules\Reporting\Services\Dashboards\DashboardBuilder;
use App\Modules\Reporting\Support\Period;
use App\Modules\Reporting\Support\PeriodResolver;
use Illuminate\Http\Request;

/** Reads the period and comparison from a request (web and API alike) and builds the dashboard. */
class DashboardService
{
    public function __construct(private readonly PeriodResolver $periods) {}

    /**
     * @return array{key: string, title: string, period: Period, compare: Period|null, preset: string, compare_mode: string, kpis: list<array<string, mixed>>, charts: list<array<string, mixed>>, tables: list<array<string, mixed>>}
     *
     * @throws ReportingException
     */
    public function run(DashboardBuilder $builder, Request $request): array
    {
        $preset = (string) ($request->query('period') ?: ($request->query('from') && $request->query('to') ? 'custom' : 'this_month'));
        $mode = (string) ($request->query('compare') ?: 'previous');
        $period = $this->periods->resolve($preset, $request->query('from'), $request->query('to'));
        $compare = $this->periods->comparison($period, $mode);

        return ['key' => $builder->key(), 'title' => $builder->title(), 'period' => $period, 'compare' => $compare, 'preset' => $preset, 'compare_mode' => $mode] + $builder->build($period, $compare);
    }
}
