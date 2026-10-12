<?php

namespace App\Modules\AI\Services\Tools;

use App\Modules\AI\Contracts\BusinessTool;
use App\Modules\AI\DTOs\ToolResult;
use App\Modules\Finance\Contracts\FinanceMetrics;
use App\Modules\Inventory\Contracts\StockMetrics;
use App\Modules\Manufacturing\Contracts\ProductionMetrics;
use App\Modules\Procurement\Contracts\PurchasingMetrics;
use App\Modules\Sales\Contracts\SalesMetrics;
use Illuminate\Contracts\Container\Container;

/**
 * Every read tool (risk R0). Each is a thin adapter over a module's read-only metrics contract, the same figures the Reporting
 * dashboards show, so the assistant and the dashboards cannot disagree. Nothing here writes, and nothing takes SQL or a file name.
 */
class ToolCatalog
{
    private const LIMIT = ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 15, 'description' => 'How many rows (default 10).']];

    public function __construct(private readonly Container $container) {}

    /** @return list<BusinessTool> */
    public function all(): array
    {
        return array_merge($this->sales(), $this->purchasing(), $this->stock(), $this->finance(), $this->production(), $this->container->make(WorkTools::class)->all());
    }

    /** @return list<BusinessTool> */
    private function sales(): array
    {
        $m = fn (): SalesMetrics => $this->container->make(SalesMetrics::class);
        $link = fn (string $label) => ['label' => $label, 'url' => route('sales.invoices.index')];

        return [
            new ClosureTool('sales.summary', 'Sales for a period: invoices, net and gross invoiced, credit notes, net sales, average invoice, quotations, confirmed orders, deliveries. Money in LKR, net of VAT unless "gross".', 'sales', 'sales.view',
                ClosureTool::PERIOD, ['from', 'to'], fn (array $i) => new ToolResult($m()->summary($i['from'], $i['to']), [$link('Invoices')])),
            new ClosureTool('sales.top_customers', 'Best customers by net sales in a period (credit notes deducted).', 'sales', 'sales.view',
                ClosureTool::PERIOD + self::LIMIT, ['from', 'to'], function (array $i) use ($m) {
                    $rows = $m()->topCustomers($i['from'], $i['to'], (int) ($i['limit'] ?? 10));

                    return new ToolResult(['customers' => array_map(fn ($r) => ['name' => $r['name'], 'invoices' => $r['invoices'], 'net' => $r['net']], $rows)],
                        array_map(fn ($r) => ['label' => $r['name'], 'url' => route('customers.show', [$r['public_id']])], $rows));
                }),
            new ClosureTool('sales.top_items', 'Best-selling items by net sales in a period.', 'sales', 'sales.view',
                ClosureTool::PERIOD + self::LIMIT, ['from', 'to'], function (array $i) use ($m) {
                    $rows = $m()->topItems($i['from'], $i['to'], (int) ($i['limit'] ?? 10));

                    return new ToolResult(['items' => array_map(fn ($r) => ['code' => $r['code'], 'name' => $r['name'], 'qty' => $r['qty'], 'uom' => $r['uom'], 'net' => $r['net']], $rows)],
                        array_map(fn ($r) => ['label' => $r['name'], 'url' => route('items.show', [$r['code']])], $rows));
                }),
            new ClosureTool('sales.open_orders', 'Confirmed sales orders not yet fully invoiced, and how many are past their required date.', 'sales', 'sales.view', [], [],
                function () use ($m) {
                    $o = $m()->openOrders(10);

                    return new ToolResult(['orders' => $o['orders'], 'to_invoice' => $o['to_invoice'], 'overdue' => $o['overdue'], 'list' => array_map(fn ($r) => array_diff_key($r, ['id' => 1]), $o['list'])],
                        array_map(fn ($r) => ['label' => $r['document_no'], 'url' => route('sales.orders.show', [$r['id']])], $o['list']));
                }),
        ];
    }

    /** @return list<BusinessTool> */
    private function purchasing(): array
    {
        $m = fn (): PurchasingMetrics => $this->container->make(PurchasingMetrics::class);

        return [
            new ClosureTool('purchasing.summary', 'Purchasing for a period: orders approved, receipts, supplier bills, returns, net purchases, price variance. Money in LKR.', 'procurement', 'procurement.view',
                ClosureTool::PERIOD, ['from', 'to'], fn (array $i) => new ToolResult($m()->summary($i['from'], $i['to']), [['label' => 'Supplier bills', 'url' => route('procurement.bills.index')]])),
            new ClosureTool('purchasing.top_suppliers', 'Biggest suppliers by net purchases in a period (returns deducted).', 'procurement', 'procurement.view',
                ClosureTool::PERIOD + self::LIMIT, ['from', 'to'], function (array $i) use ($m) {
                    $rows = $m()->topSuppliers($i['from'], $i['to'], (int) ($i['limit'] ?? 10));

                    return new ToolResult(['suppliers' => array_map(fn ($r) => ['name' => $r['name'], 'bills' => $r['bills'], 'net' => $r['net']], $rows)],
                        array_map(fn ($r) => ['label' => $r['name'], 'url' => route('suppliers.show', [$r['public_id']])], $rows));
                }),
            new ClosureTool('purchasing.open_orders', 'Approved purchase orders still to be received and how many are past their expected date.', 'procurement', 'procurement.view', [], [],
                function () use ($m) {
                    $o = $m()->openOrders(10);

                    return new ToolResult(['orders' => $o['orders'], 'to_receive' => $o['to_receive'], 'overdue' => $o['overdue'], 'list' => array_map(fn ($r) => array_diff_key($r, ['id' => 1]), $o['list'])],
                        array_map(fn ($r) => ['label' => $r['document_no'], 'url' => route('procurement.orders.show', [$r['id']])], $o['list']));
                }),
        ];
    }

    /** @return list<BusinessTool> */
    private function stock(): array
    {
        $m = fn (): StockMetrics => $this->container->make(StockMetrics::class);
        $stock = ['label' => 'Stock', 'url' => ''];

        return [
            new ClosureTool('stock.valuation', 'Stock on hand now valued at average cost: total, by warehouse, and the items holding the most value.', 'inventory', 'inventory.stock.view', [], [],
                fn () => new ToolResult($m()->valuation() + ['top_items' => array_map(fn ($r) => ['code' => $r['code'], 'name' => $r['name'], 'qty' => $r['qty'], 'value' => $r['value']], $m()->topValueItems(10))],
                    [['label' => $stock['label'], 'url' => route('inventory.stock.index')]])),
            new ClosureTool('stock.below_reorder', 'Items below the minimum in their reorder rule, with the shortfall.', 'inventory', 'inventory.stock.view', [], [],
                function () use ($m) {
                    $r = $m()->belowReorder(15);

                    return new ToolResult(['count' => $r['count'], 'list' => $r['list']], array_map(fn ($x) => ['label' => $x['name'], 'url' => route('inventory.stock.item', [$x['code']])], $r['list']));
                }),
            new ClosureTool('stock.expiring', 'Lots with stock that expire within some days (or already have).', 'inventory', 'inventory.stock.view',
                ['days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'description' => 'Look-ahead in days (default 30).']], [],
                function (array $i) use ($m) {
                    $r = $m()->expiring((int) ($i['days'] ?? 30), 15);

                    return new ToolResult($r, [['label' => 'Lots', 'url' => route('inventory.lots.index')]]);
                }),
            new ClosureTool('stock.movement', 'Value of stock that came in and went out in a period, by kind of movement.', 'inventory', 'inventory.stock.view',
                ClosureTool::PERIOD, ['from', 'to'], fn (array $i) => new ToolResult($m()->movement($i['from'], $i['to']), [['label' => 'Stock', 'url' => route('inventory.stock.index')]])),
        ];
    }

    /** @return list<BusinessTool> */
    private function finance(): array
    {
        $m = fn (): FinanceMetrics => $this->container->make(FinanceMetrics::class);
        $reports = fn () => [['label' => 'Finance reports', 'url' => route('finance.reports.index')]];

        return [
            new ClosureTool('finance.profit', 'Income, cost of sales, gross profit, expenses and net profit for a period, from posted journals. LKR.', 'finance', 'finance.report.view',
                ClosureTool::PERIOD, ['from', 'to'], fn (array $i) => new ToolResult($m()->profit($i['from'], $i['to']), $reports())),
            new ClosureTool('finance.receivables', 'What customers owe as at today, by how overdue, and who owes most.', 'finance', 'finance.report.view', [], [],
                fn () => new ToolResult($m()->openItems('receivable', 10), $reports())),
            new ClosureTool('finance.payables', 'What is owed to suppliers as at today, by how overdue, and who is owed most.', 'finance', 'finance.report.view', [], [],
                fn () => new ToolResult($m()->openItems('payable', 10), $reports())),
            new ClosureTool('finance.cash', 'Bank and cash balances as at today and the money received and paid in a period.', 'finance', 'finance.report.view',
                ClosureTool::PERIOD, ['from', 'to'], fn (array $i) => new ToolResult($m()->cash($i['from'], $i['to']), $reports())),
        ];
    }

    /** @return list<BusinessTool> */
    private function production(): array
    {
        $m = fn (): ProductionMetrics => $this->container->make(ProductionMetrics::class);

        return [
            new ClosureTool('production.summary', 'Production for a period: open and late orders, orders completed on time, work in progress, units made, good, rejected, rework and the rates.', 'manufacturing', 'manufacturing.view',
                ClosureTool::PERIOD, ['from', 'to'], fn (array $i) => new ToolResult($m()->summary($i['from'], $i['to']), [['label' => 'Production orders', 'url' => route('manufacturing.orders.index')]])),
            new ClosureTool('production.targets', 'Good units against the per-shift targets in a period, and the shifts furthest below target.', 'manufacturing', 'manufacturing.view',
                ClosureTool::PERIOD, ['from', 'to'], fn (array $i) => new ToolResult($m()->targets($i['from'], $i['to'], 5), [['label' => 'Shift report', 'url' => route('manufacturing.shifts.report')]])),
            new ClosureTool('production.downtime', 'Machine downtime in a period: minutes, by kind, biggest reasons and machines.', 'manufacturing', 'manufacturing.view',
                ClosureTool::PERIOD, ['from', 'to'], fn (array $i) => new ToolResult($m()->downtime($i['from'], $i['to'], 5), [['label' => 'Shift report', 'url' => route('manufacturing.shifts.report')]])),
            new ClosureTool('production.late_orders', 'Production orders past their planned finish date.', 'manufacturing', 'manufacturing.view', [], [],
                function () use ($m) {
                    $rows = $m()->lateOrders(10);

                    return new ToolResult(['count' => count($rows), 'list' => array_map(fn ($r) => array_diff_key($r, ['id' => 1]), $rows)],
                        array_map(fn ($r) => ['label' => $r['order_no'], 'url' => route('manufacturing.orders.show', [$r['id']])], $rows));
                }),
            new ClosureTool('quality.summary', 'Quality in a period: what inspection decided, why units were rejected, and the products that reject most.', 'manufacturing', 'manufacturing.view',
                ClosureTool::PERIOD, ['from', 'to'], fn (array $i) => new ToolResult($m()->quality($i['from'], $i['to'], 5), [['label' => 'Production orders', 'url' => route('manufacturing.orders.index')]])),
        ];
    }
}
