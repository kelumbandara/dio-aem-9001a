<?php

namespace App\Modules\MasterData;

use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Core\DTOs\MenuItem;
use App\Modules\MasterData\Approvals\PriceListPriceChange;
use App\Modules\MasterData\Approvals\SupplierBankAccountChange;
use App\Modules\MasterData\Console\ImportCsv;
use App\Modules\MasterData\Contracts\CurrencyConverter;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Modules\MasterData\Contracts\PriceResolver;
use App\Modules\MasterData\Contracts\TaxCalculator;
use App\Modules\MasterData\Contracts\TaxCatalog;
use App\Modules\MasterData\Contracts\UomConverter;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\ItemCategory;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Models\TaxCode;
use App\Modules\MasterData\Models\UnitOfMeasure;
use App\Modules\MasterData\Policies\CustomerPolicy;
use App\Modules\MasterData\Policies\ItemPolicy;
use App\Modules\MasterData\Policies\PriceListPolicy;
use App\Modules\MasterData\Policies\ReferencePolicy;
use App\Modules\MasterData\Policies\SupplierPolicy;
use App\Modules\MasterData\Services\DatabaseCurrencyConverter;
use App\Modules\MasterData\Services\DatabaseItemCatalog;
use App\Modules\MasterData\Services\DatabasePartyDirectory;
use App\Modules\MasterData\Services\DatabasePriceResolver;
use App\Modules\MasterData\Services\DatabaseTaxCalculator;
use App\Modules\MasterData\Services\DatabaseTaxCatalog;
use App\Modules\MasterData\Services\ItemUomConverter;
use App\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

class MasterDataServiceProvider extends ModuleServiceProvider
{
    protected function moduleKey(): string
    {
        return 'masterdata';
    }

    protected function permissions(): array
    {
        return [
            'masterdata.reference.view', 'masterdata.reference.manage',
            'masterdata.item.view', 'masterdata.item.manage',
            'masterdata.customer.view', 'masterdata.customer.manage',
            'masterdata.supplier.view', 'masterdata.supplier.manage',
            'masterdata.supplier.bank.view', 'masterdata.supplier.bank.manage',
            'masterdata.price_list.view', 'masterdata.price_list.manage',
            'masterdata.import',
        ];
    }

    protected function menu(): array
    {
        return [
            new MenuItem('masterdata::ui.menu.items', 'items.index', 'masterdata.item.view', 'box', 100),
            new MenuItem('masterdata::ui.menu.customers', 'customers.index', 'masterdata.customer.view', 'building', 110),
            new MenuItem('masterdata::ui.menu.suppliers', 'suppliers.index', 'masterdata.supplier.view', 'truck', 120),
            new MenuItem('masterdata::ui.menu.price_lists', 'price-lists.index', 'masterdata.price_list.view', 'tag', 130),
            new MenuItem('masterdata::ui.menu.reference', 'masterdata.reference.index', 'masterdata.reference.view', 'database', 140),
            new MenuItem('masterdata::ui.menu.import', 'masterdata.import.create', 'masterdata.import', 'upload', 150),
        ];
    }

    public function register(): void
    {
        $this->app->singleton(ItemCatalog::class, DatabaseItemCatalog::class);
        $this->app->singleton(PartyDirectory::class, DatabasePartyDirectory::class);
        $this->app->singleton(UomConverter::class, ItemUomConverter::class);
        $this->app->singleton(CurrencyConverter::class, DatabaseCurrencyConverter::class);
        $this->app->singleton(TaxCalculator::class, DatabaseTaxCalculator::class);
        $this->app->singleton(TaxCatalog::class, DatabaseTaxCatalog::class);
        $this->app->singleton(PriceResolver::class, DatabasePriceResolver::class);
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([ImportCsv::class]);
        }

        Gate::policy(Item::class, ItemPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(PriceList::class, PriceListPolicy::class);
        foreach ([UnitOfMeasure::class, ItemCategory::class, Currency::class, TaxCode::class, PaymentTerm::class] as $model) {
            Gate::policy($model, ReferencePolicy::class);
        }

        $approvals = $this->app->make(ApprovalService::class);
        $approvals->registerAction(PriceListPriceChange::class);
        $approvals->registerAction(SupplierBankAccountChange::class);
    }
}
