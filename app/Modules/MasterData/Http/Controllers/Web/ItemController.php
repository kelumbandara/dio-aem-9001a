<?php

namespace App\Modules\MasterData\Http\Controllers\Web;

use App\Modules\MasterData\Actions\SaveItem;
use App\Modules\MasterData\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\MasterData\Http\Requests\SaveItemRequest;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\ItemCategory;
use App\Modules\MasterData\Models\TaxCode;
use App\Modules\MasterData\Models\UnitOfMeasure;
use App\Modules\MasterData\Support\InputMapper;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ItemController extends Controller
{
    use ReportsBusinessErrors;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Item::class);
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status', 'active');

        $items = Item::query()->with(['category', 'baseUom'])
            ->when($q !== '', function ($query) use ($q) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($q)).'%';
                $query->where(fn ($w) => $w->whereRaw('lower(code) like ?', [$term])->orWhereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(coalesce(barcode, \'\')) like ?', [$term]));
            })
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('code')->paginate(25)->withQueryString();

        return view('masterdata::items.index', ['items' => $items, 'q' => $q, 'status' => $status]);
    }

    public function create(): View
    {
        $this->authorize('create', Item::class);

        return view('masterdata::items.form', $this->choices() + ['item' => null]);
    }

    public function store(SaveItemRequest $request, SaveItem $save, InputMapper $map): RedirectResponse
    {
        return $this->attempt(function () use ($request, $save, $map) {
            $item = $save($map->item($this->normalise($request->validated())));

            return redirect()->route('items.show', $item)->with('status', __('masterdata::ui.flash.saved'));
        });
    }

    public function show(Item $item): View
    {
        $this->authorize('view', $item);

        return view('masterdata::items.show', ['item' => $item->load(['category', 'baseUom', 'defaultTaxCode', 'uoms.uom'])]);
    }

    public function edit(Item $item): View
    {
        $this->authorize('update', $item);

        return view('masterdata::items.form', $this->choices() + ['item' => $item->load('uoms.uom')]);
    }

    public function update(SaveItemRequest $request, Item $item, SaveItem $save, InputMapper $map): RedirectResponse
    {
        return $this->attempt(function () use ($request, $item, $save, $map) {
            $validated = $this->normalise($request->validated());
            $save($map->item($validated, $item->load('uoms')), $item, (int) $validated['version']);

            return redirect()->route('items.show', $item)->with('status', __('masterdata::ui.flash.saved'));
        });
    }

    /**
     * Unit rows left completely empty are dropped.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        if (isset($data['uoms'])) {
            $data['uoms'] = array_values(array_filter($data['uoms'], fn ($row) => ($row['uom'] ?? '') !== '' || ($row['factor'] ?? '') !== ''));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function choices(): array
    {
        return [
            'categories' => ItemCategory::query()->where('is_active', true)->orderBy('code')->get(['code', 'name']),
            'uoms' => UnitOfMeasure::query()->where('is_active', true)->orderBy('code')->get(['code', 'name']),
            'taxCodes' => TaxCode::query()->where('is_active', true)->orderBy('code')->get(['code', 'name']),
            'types' => Item::TYPES,
        ];
    }
}
