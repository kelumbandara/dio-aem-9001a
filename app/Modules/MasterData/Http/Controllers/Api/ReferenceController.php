<?php

namespace App\Modules\MasterData\Http\Controllers\Api;

use App\Modules\MasterData\Reference\ReferenceRegistry;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** One controller for units, categories, currencies, tax codes and payment terms (see ReferenceDefinition). */
class ReferenceController extends Controller
{
    public function __construct(private readonly ReferenceRegistry $registry) {}

    public function index(Request $request, string $resource): JsonResponse
    {
        $definition = $this->registry->get($resource);
        $this->authorize('viewAny', $definition->modelClass());

        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:200'], 'filter.is_active' => ['sometimes', 'in:true,false,1,0']]);

        $page = $definition->query()
            ->when($request->has('filter.is_active'), fn ($q) => $q->where('is_active', filter_var($request->input('filter.is_active'), FILTER_VALIDATE_BOOLEAN)))
            ->paginate((int) $request->query('per_page', 100))->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($m) => $definition->present($m))->values(),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(string $id, string $resource): JsonResponse
    {
        $definition = $this->registry->get($resource);
        $model = $definition->find($id);
        $this->authorize('view', $model);

        return response()->json(['data' => $definition->present($model)]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        $definition = $this->registry->get($resource);
        $this->authorize('create', $definition->modelClass());

        $data = Validator::make($request->all(), $definition->rules(null))->validate();
        $model = $definition->save($data, null);

        return response()->json(['data' => $definition->present($model->refresh())], 201)
            ->header('Location', url("/api/v1/{$resource}/".$definition->identifier($model)));
    }

    public function update(Request $request, string $id, string $resource): JsonResponse
    {
        $definition = $this->registry->get($resource);
        $model = $definition->find($id);
        $this->authorize('update', $model);

        // PATCH semantics: anything not sent keeps its current value (the code, if not sent, is not re-checked).
        $current = collect($model->only(array_keys($definition->fields())))->except('parent', 'code')->all();
        $data = Validator::make([...$current, ...$request->all()], $definition->rules($model))->validate();
        $data = [...$current, ...$data];

        return response()->json(['data' => $definition->present($definition->save($data, $model)->refresh())]);
    }
}
