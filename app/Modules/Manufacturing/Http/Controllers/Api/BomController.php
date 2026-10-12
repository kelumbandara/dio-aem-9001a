<?php

namespace App\Modules\Manufacturing\Http\Controllers\Api;

use App\Modules\Manufacturing\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Manufacturing\Models\BomVersion;
use App\Modules\Manufacturing\Services\BomService;
use App\Modules\Manufacturing\Services\ManufacturingPresenter;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BomController extends Controller
{
    use NeedsPermission;

    public function index(Request $request, ManufacturingPresenter $presenter, ItemCatalog $items): JsonResponse
    {
        $this->need($request, 'manufacturing.view');
        $request->validate(['filter.status' => ['sometimes', 'in:draft,active,retired'], 'filter.item' => ['sometimes', 'string', 'max:60'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $f = (array) $request->input('filter', []);
        $query = BomVersion::query()->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v));
        if (! empty($f['item'])) {
            $info = $items->findByCode((string) $f['item']);
            $query->where('item_id', $info->id ?? 0);
        }
        $page = $query->orderBy('item_id')->orderByDesc('version_no')->paginate((int) $request->query('per_page', 25))->withQueryString();

        return response()->json([
            'data' => $presenter->bomHeaders($page->getCollection()),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, BomVersion $version, ManufacturingPresenter $presenter): JsonResponse
    {
        $this->need($request, 'manufacturing.view');

        return response()->json(['data' => $presenter->bom($version)]);
    }

    public function store(Request $request, BomService $service, ManufacturingPresenter $presenter): JsonResponse
    {
        $this->need($request, 'manufacturing.bom.manage');
        $version = $service->save($this->payload($request, true), null, $request->user()->id);

        return response()->json(['data' => $presenter->bom($version, false)], 201)->header('Location', route('api.v1.boms.show', $version));
    }

    public function update(Request $request, BomVersion $version, BomService $service, ManufacturingPresenter $presenter): JsonResponse
    {
        $this->need($request, 'manufacturing.bom.manage');

        return response()->json(['data' => $presenter->bom($service->save($this->payload($request, false), $version, $request->user()->id), false)]);
    }

    public function activate(Request $request, BomVersion $version, BomService $service, ManufacturingPresenter $presenter): JsonResponse
    {
        $this->need($request, 'manufacturing.bom.activate');

        return response()->json(['data' => $presenter->bom($service->activate($version, $request->user()->id))]);
    }

    public function retire(Request $request, BomVersion $version, BomService $service, ManufacturingPresenter $presenter): JsonResponse
    {
        $this->need($request, 'manufacturing.bom.activate');

        return response()->json(['data' => $presenter->bom($service->retire($version, $request->user()->id))]);
    }

    public function copy(Request $request, BomVersion $version, BomService $service, ManufacturingPresenter $presenter): JsonResponse
    {
        $this->need($request, 'manufacturing.bom.manage');
        $copy = $service->copyToDraft($version, $request->user()->id);

        return response()->json(['data' => $presenter->bom($copy, false)], 201)->header('Location', route('api.v1.boms.show', $copy));
    }

    public function destroy(Request $request, BomVersion $version, BomService $service): JsonResponse
    {
        $this->need($request, 'manufacturing.bom.manage');
        $service->deleteDraft($version);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $creating): array
    {
        return $request->validate([
            'item' => [$creating ? 'required' : 'prohibited', 'string', 'max:60'], 'base_qty' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'], 'description' => ['nullable', 'string', 'max:255'], 'version' => ['nullable', 'integer'],
            'components' => ['required', 'array', 'min:1', 'max:200'], 'components.*.item' => ['required', 'string', 'max:60'], 'components.*.qty' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'components.*.uom' => ['nullable', 'string', 'max:20'], 'components.*.scrap_pct' => ['nullable', 'string', 'regex:/^\d{1,2}(\.\d{1,3})?$/'], 'components.*.notes' => ['nullable', 'string', 'max:255'],
            'operations' => ['nullable', 'array', 'max:50'], 'operations.*.op_no' => ['nullable', 'integer', 'min:1'], 'operations.*.name' => ['required', 'string', 'max:150'], 'operations.*.work_center' => ['required', 'string', 'max:30'],
            'operations.*.machine' => ['nullable', 'string', 'max:30'], 'operations.*.setup_minutes' => ['nullable', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/'], 'operations.*.run_minutes' => ['nullable', 'string', 'regex:/^\d{1,8}(\.\d{1,4})?$/'],
            'operations.*.notes' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
