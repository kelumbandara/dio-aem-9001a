<?php

namespace App\Modules\Inventory\Http\Controllers\Web;

use App\Modules\Inventory\Actions\PostStockMovement;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\Inventory\Http\Requests\PostStockDocumentRequest;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\DocumentPresenter;
use App\Modules\Inventory\Services\MovementInput;
use App\Modules\Inventory\Services\StockPostingService;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DocumentController extends Controller
{
    use ReportsBusinessErrors;

    public function index(Request $request, DocumentPresenter $presenter): View
    {
        $this->authorize('viewAny', StockDocument::class);
        $type = in_array($request->query('type'), StockDocument::TYPES, true) ? $request->query('type') : null;
        $q = trim((string) $request->query('q', ''));

        $page = StockDocument::query()
            ->when($type, fn ($query) => $query->where('doc_type', $type))
            ->when($q !== '', function ($query) use ($q) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($q)).'%';
                $query->where(fn ($w) => $w->whereRaw('lower(document_no) like ?', [$term])->orWhereRaw("lower(coalesce(reference, '')) like ?", [$term]));
            })
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return view('inventory::documents.index', ['page' => $page, 'rows' => $presenter->headers($page->getCollection()), 'type' => $type, 'q' => $q]);
    }

    public function create(Request $request, string $type, ItemCatalog $items): View
    {
        abort_unless(in_array($type, StockDocument::TYPES, true), 404);
        abort_unless($request->user()->can('post', [StockDocument::class, $type]), 403);

        return view('inventory::documents.form', [
            'type' => $type, 'reasons' => StockPostingService::REASONS[$type],
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(['code', 'name', 'uses_locations']),
            'itemCodes' => collect($items->search('', 300))->filter(fn ($i) => $i->isStocked() && $i->isActive)->map(fn ($i) => ['code' => $i->code, 'name' => $i->name, 'lot' => $i->tracksLots])->values(),
        ]);
    }

    public function store(PostStockDocumentRequest $request, MovementInput $input, PostStockMovement $post): RedirectResponse
    {
        return $this->attempt(function () use ($request, $input, $post) {
            $outcome = $post($input->movement($request->validated(), $request->user()->id), $request->user()->id, 'web');

            if (! $outcome->isPosted()) {
                return redirect()->route('inventory.documents.index')->with('status', __('inventory::ui.flash.awaiting_approval', ['document' => $outcome->approvalDocumentNo]));
            }
            $document = StockDocument::query()->findOrFail($outcome->posted->documentId);

            return redirect()->route('inventory.documents.show', $document)->with('status', __('inventory::ui.flash.posted', ['document' => $document->document_no]));
        });
    }

    public function show(StockDocument $document, DocumentPresenter $presenter): View
    {
        $this->authorize('view', $document);

        return view('inventory::documents.show', ['doc' => $presenter->full($document), 'model' => $document]);
    }

    public function reverse(Request $request, StockDocument $document, StockPoster $poster): RedirectResponse
    {
        $this->authorize('reverse', $document);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(function () use ($request, $document, $poster, $data) {
            $reversal = $poster->reverse($document->id, $data['note'] ?? null, $request->user()->id);
            $new = StockDocument::query()->findOrFail($reversal->documentId);

            return redirect()->route('inventory.documents.show', $new)->with('status', __('inventory::ui.flash.reversed', ['document' => $document->document_no]));
        });
    }
}
