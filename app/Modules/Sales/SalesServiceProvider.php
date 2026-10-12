<?php

namespace App\Modules\Sales;

use App\Modules\Core\DTOs\MenuItem;
use App\Modules\Sales\Contracts\QuotationDrafter;
use App\Modules\Sales\Contracts\SalesMetrics;
use App\Modules\Sales\Services\DatabaseSalesMetrics;
use App\Modules\Sales\Services\EloquentQuotationDrafter;
use App\Support\ModuleServiceProvider;

class SalesServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SalesMetrics::class, DatabaseSalesMetrics::class);
        $this->app->bind(QuotationDrafter::class, EloquentQuotationDrafter::class);
    }

    protected function moduleKey(): string
    {
        return 'sales';
    }

    protected function permissions(): array
    {
        return [
            'sales.view',
            'sales.quotation.manage',
            'sales.order.manage', 'sales.order.confirm', 'sales.credit.override',
            'sales.delivery.post', 'sales.reverse',
            'sales.invoice.post', 'sales.credit_note.post',
        ];
    }

    protected function menu(): array
    {
        return [
            new MenuItem('sales::ui.menu.quotations', 'sales.quotations.index', 'sales.view', 'tag', 100, group: 'sales'),
            new MenuItem('sales::ui.menu.orders', 'sales.orders.index', 'sales.view', 'box', 110, group: 'sales'),
            new MenuItem('sales::ui.menu.deliveries', 'sales.deliveries.index', 'sales.view', 'truck', 120, group: 'sales'),
            new MenuItem('sales::ui.menu.invoices', 'sales.invoices.index', 'sales.view', 'book', 130, group: 'sales'),
            new MenuItem('sales::ui.menu.credit_notes', 'sales.credit-notes.index', 'sales.view', 'swap', 140, group: 'sales'),
        ];
    }
}
