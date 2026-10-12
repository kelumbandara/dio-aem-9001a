<?php

namespace App\Modules\Manufacturing\Http\Controllers\Web;

use App\Modules\Manufacturing\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Manufacturing\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\Manufacturing\Models\BomVersion;
use App\Modules\Manufacturing\Services\BomQueries;
use App\Modules\Manufacturing\Services\BomService;
use App\Modules\Manufacturing\Services\FormOptions;
use App\Modules\Manufacturing\Services\ManufacturingPresenter;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BomController extends Controller
{
    use NeedsPermission, ReportsBusinessErrors;

    public function index(Request $request, ManufacturingPresenter $presenter, ItemCatalog $items): View
    {
        $this->need($request, 'manufacturing.view');
        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['draft', 'active', 'retired'], true) ? $request->query('status') : null;
        $query = BomVersion::query()->when($status, fn ($b) => $b->where('status', $status));
        if ($q !== '') {
            $ids = array_keys($items->search($q, 200));
            $query->whereIn('item_id', $ids === [] ? [0] : $ids);
        }
        $page = $query->orderBy('item_id')->orderByDesc('version_no')->paginate(25)->withQueryString();

        return view('manufacturing::boms.index', [
            'page' => $page, 'rows' => $presenter->bomHeaders($page->getCollection()), 'q' => $q, 'status' => $status,
            'canManage' => $request->user()->can('manufacturing.bom.manage'),
        ]);
    }

    public function create(Request $request, FormOptions $options): View
    {
        $this->need($request, 'manufacturing.bom.manage');

        return view('manufacturing::boms.form', ['bom' => null, 'options' => $options->all(), 'prefill' => (string) $request->query('item', '')]);
    }

    public function store(Request $request, BomService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.bom.manage');

        return $this->attempt(function () use ($request, $service) {
            $version = $service->save($this->payload($request), null, $request->user()->id);

            return redirect()->route('manufacturing.boms.show', $version)->with('status', __('manufacturing::ui.flash.bom_saved', ['version' => $version->version_no]));
        });
    }

    public function show(Request $request, BomVersion $version, ManufacturingPresenter $presenter, BomQueries $queries, ItemCatalog $items): View
    {
        $this->need($request, 'manufacturing.view');
        $bom = $presenter->bom($version);
        $siblings = BomVersion::query()->where('item_id', $version->item_id)->orderByDesc('version_no')->get();
        $used = $queries->whereUsed($version->item_id);
        $parents = $items->findMany(array_column($used, 'item_id'));

        return view('manufacturing::boms.show', [
            'bom' => $bom, 'versions' => $presenter->bomHeaders($siblings),
            'whereUsed' => array_map(fn (array $u) => ['code' => $parents[$u['item_id']]->code ?? '', 'name' => $parents[$u['item_id']]->name ?? '', 'version_no' => $u['version_no']], $used),
            'canManage' => $request->user()->can('manufacturing.bom.manage'), 'canActivate' => $request->user()->can('manufacturing.bom.activate'),
        ]);
    }

    public function edit(Request $request, BomVersion $version, ManufacturingPresenter $presenter, FormOptions $options): View
    {
        $this->need($request, 'manufacturing.bom.manage');
        abort_unless($version->status === BomVersion::DRAFT, 409);

        return view('manufacturing::boms.form', ['bom' => $presenter->bom($version, false), 'options' => $options->all(), 'prefill' => '']);
    }

    public function update(Request $request, BomVersion $version, BomService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.bom.manage');

        return $this->attempt(function () use ($request, $version, $service) {
            $service->save($this->payload($request), $version, $request->user()->id);

            return redirect()->route('manufacturing.boms.show', $version)->with('status', __('manufacturing::ui.flash.bom_saved', ['version' => $version->version_no]));
        });
    }

    public function activate(Request $request, BomVersion $version, BomService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.bom.activate');

        return $this->attempt(function () use ($request, $version, $service) {
            $service->activate($version, $request->user()->id);

            return redirect()->route('manufacturing.boms.show', $version)->with('status', __('manufacturing::ui.flash.bom_activated', ['version' => $version->version_no]));
        });
    }

    public function retire(Request $request, BomVersion $version, BomService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.bom.activate');

        return $this->attempt(function () use ($request, $version, $service) {
            $service->retire($version, $request->user()->id);

            return redirect()->route('manufacturing.boms.show', $version)->with('status', __('manufacturing::ui.flash.bom_retired', ['version' => $version->version_no]));
        });
    }

    public function copy(Request $request, BomVersion $version, BomService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.bom.manage');

        return $this->attempt(function () use ($request, $version, $service) {
            $copy = $service->copyToDraft($version, $request->user()->id);

            return redirect()->route('manufacturing.boms.edit', $copy)->with('status', __('manufacturing::ui.flash.bom_copied', ['version' => $copy->version_no]));
        });
    }

    public function destroy(Request $request, BomVersion $version, BomService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.bom.manage');

        return $this->attempt(function () use ($version, $service) {
            $service->deleteDraft($version);

            return redirect()->route('manufacturing.boms.index')->with('status', __('manufacturing::ui.flash.bom_deleted'));
        });
    }

    /**
     * The submitted form, with the blank spare rows left out.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $request->validate([
            'item' => ['nullable', 'string', 'max:60'], 'base_qty' => ['nullable', 'string', 'max:30'], 'description' => ['nullable', 'string', 'max:255'], 'version' => ['nullable', 'integer'],
            'components' => ['nullable', 'array', 'max:200'], 'operations' => ['nullable', 'array', 'max:50'],
        ]);
        $keep = fn (array $rows, array $keys) => array_values(array_filter(array_map(fn ($r) => is_array($r) ? $r : [], $rows), fn (array $r) => collect($keys)->contains(fn ($k) => trim((string) ($r[$k] ?? '')) !== '')));

        return [
            'item' => $request->input('item'), 'base_qty' => $request->input('base_qty') ?: '1', 'description' => $request->input('description'), 'version' => $request->input('version'),
            'components' => $keep((array) $request->input('components', []), ['item', 'qty']),
            'operations' => $keep((array) $request->input('operations', []), ['name', 'work_center']),
        ];
    }
}
