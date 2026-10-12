<?php

namespace App\Modules\Manufacturing\Http\Controllers\Web;

use App\Modules\Manufacturing\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Manufacturing\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Services\FormOptions;
use App\Modules\Manufacturing\Services\ManufacturingPresenter;
use App\Modules\Manufacturing\Services\MaterialService;
use App\Modules\Manufacturing\Services\ProductionOrderService;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    use NeedsPermission, ReportsBusinessErrors;

    private const QTY = ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'];

    public function index(Request $request, ManufacturingPresenter $presenter, ItemCatalog $items): View
    {
        $this->need($request, 'manufacturing.view');
        $status = in_array($request->query('status'), ProductionOrder::STATUSES, true) ? $request->query('status') : null;
        $q = trim((string) $request->query('q', ''));
        $query = ProductionOrder::query()->when($status, fn ($b) => $b->where('status', $status));
        if ($q !== '') {
            $ids = array_keys($items->search($q, 200));
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($q)).'%';
            $query->where(fn ($w) => $w->whereRaw('lower(order_no) like ?', [$like])->orWhereIn('item_id', $ids === [] ? [0] : $ids));
        }
        $page = $query->orderByDesc('id')->paginate(25)->withQueryString();

        return view('manufacturing::orders.index', [
            'page' => $page, 'rows' => $presenter->orderHeaders($page->getCollection()), 'statuses' => ProductionOrder::STATUSES, 'status' => $status, 'q' => $q,
            'canManage' => $request->user()->can('manufacturing.order.manage'),
        ]);
    }

    public function create(Request $request, FormOptions $options): View
    {
        $this->need($request, 'manufacturing.order.manage');

        return view('manufacturing::orders.form', ['order' => null, 'options' => $options->all(), 'prefill' => (string) $request->query('item', '')]);
    }

    public function store(Request $request, ProductionOrderService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.order.manage');

        return $this->attempt(function () use ($request, $service) {
            $order = $service->save($this->header($request), null, $request->user()->id);

            return redirect()->route('manufacturing.orders.show', $order)->with('status', __('manufacturing::ui.flash.order_saved', ['order' => $order->order_no]));
        });
    }

    public function show(Request $request, ProductionOrder $order, ManufacturingPresenter $presenter): View
    {
        $this->need($request, 'manufacturing.view');

        return view('manufacturing::orders.show', [
            'order' => $presenter->order($order),
            'can' => [
                'manage' => $request->user()->can('manufacturing.order.manage'), 'release' => $request->user()->can('manufacturing.order.release'),
                'issue' => $request->user()->can('manufacturing.issue.post'), 'over' => $request->user()->can('manufacturing.issue.over'),
            ],
        ]);
    }

    public function edit(Request $request, ProductionOrder $order, ManufacturingPresenter $presenter, FormOptions $options): View
    {
        $this->need($request, 'manufacturing.order.manage');
        abort_unless($order->status === 'draft', 409);

        return view('manufacturing::orders.form', ['order' => $presenter->order($order), 'options' => $options->all(), 'prefill' => '']);
    }

    public function update(Request $request, ProductionOrder $order, ProductionOrderService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.order.manage');

        return $this->attempt(function () use ($request, $order, $service) {
            $service->save($this->header($request), $order, $request->user()->id);

            return redirect()->route('manufacturing.orders.show', $order)->with('status', __('manufacturing::ui.flash.order_saved', ['order' => $order->order_no]));
        });
    }

    public function release(Request $request, ProductionOrder $order, ProductionOrderService $service, ItemCatalog $items): RedirectResponse
    {
        $this->need($request, 'manufacturing.order.release');

        return $this->attempt(function () use ($request, $order, $service, $items) {
            $result = $service->release($order, $request->user()->id);
            $message = __('manufacturing::ui.flash.order_released', ['order' => $order->order_no]);
            if ($result['shortages'] !== []) {
                $info = $items->findMany(array_column($result['shortages'], 'item_id'));
                $message .= ' '.__('manufacturing::ui.flash.shortages', ['list' => collect($result['shortages'])->map(fn ($s) => ($info[$s['item_id']]->code ?? $s['item_id']).' ('.__('manufacturing::ui.orders.available').' '.rtrim(rtrim($s['available'], '0'), '.').' / '.rtrim(rtrim($s['required'], '0'), '.').')')->implode(', ')]);
            }

            return redirect()->route('manufacturing.orders.show', $order)->with('status', $message);
        });
    }

    public function cancel(Request $request, ProductionOrder $order, ProductionOrderService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.order.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(function () use ($request, $order, $service, $data) {
            $service->cancel($order, $data['reason'], $request->user()->id);

            return redirect()->route('manufacturing.orders.show', $order)->with('status', __('manufacturing::ui.flash.order_cancelled', ['order' => $order->order_no]));
        });
    }

    public function issue(Request $request, ProductionOrder $order, MaterialService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.issue.post');
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string', 'max:255'], 'lines' => ['required', 'array'],
            'lines.*.material' => ['required', 'integer'], 'lines.*.qty' => self::QTY, 'lines.*.lot_no' => ['nullable', 'string', 'max:60'],
        ]);
        $lines = $this->picked($data['lines']);

        return $this->attempt(function () use ($request, $order, $service, $data, $lines) {
            $result = $service->issue($order, $lines, $request->user()->id, $request->user()->can('manufacturing.issue.over'), $data['reason'] ?? null, $data['date'] ?? null);

            return redirect()->route('manufacturing.orders.show', $order)->with('status', __('manufacturing::ui.flash.issued', ['document' => $result['document_no']]));
        });
    }

    public function return(Request $request, ProductionOrder $order, MaterialService $service): RedirectResponse
    {
        $this->need($request, 'manufacturing.issue.post');
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:255'], 'lines' => ['required', 'array'],
            'lines.*.material' => ['required', 'integer'], 'lines.*.qty' => self::QTY, 'lines.*.lot_no' => ['nullable', 'string', 'max:60'],
        ]);
        $lines = $this->picked($data['lines']);

        return $this->attempt(function () use ($request, $order, $service, $data, $lines) {
            $result = $service->return($order, $lines, $request->user()->id, $data['reason'], $data['date'] ?? null);

            return redirect()->route('manufacturing.orders.show', $order)->with('status', __('manufacturing::ui.flash.returned', ['document' => $result['document_no']]));
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function picked(array $lines): array
    {
        return array_values(array_filter($lines, fn ($l) => trim((string) ($l['qty'] ?? '')) !== ''));
    }

    /** @return array<string, mixed> */
    private function header(Request $request): array
    {
        return $request->validate([
            'item' => ['nullable', 'string', 'max:60'], 'qty' => ['required', 'string', 'max:30'], 'planned_start' => ['nullable', 'date_format:Y-m-d'], 'planned_end' => ['nullable', 'date_format:Y-m-d'],
            'source_warehouse' => ['required', 'string', 'max:30'], 'output_warehouse' => ['required', 'string', 'max:30'], 'line' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'], 'version' => ['nullable', 'integer'],
        ]);
    }
}
