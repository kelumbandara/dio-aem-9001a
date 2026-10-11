<?php

namespace App\Modules\MasterData\Http\Controllers\Api;

use App\Modules\MasterData\Actions\SaveItem;
use App\Modules\MasterData\Http\Requests\SaveItemRequest;
use App\Modules\MasterData\Http\Resources\ItemResource;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Support\CodeLookup;
use App\Modules\MasterData\Support\InputMapper;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemController extends Controller
{
    private const WITH = ['category', 'baseUom', 'defaultTaxCode', 'uoms.uom'];

    public function index(Request $request, CodeLookup $codes): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Item::class);

        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'string', 'max:30'],
            'filter.q' => ['sometimes', 'string', 'max:100'],
            'filter.category' => ['sometimes', 'string', 'max:20'],
            'filter.item_type' => ['sometimes', 'in:stock,service,non_stock'],
            'filter.is_active' => ['sometimes', 'in:true,false,1,0'],
            'filter.is_sellable' => ['sometimes', 'in:true,false,1,0'],
            'filter.is_purchasable' => ['sometimes', 'in:true,false,1,0'],
        ]);

        $filter = (array) $request->input('filter', []);
        $sort = (string) $request->query('sort', 'code');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = in_array(ltrim($sort, '-'), ['code', 'name', 'created_at'], true) ? ltrim($sort, '-') : 'code';
        $bool = fn (string $key) => filter_var($filter[$key] ?? null, FILTER_VALIDATE_BOOLEAN);

        $items = Item::query()->with(self::WITH)
            ->when(! empty($filter['q']), function ($q) use ($filter) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($filter['q'])).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(code) like ?', [$term])->orWhereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(barcode) like ?', [$term]));
            })
            ->when(! empty($filter['category']), fn ($q) => $q->where('category_id', $codes->category($filter['category'], 'filter.category')))
            ->when(! empty($filter['item_type']), fn ($q) => $q->where('item_type', $filter['item_type']))
            ->when(isset($filter['is_active']), fn ($q) => $q->where('is_active', $bool('is_active')))
            ->when(isset($filter['is_sellable']), fn ($q) => $q->where('is_sellable', $bool('is_sellable')))
            ->when(isset($filter['is_purchasable']), fn ($q) => $q->where('is_purchasable', $bool('is_purchasable')))
            ->orderBy($column, $direction)->orderBy('id')
            ->paginate((int) $request->query('per_page', 25))->withQueryString();

        return ItemResource::collection($items);
    }

    public function show(Item $item): ItemResource
    {
        $this->authorize('view', $item);

        return new ItemResource($item->load(self::WITH));
    }

    public function store(SaveItemRequest $request, SaveItem $save, InputMapper $map): JsonResponse
    {
        $item = $save($map->item($request->validated()));

        return (new ItemResource($item->load(self::WITH)))->response()->setStatusCode(201)
            ->header('Location', route('api.v1.items.show', $item));
    }

    public function update(SaveItemRequest $request, Item $item, SaveItem $save, InputMapper $map): ItemResource
    {
        $validated = $request->validated();
        $updated = $save($map->item($validated, $item->load('uoms')), $item, (int) $validated['version']);

        return new ItemResource($updated->load(self::WITH));
    }
}
