<?php

namespace App\Modules\Inventory;

use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Core\DTOs\MenuItem;
use App\Modules\Inventory\Approvals\StockAdjustmentPost;
use App\Modules\Inventory\Console\ReconcileStock;
use App\Modules\Inventory\Contracts\StockAvailability;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\Contracts\StockReserver;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Policies\StockDocumentPolicy;
use App\Modules\Inventory\Policies\WarehousePolicy;
use App\Modules\Inventory\Services\DatabaseStockAvailability;
use App\Modules\Inventory\Services\DatabaseStockReserver;
use App\Modules\Inventory\Services\StockPostingService;
use App\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;

class InventoryServiceProvider extends ModuleServiceProvider
{
    protected function moduleKey(): string
    {
        return 'inventory';
    }

    protected function permissions(): array
    {
        return [
            'inventory.warehouse.view', 'inventory.warehouse.manage',
            'inventory.stock.view',
            'inventory.receive', 'inventory.issue', 'inventory.transfer', 'inventory.adjust', 'inventory.reverse',
            'inventory.reorder.manage',
        ];
    }

    protected function menu(): array
    {
        return [
            new MenuItem('inventory::ui.menu.stock', 'inventory.stock.index', 'inventory.stock.view', 'archive', 200),
            new MenuItem('inventory::ui.menu.documents', 'inventory.documents.index', 'inventory.stock.view', 'swap', 210),
            new MenuItem('inventory::ui.menu.lots', 'inventory.lots.index', 'inventory.stock.view', 'clock', 220),
            new MenuItem('inventory::ui.menu.reorder', 'inventory.reorder.index', 'inventory.stock.view', 'bell', 230),
            new MenuItem('inventory::ui.menu.warehouses', 'inventory.warehouses.index', 'inventory.warehouse.view', 'building', 240),
        ];
    }

    public function register(): void
    {
        $this->app->singleton(StockPostingService::class);
        $this->app->singleton(StockPoster::class, StockPostingService::class);
        $this->app->singleton(StockAvailability::class, DatabaseStockAvailability::class);
        $this->app->singleton(StockReserver::class, DatabaseStockReserver::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Warehouse::class, WarehousePolicy::class);
        Gate::policy(StockDocument::class, StockDocumentPolicy::class);

        $this->app->make(ApprovalService::class)->registerAction(StockAdjustmentPost::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileStock::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('inventory:reconcile')->dailyAt('02:30')->withoutOverlapping(30);
        });
    }
}
