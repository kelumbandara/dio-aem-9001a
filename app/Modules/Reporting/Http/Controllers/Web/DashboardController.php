<?php

namespace App\Modules\Reporting\Http\Controllers\Web;

use App\Modules\Reporting\Exceptions\ReportingException;
use App\Modules\Reporting\Services\DashboardRegistry;
use App\Modules\Reporting\Services\Dashboards\DashboardBuilder;
use App\Modules\Reporting\Services\DashboardService;
use App\Modules\Reporting\Support\CsvExporter;
use App\Modules\Reporting\Support\PeriodResolver;
use App\Support\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardRegistry $registry): View
    {
        return view('reporting::dashboards.index', ['dashboards' => $registry->availableTo($request->user())]);
    }

    public function show(Request $request, string $dashboard, DashboardRegistry $registry, DashboardService $service): View
    {
        return view('reporting::dashboards.show', $this->data($request, $dashboard, $registry, $service));
    }

    public function print(Request $request, string $dashboard, DashboardRegistry $registry, DashboardService $service): View
    {
        return view('reporting::dashboards.print', $this->data($request, $dashboard, $registry, $service));
    }

    public function csv(Request $request, string $dashboard, DashboardRegistry $registry, DashboardService $service): Response
    {
        $d = $this->data($request, $dashboard, $registry, $service)['d'];
        $name = 'dio-'.$d['key'].'-'.$d['period']->from.'_'.$d['period']->to.'.csv';

        return response(CsvExporter::render($d['title'], $d['period'], $d['compare'], $d), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    /** @return array<string, mixed> */
    private function data(Request $request, string $dashboard, DashboardRegistry $registry, DashboardService $service): array
    {
        $builder = $registry->find($dashboard, $request->user());
        abort_if($builder === null, 404);
        try {
            $d = $service->run($builder, $request);
        } catch (ReportingException $e) {
            return $this->fallback($request, $builder, $service, $e->getMessage());
        }

        return ['d' => $d, 'presets' => PeriodResolver::PRESETS, 'dashboards' => $registry->availableTo($request->user()), 'error' => null];
    }

    /** A bad period typed into the form: show this month and say why. */
    /** @return array<string, mixed> */
    private function fallback(Request $request, DashboardBuilder $builder, DashboardService $service, string $message): array
    {
        $d = $service->run($builder, new Request(['period' => 'this_month']));

        return ['d' => $d, 'presets' => PeriodResolver::PRESETS, 'dashboards' => app(DashboardRegistry::class)->availableTo($request->user()), 'error' => $message];
    }
}
