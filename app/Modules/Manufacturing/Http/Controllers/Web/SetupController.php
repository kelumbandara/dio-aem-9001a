<?php

namespace App\Modules\Manufacturing\Http\Controllers\Web;

use App\Modules\Manufacturing\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Manufacturing\Models\Machine;
use App\Modules\Manufacturing\Models\ProductionLine;
use App\Modules\Manufacturing\Models\WorkCenter;
use App\Modules\Manufacturing\Services\SetupService;
use App\Support\Http\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Work centres, production lines, machines and shifts share these screens; the fields differ per entity. */
class SetupController extends Controller
{
    use NeedsPermission;

    public function overview(Request $request): View
    {
        $this->need($request, 'manufacturing.view');

        return view('manufacturing::setup.overview', [
            'entities' => collect(SetupService::ENTITIES)->map(fn (string $model, string $key) => ['key' => $key, 'count' => $model::query()->count()])->values()->all(),
            'canManage' => $request->user()->can('manufacturing.setup.manage'),
        ]);
    }

    public function index(Request $request, string $entity, SetupService $service): View
    {
        $this->need($request, 'manufacturing.view');
        $model = $service->model($entity);

        return view('manufacturing::setup.list', [
            'entity' => $entity, 'rows' => $model::query()->orderBy('code')->get()->map(fn (Model $m) => $this->row($entity, $m))->all(),
            'canManage' => $request->user()->can('manufacturing.setup.manage'),
        ]);
    }

    public function create(Request $request, string $entity, SetupService $service): View
    {
        $this->need($request, 'manufacturing.setup.manage');
        $service->model($entity);

        return view('manufacturing::setup.form', ['entity' => $entity, 'row' => null, 'options' => $this->options()]);
    }

    public function store(Request $request, string $entity, SetupService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.setup.manage');
        $service->save($entity, $this->input($request), null);

        return redirect()->route('manufacturing.setup.list', $entity)->with('status', __('manufacturing::ui.flash.setup_saved'));
    }

    public function edit(Request $request, string $entity, string $id, SetupService $service): View
    {
        $this->need($request, 'manufacturing.setup.manage');
        $model = $service->model($entity)::query()->where('public_id', $id)->firstOrFail();

        return view('manufacturing::setup.form', ['entity' => $entity, 'row' => $this->row($entity, $model), 'options' => $this->options()]);
    }

    public function update(Request $request, string $entity, string $id, SetupService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.setup.manage');
        $model = $service->model($entity)::query()->where('public_id', $id)->firstOrFail();
        $service->save($entity, $this->input($request), $model);

        return redirect()->route('manufacturing.setup.list', $entity)->with('status', __('manufacturing::ui.flash.setup_saved'));
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        $data = $request->except(['_token', '_method']);
        // checkboxes: absent = off; the shift's seven day boxes become one "1111100" string
        $data['is_active'] = $request->boolean('is_active');
        if ($request->has('day')) {
            $data['days'] = collect(range(1, 7))->map(fn (int $d) => in_array((string) $d, (array) $request->input('day'), true) ? '1' : '0')->implode('');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function row(string $entity, Model $m): array
    {
        $row = ['id' => $m->getAttribute('public_id')];
        foreach (['code', 'name', 'description', 'capacity_hours_per_day', 'hourly_rate', 'is_active', 'status', 'notes', 'start_time', 'end_time', 'days'] as $k) {
            $row[$k] = $m->getAttribute($k);
        }
        if ($entity === 'machines') {
            /** @var Machine $m */
            $row['work_center'] = WorkCenter::query()->whereKey($m->work_center_id)->value('code');
            $row['production_line'] = $m->production_line_id ? ProductionLine::query()->whereKey($m->production_line_id)->value('code') : null;
        }
        foreach (['capacity_hours_per_day', 'hourly_rate'] as $k) {
            if ($row[$k] !== null) {
                $row[$k] = rtrim(rtrim((string) $row[$k], '0'), '.');
            }
        }
        foreach (['start_time', 'end_time'] as $k) {
            if ($row[$k] !== null) {
                $row[$k] = substr((string) $row[$k], 0, 5);
            }
        }

        return $row;
    }

    /** @return array{workCenters: list<string>, lines: list<string>} */
    private function options(): array
    {
        return [
            'workCenters' => WorkCenter::query()->where('is_active', true)->orderBy('code')->pluck('code')->all(),
            'lines' => ProductionLine::query()->where('is_active', true)->orderBy('code')->pluck('code')->all(),
        ];
    }
}
