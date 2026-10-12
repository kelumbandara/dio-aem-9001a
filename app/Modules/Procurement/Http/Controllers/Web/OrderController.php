<?php

namespace App\Modules\Procurement\Http\Controllers\Web;

use App\Modules\Procurement\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Procurement\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\Procurement\Http\Requests\PurchaseOrderRequest;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Services\FormOptions;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Procurement\Services\PurchasePresenter;
use App\Modules\Procurement\Services\PurchaseReturnService;
use App\Modules\Procurement\Services\SupplierBillService;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    use NeedsPermission, ReportsBusinessErrors;

    public function index(Request $request, PurchasePresenter $presenter): View
    {
        $this->need($request, 'procurement.view');
        $status = in_array($request->query('status'), PurchaseOrder::STATUSES, true) ? $request->query('status') : null;
        $q = trim((string) $request->query('q', ''));
        $page = $this->searchNo(PurchaseOrder::query()->when($status, fn ($query) => $query->where('status', $status)), $q)->orderByDesc('id')->paginate(25)->withQueryString();

        return view('procurement::docs.index', [
            'kind' => 'orders', 'page' => $page, 'rows' => $presenter->headers($page->getCollection()), 'statuses' => PurchaseOrder::STATUSES, 'status' => $status, 'q' => $q,
            'create' => $request->user()->can('procurement.order.manage') ? route('procurement.orders.create') : null,
        ]);
    }

    public function create(Request $request, FormOptions $options): View
    {
        $this->need($request, 'procurement.order.manage');

        return view('procurement::docs.form', ['doc' => null, 'options' => $options->all()]);
    }

    public function store(PurchaseOrderRequest $request, PurchaseOrderService $service): RedirectResponse
    {
        return $this->attempt(function () use ($request, $service) {
            $order = $service->save($request->validated(), null, $request->user()->id);

            return redirect()->route('procurement.orders.show', $order)->with('status', __('procurement::ui.flash.saved', ['document' => $order->document_no]));
        });
    }

    public function show(Request $request, PurchaseOrder $order, PurchasePresenter $presenter): View
    {
        $this->need($request, 'procurement.view');

        return view('procurement::orders.show', ['doc' => $presenter->order($order)]);
    }

    public function print(Request $request, PurchaseOrder $order, PurchasePresenter $presenter): View
    {
        $this->need($request, 'procurement.view');
        abort_unless(in_array($order->status, ['approved', 'completed'], true), 404);

        return view('procurement::orders.print', ['doc' => $presenter->orderPrint($order)]);
    }

    public function edit(Request $request, PurchaseOrder $order, PurchasePresenter $presenter, FormOptions $options): View
    {
        $this->need($request, 'procurement.order.manage');
        abort_unless($order->status === 'draft', 409);

        return view('procurement::docs.form', ['doc' => $presenter->order($order), 'options' => $options->all()]);
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $order, PurchaseOrderService $service): RedirectResponse
    {
        return $this->attempt(function () use ($request, $order, $service) {
            $service->save($request->validated(), $order, $request->user()->id);

            return redirect()->route('procurement.orders.show', $order)->with('status', __('procurement::ui.flash.saved', ['document' => $order->document_no]));
        });
    }

    public function submit(Request $request, PurchaseOrder $order, PurchaseOrderService $service): RedirectResponse
    {
        $this->need($request, 'procurement.order.manage');

        return $this->attempt(function () use ($request, $order, $service) {
            $order = $service->submit($order, $request->user()->id, 'web');
            $key = $order->status === 'approved' ? 'approved' : 'submitted';

            return redirect()->route('procurement.orders.show', $order)->with('status', __("procurement::ui.flash.{$key}", ['document' => $order->document_no]));
        });
    }

    public function withdraw(Request $request, PurchaseOrder $order, PurchaseOrderService $service): RedirectResponse
    {
        $this->need($request, 'procurement.order.manage');

        return $this->attempt(function () use ($request, $order, $service) {
            $service->withdraw($order, $request->user()->id);

            return redirect()->route('procurement.orders.show', $order)->with('status', __('procurement::ui.flash.withdrawn', ['document' => $order->document_no]));
        });
    }

    public function cancel(Request $request, PurchaseOrder $order, PurchaseOrderService $service): RedirectResponse
    {
        $this->need($request, 'procurement.order.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(function () use ($request, $order, $service, $data) {
            $service->cancel($order, $data['reason'], $request->user()->id);

            return redirect()->route('procurement.orders.show', $order)->with('status', __('procurement::ui.flash.cancelled', ['document' => $order->document_no]));
        });
    }

    public function receive(Request $request, PurchaseOrder $order, GoodsReceiptService $service): RedirectResponse
    {
        $this->need($request, 'procurement.receipt.post');
        $data = $request->validate([
            'receipt_date' => ['nullable', 'date_format:Y-m-d'], 'supplier_dn_ref' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array'], 'lines.*.line' => ['required', 'integer'], 'lines.*.qty' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'lines.*.lot' => ['nullable', 'string', 'max:60'], 'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $data['lines'] = $this->picked($data['lines']);

        return $this->attempt(function () use ($request, $order, $service, $data) {
            $receipt = $service->post($order, $data, $request->user()->id);

            return redirect()->route('procurement.receipts.show', $receipt)->with('status', __('procurement::ui.flash.received', ['document' => $receipt->document_no]));
        });
    }

    public function bill(Request $request, PurchaseOrder $order, SupplierBillService $service): RedirectResponse
    {
        $this->need($request, 'procurement.bill.post');
        $data = $request->validate([
            'supplier_invoice_no' => ['required', 'string', 'max:60'], 'bill_date' => ['nullable', 'date_format:Y-m-d'], 'due_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'], 'override_reason' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array'], 'lines.*.line' => ['required', 'integer'], 'lines.*.qty' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'lines.*.unit_price' => ['nullable', 'string', 'regex:/^\d{1,14}(\.\d{1,6})?$/'],
        ]);
        $data['lines'] = $this->picked($data['lines']);

        return $this->attempt(function () use ($request, $order, $service, $data) {
            $bill = $service->create($order, $data, $request->user()->id, $request->user()->can('procurement.bill.override'));

            return redirect()->route('procurement.bills.show', $bill)->with('status', __('procurement::ui.flash.billed', ['document' => $bill->document_no]));
        });
    }

    public function sendBack(Request $request, PurchaseOrder $order, PurchaseReturnService $service): RedirectResponse
    {
        $this->need($request, 'procurement.return.post');
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'], 'return_date' => ['nullable', 'date_format:Y-m-d'], 'bill' => ['nullable', 'string', 'max:60'],
            'lines' => ['required', 'array'], 'lines.*.line' => ['required', 'integer'], 'lines.*.qty' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ]);
        $data['lines'] = $this->picked($data['lines']);

        return $this->attempt(function () use ($request, $order, $service, $data) {
            $return = $service->create($order, $data, $request->user()->id);

            return redirect()->route('procurement.returns.show', $return)->with('status', __('procurement::ui.flash.returned', ['document' => $return->document_no]));
        });
    }

    /**
     * Rows with a quantity; an empty list means "nothing chosen", never "everything".
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function picked(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => trim((string) ($r['qty'] ?? '')) !== '' && (float) $r['qty'] > 0));
        if ($rows === []) {
            abort(back()->withInput()->withErrors(['lines' => __('procurement::ui.errors.pick_lines')]));
        }

        return $rows;
    }
}
