<?php

namespace App\Modules\Procurement\Services;

use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * "Goods received, not yet invoiced" per order line, worked out from Procurement's own documents: what receipts put
 * into it (at the order's cost), less what returns took out, less what bills have cleared (net of debit notes).
 * Used to clear exactly what is left when a bill completes a line, so the account never keeps rounding dust.
 */
final class GrniQueries
{
    public function remaining(int $orderLineId): string
    {
        $received = (string) DB::table('goods_receipt_lines as l')->join('goods_receipts as r', 'r.id', '=', 'l.receipt_id')
            ->where('l.order_line_id', $orderLineId)->where('r.status', 'posted')->sum('l.value');
        $returned = (string) DB::table('purchase_return_lines')->where('order_line_id', $orderLineId)->where('goods_returned', true)->sum('grni_value');
        $billed = (string) DB::table('supplier_bill_lines')->where('order_line_id', $orderLineId)->sum('grni_amount');
        $debited = (string) DB::table('purchase_return_lines')->where('order_line_id', $orderLineId)->sum('grni_amount');

        return Decimal::round(Decimal::sub(Decimal::sub($received, $returned), Decimal::sub($billed, $debited)), 4);
    }

    /** Net value billed for an order line, less debit notes. */
    public function netBilled(int $orderLineId): string
    {
        $billed = (string) DB::table('supplier_bill_lines')->where('order_line_id', $orderLineId)->sum('net_amount');
        $debited = (string) DB::table('purchase_return_lines')->where('order_line_id', $orderLineId)->sum('net_amount');

        return Decimal::round(Decimal::sub($billed, $debited), 4);
    }
}
