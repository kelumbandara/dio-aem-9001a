<?php

namespace App\Modules\MasterData\Http\Controllers\Api;

use App\Modules\MasterData\Actions\RequestPriceChanges;
use App\Modules\MasterData\Actions\SavePriceList;
use App\Modules\MasterData\Http\Requests\PriceChangesRequest;
use App\Modules\MasterData\Http\Requests\SavePriceListRequest;
use App\Modules\MasterData\Http\Resources\PriceListResource;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\PriceListItem;
use App\Modules\MasterData\Support\CodeLookup;
use App\Modules\MasterData\Support\InputMapper;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PriceListController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PriceList::class);

        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'filter.type' => ['sometimes', 'in:sales,purchase'],
            'filter.is_active' => ['sometimes', 'in:true,false,1,0'],
        ]);
        $filter = (array) $request->input('filter', []);

        return PriceListResource::collection(
            PriceList::query()->with('currency')
                ->when(! empty($filter['type']), fn ($q) => $q->where('type', $filter['type']))
                ->when(isset($filter['is_active']), fn ($q) => $q->where('is_active', filter_var($filter['is_active'], FILTER_VALIDATE_BOOLEAN)))
                ->orderBy('type')->orderBy('code')
                ->paginate((int) $request->query('per_page', 25))->withQueryString(),
        );
    }

    public function show(PriceList $priceList): PriceListResource
    {
        $this->authorize('view', $priceList);

        return new PriceListResource($priceList->load('currency'));
    }

    public function store(SavePriceListRequest $request, SavePriceList $save, InputMapper $map): JsonResponse
    {
        $list = $save($map->priceList($request->validated()));

        return (new PriceListResource($list))->response()->setStatusCode(201)->header('Location', route('api.v1.price-lists.show', $list));
    }

    public function update(SavePriceListRequest $request, PriceList $priceList, SavePriceList $save, InputMapper $map): PriceListResource
    {
        $validated = $request->validated();

        return new PriceListResource($save($map->priceList($validated, $priceList), $priceList, (int) $validated['version']));
    }

    public function prices(Request $request, PriceList $priceList, CodeLookup $codes): JsonResponse
    {
        $this->authorize('view', $priceList);
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:200'], 'filter.item' => ['sometimes', 'string', 'max:40']]);

        $page = PriceListItem::query()->with(['item:id,code,name', 'uom:id,code'])
            ->where('price_list_id', $priceList->id)
            ->when($request->input('filter.item'), fn ($q, $code) => $q->where('item_id', $codes->item($code, 'filter.item')))
            ->orderBy('item_id')->orderBy('uom_id')->orderBy('min_qty')
            ->paginate((int) $request->query('per_page', 50))->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($p) => [
                'item' => $p->item->code, 'item_name' => $p->item->name, 'uom' => $p->uom->code, 'min_qty' => $p->min_qty, 'unit_price' => $p->unit_price,
            ])->values(),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'price_list_version' => $priceList->version],
        ]);
    }

    /** Applies the price changes now, or answers 202 with the approval request when a policy requires approval. */
    public function changePrices(PriceChangesRequest $request, PriceList $priceList, RequestPriceChanges $change, InputMapper $map): JsonResponse
    {
        $outcome = $change($priceList, $map->priceChanges($request->validated('changes')), $request->user()->id, 'api', $request->header('Idempotency-Key'));

        if (! $outcome->applied) {
            return response()->json(['data' => ['approval_request_id' => $outcome->approvalRequestId, 'document_no' => $outcome->documentNo, 'status' => 'pending']], 202);
        }

        return response()->json(['data' => ['status' => 'applied', 'price_list_version' => $priceList->refresh()->version]]);
    }
}
