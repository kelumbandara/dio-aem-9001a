<?php

namespace App\Modules\Procurement\Http\Controllers\Web;

use App\Modules\Procurement\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Procurement\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\PurchaseReturn;
use App\Modules\Procurement\Models\SupplierBill;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Services\PurchasePresenter;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Goods receipts, supplier bills and purchase returns. */
class DocumentController extends Controller
{
    use NeedsPermission, ReportsBusinessErrors;

    public function receipts(Request $request, PurchasePresenter $presenter): View
    {
        return $this->list($request, $presenter, 'receipts', GoodsReceipt::query());
    }

    public function bills(Request $request, PurchasePresenter $presenter): View
    {
        return $this->list($request, $presenter, 'bills', SupplierBill::query(), 'supplier_invoice_no');
    }

    public function returns(Request $request, PurchasePresenter $presenter): View
    {
        return $this->list($request, $presenter, 'returns', PurchaseReturn::query());
    }

    public function receipt(Request $request, GoodsReceipt $receipt, PurchasePresenter $presenter): View
    {
        $this->need($request, 'procurement.view');

        return view('procurement::receipts.show', ['doc' => $presenter->receipt($receipt)]);
    }

    public function reverseReceipt(Request $request, GoodsReceipt $receipt, GoodsReceiptService $service): RedirectResponse
    {
        $this->need($request, 'procurement.reverse');
        $this->need($request, 'procurement.receipt.post');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(function () use ($request, $receipt, $service, $data) {
            $service->reverse($receipt, $data['note'] ?? null, $request->user()->id);

            return redirect()->route('procurement.receipts.show', $receipt)->with('status', __('procurement::ui.flash.receipt_reversed', ['document' => $receipt->document_no]));
        });
    }

    public function bill(Request $request, SupplierBill $bill, PurchasePresenter $presenter): View
    {
        $this->need($request, 'procurement.view');

        return view('procurement::bills.show', ['doc' => $presenter->bill($bill)]);
    }

    public function purchaseReturn(Request $request, PurchaseReturn $return, PurchasePresenter $presenter): View
    {
        $this->need($request, 'procurement.view');

        return view('procurement::returns.show', ['doc' => $presenter->purchaseReturn($return)]);
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<*>  $query */
    private function list(Request $request, PurchasePresenter $presenter, string $kind, $query, string $column = 'document_no'): View
    {
        $this->need($request, 'procurement.view');
        $q = trim((string) $request->query('q', ''));
        $page = $this->searchNo($query, $q, $column)->orderByDesc('id')->paginate(25)->withQueryString();

        return view('procurement::docs.index', ['kind' => $kind, 'page' => $page, 'rows' => $presenter->headers($page->getCollection()), 'statuses' => [], 'status' => null, 'q' => $q, 'create' => null]);
    }
}
