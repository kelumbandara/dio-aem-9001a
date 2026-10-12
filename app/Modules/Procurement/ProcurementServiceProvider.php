<?php

namespace App\Modules\Procurement;

use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Core\DTOs\MenuItem;
use App\Modules\Procurement\Approvals\PurchaseOrderApprove;
use App\Support\ModuleServiceProvider;

class ProcurementServiceProvider extends ModuleServiceProvider
{
    protected function moduleKey(): string
    {
        return 'procurement';
    }

    protected function permissions(): array
    {
        return [
            'procurement.view',
            'procurement.order.manage',
            'procurement.receipt.post', 'procurement.return.post', 'procurement.reverse',
            'procurement.bill.post', 'procurement.bill.override',
        ];
    }

    protected function menu(): array
    {
        return [
            new MenuItem('procurement::ui.menu.orders', 'procurement.orders.index', 'procurement.view', 'truck', 150),
            new MenuItem('procurement::ui.menu.receipts', 'procurement.receipts.index', 'procurement.view', 'archive', 160),
            new MenuItem('procurement::ui.menu.bills', 'procurement.bills.index', 'procurement.view', 'book', 170),
            new MenuItem('procurement::ui.menu.returns', 'procurement.returns.index', 'procurement.view', 'swap', 180),
        ];
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(ApprovalService::class)->registerAction(PurchaseOrderApprove::class);
    }
}
