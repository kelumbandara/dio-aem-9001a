<?php

namespace App\Modules\MasterData\Http\Controllers\Web;

use App\Modules\MasterData\Actions\RequestPriceChanges;
use App\Modules\MasterData\Actions\SavePriceList;
use App\Modules\MasterData\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\MasterData\Http\Requests\SavePriceListRequest;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\PriceListItem;
use App\Modules\MasterData\Support\InputMapper;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PriceListController extends Controller
{
    use ReportsBusinessErrors;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PriceList::class);
        $type = in_array($request->query('type'), [PriceList::SALES, PriceList::PURCHASE], true) ? $request->query('type') : null;

        return view('masterdata::price-lists.index', [
            'lists' => PriceList::query()->with('currency')->when($type, fn ($q) => $q->where('type', $type))->orderBy('type')->orderBy('code')->paginate(25)->withQueryString(),
            'type' => $type,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', PriceList::class);

        return view('masterdata::price-lists.form', ['list' => null, 'currencies' => $this->currencies()]);
    }

    public function store(SavePriceListRequest $request, SavePriceList $save, InputMapper $map): RedirectResponse
    {
        return $this->attempt(fn () => redirect()->route('price-lists.show', $save($map->priceList($request->validated())))->with('status', __('masterdata::ui.flash.saved')));
    }

    public function show(PriceList $priceList): View
    {
        $this->authorize('view', $priceList);

        return view('masterdata::price-lists.show', [
            'list' => $priceList->load('currency'),
            'prices' => PriceListItem::query()->with(['item:id,code,name', 'uom:id,code'])->where('price_list_id', $priceList->id)
                ->orderBy('item_id')->orderBy('uom_id')->orderBy('min_qty')->paginate(50),
        ]);
    }

    public function edit(PriceList $priceList): View
    {
        $this->authorize('update', $priceList);

        return view('masterdata::price-lists.form', ['list' => $priceList->load('currency'), 'currencies' => $this->currencies()]);
    }

    public function update(SavePriceListRequest $request, PriceList $priceList, SavePriceList $save, InputMapper $map): RedirectResponse
    {
        return $this->attempt(function () use ($request, $priceList, $save, $map) {
            $data = $request->validated();
            $save($map->priceList($data, $priceList), $priceList, (int) $data['version']);

            return redirect()->route('price-lists.show', $priceList)->with('status', __('masterdata::ui.flash.saved'));
        });
    }

    /**
     * One price per line: item, unit price, then optionally unit and minimum quantity ("WIDGET,125.50,BOX,10").
     * A line with only an item code and an empty price removes that price.
     */
    public function changePrices(Request $request, PriceList $priceList, RequestPriceChanges $change, InputMapper $map): RedirectResponse
    {
        $this->authorize('update', $priceList);
        $request->validate(['lines' => ['required', 'string', 'max:200000']]);

        return $this->attempt(function () use ($request, $priceList, $change, $map) {
            $changes = [];
            foreach (preg_split('/\R/', (string) $request->input('lines')) as $n => $line) {
                if (trim($line) === '') {
                    continue;
                }
                [$item, $price, $uom, $min] = array_pad(array_map('trim', str_getcsv($line, ',', '"', '')), 4, '');
                $changes[] = ['item' => $item, 'unit_price' => $price !== '' ? $price : null, 'uom' => $uom !== '' ? $uom : null, 'min_qty' => $min !== '' ? $min : null];
            }
            if ($changes === []) {
                return back()->withInput()->withErrors(['lines' => __('masterdata::ui.prices.nothing')]);
            }
            if (count($changes) > 1000) {
                return back()->withInput()->withErrors(['lines' => __('masterdata::ui.prices.too_many')]);
            }

            $outcome = $change($priceList, $map->priceChanges($changes), $request->user()->id, 'web');

            return redirect()->route('price-lists.show', $priceList)->with('status', $outcome->applied
                ? __('masterdata::ui.prices.applied', ['count' => count($changes)])
                : __('masterdata::ui.flash.awaiting_approval', ['document' => $outcome->documentNo]));
        });
    }

    /** @return Collection<int, Currency> */
    private function currencies()
    {
        return Currency::query()->where('is_active', true)->orderBy('code')->get(['code', 'name']);
    }
}
