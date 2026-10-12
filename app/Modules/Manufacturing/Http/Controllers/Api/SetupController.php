<?php

namespace App\Modules\Manufacturing\Http\Controllers\Api;

use App\Modules\Manufacturing\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Manufacturing\Models\Machine;
use App\Modules\Manufacturing\Models\ProductionLine;
use App\Modules\Manufacturing\Models\WorkCenter;
use App\Modules\Manufacturing\Services\SetupService;
use App\Support\Http\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `entity` is work-centers, lines, machines or shifts. */
class SetupController extends Controller
{
    use NeedsPermission;

    public function index(Request $request, string $entity, SetupService $service): JsonResponse
    {
        $this->need($request, 'manufacturing.view');
        $model = $service->model($entity);

        return response()->json(['data' => $model::query()->orderBy('code')->get()->map(fn (Model $m) => $this->row($entity, $m))->all()]);
    }

    public function store(Request $request, string $entity, SetupService $service): JsonResponse
    {
        $this->need($request, 'manufacturing.setup.manage');

        return response()->json(['data' => $this->row($entity, $service->save($entity, $request->all(), null))], 201);
    }

    public function update(Request $request, string $entity, string $id, SetupService $service): JsonResponse
    {
        $this->need($request, 'manufacturing.setup.manage');
        $model = $service->model($entity)::query()->where('public_id', $id)->firstOrFail();

        return response()->json(['data' => $this->row($entity, $service->save($entity, $request->all(), $model))]);
    }

    /** @return array<string, mixed> */
    private function row(string $entity, Model $m): array
    {
        $row = array_merge(['id' => $m->getAttribute('public_id')], $m->only(['code', 'name', 'description', 'capacity_hours_per_day', 'hourly_rate', 'is_active', 'status', 'category', 'notes', 'start_time', 'end_time', 'days']));
        if ($entity === 'machines') {
            /** @var Machine $m */
            $row['work_center'] = WorkCenter::query()->whereKey($m->work_center_id)->value('code');
            $row['production_line'] = $m->production_line_id ? ProductionLine::query()->whereKey($m->production_line_id)->value('code') : null;
        }

        return $row;
    }
}
