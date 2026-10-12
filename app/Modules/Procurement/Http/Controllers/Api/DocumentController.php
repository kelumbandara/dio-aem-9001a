<?php

namespace App\Modules\Procurement\Http\Controllers\Api;

use App\Modules\Procurement\Http\Controllers\Concerns\NeedsPermission;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\PurchaseReturn;
use App\Modules\Procurement\Models\SupplierBill;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Services\PurchasePresenter;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Goods receipts, supplier bills and purchase returns: read, plus reversing a receipt. */
class DocumentController extends Controller
{
    use NeedsPermission;

    public function receipts(Request $request, PurchasePresenter $presenter): JsonResponse
    {
        return $this->list($request, $presenter, GoodsReceipt::query());
    }

    public function receipt(Request $request, GoodsReceipt $receipt, PurchasePresenter $presenter): JsonResponse
    {
        $this->need($request, 'procurement.view');

        return response()->json(['data' => $presenter->receipt($receipt)]);
    }

    public function reverseReceipt(Request $request, GoodsReceipt $receipt, GoodsReceiptService $service, PurchasePresenter $presenter): JsonResponse
    {
        $this->need($request, 'procurement.reverse');
        $this->need($request, 'procurement.receipt.post');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $presenter->receipt($service->reverse($receipt, $data['note'] ?? null, $request->user()->id))]);
    }

    public function bills(Request $request, PurchasePresenter $presenter): JsonResponse
    {
        return $this->list($request, $presenter, SupplierBill::query(), 'supplier_invoice_no');
    }

    public function bill(Request $request, SupplierBill $bill, PurchasePresenter $presenter): JsonResponse
    {
        $this->need($request, 'procurement.view');

        return response()->json(['data' => $presenter->bill($bill)]);
    }

    public function returns(Request $request, PurchasePresenter $presenter): JsonResponse
    {
        return $this->list($request, $presenter, PurchaseReturn::query());
    }

    public function purchaseReturn(Request $request, PurchaseReturn $return, PurchasePresenter $presenter): JsonResponse
    {
        $this->need($request, 'procurement.view');

        return response()->json(['data' => $presenter->purchaseReturn($return)]);
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<*>  $query */
    private function list(Request $request, PurchasePresenter $presenter, $query, string $column = 'document_no'): JsonResponse
    {
        $this->need($request, 'procurement.view');
        $request->validate(['filter.q' => ['sometimes', 'string', 'max:100'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = $this->searchNo($query, (string) $request->input('filter.q', ''), $column)->orderByDesc('id')->paginate((int) $request->query('per_page', 25))->withQueryString();

        return response()->json([
            'data' => $presenter->headers($page->getCollection()),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }
}
