<?php

namespace App\Modules\Reporting\Http\Controllers\Api;

use App\Modules\Reporting\Services\DashboardRegistry;
use App\Modules\Reporting\Services\DashboardService;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardRegistry $registry): JsonResponse
    {
        return response()->json(['data' => array_map(fn ($b) => ['key' => $b->key(), 'title' => $b->title(), 'summary' => $b->summary()], $registry->availableTo($request->user()))]);
    }

    public function show(Request $request, string $dashboard, DashboardRegistry $registry, DashboardService $service): JsonResponse
    {
        $builder = $registry->find($dashboard, $request->user());
        abort_if($builder === null, 404);
        $d = $service->run($builder, $request);

        return response()->json(['data' => [
            'key' => $d['key'], 'title' => $d['title'], 'period' => $d['period']->toArray(), 'compare' => $d['compare']?->toArray(),
            'kpis' => $d['kpis'], 'charts' => $d['charts'], 'tables' => $d['tables'],
        ]]);
    }
}
