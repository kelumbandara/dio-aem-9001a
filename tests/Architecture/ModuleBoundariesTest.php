<?php

use App\Modules\Approvals\Actions\ExecuteApprovedRequest;
use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\Demo\DemoExpense;
use App\Modules\Approvals\DTOs\ExecutionToken;
use App\Modules\Approvals\Jobs\ExecuteApprovedRequestJob;
use App\Modules\Finance\Approvals\ManualJournalPost;
use App\Modules\Finance\Approvals\SupplierPaymentPost;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Models\SettlementAllocation;
use App\Modules\Inventory\Approvals\StockAdjustmentPost;
use App\Modules\Inventory\Models\ItemCost;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockLedgerEntry;
use App\Modules\MasterData\Approvals\PriceListPriceChange;
use App\Modules\MasterData\Approvals\SupplierBankAccountChange;
use App\Modules\Sales\Models\CreditNote;
use App\Modules\Sales\Models\CreditNoteLine;
use App\Modules\Sales\Models\CreditNoteTax;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\InvoiceLine;
use App\Modules\Sales\Models\InvoiceTax;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderLine;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\QuotationLine;
use Illuminate\Database\Eloquent\Model;
use Tests\Support\Approvals\FakePayment;
use Tests\Support\ModuleBoundaryChecker;

function realChecker(): ModuleBoundaryChecker
{
    return new ModuleBoundaryChecker(
        app_path('Modules'),
        collect(config('modules.modules'))->map(fn ($m) => $m['depends_on'])->all(),
        config('modules.public_namespaces'),
    );
}

it('keeps every real module inside its allowed dependencies and public namespaces', function () {
    expect(realChecker()->violations())->toBe([]);
});

it('only lists dependencies that are themselves declared and never forms a cycle', function () {
    $modules = config('modules.modules');

    foreach ($modules as $key => $module) {
        foreach ($module['depends_on'] as $dependency) {
            expect($modules)->toHaveKey($dependency);
        }
    }

    $visit = function (string $key, array $path) use (&$visit, $modules) {
        expect($path)->not->toContain($key, 'Dependency cycle: '.implode(' -> ', [...$path, $key]));
        foreach ($modules[$key]['depends_on'] as $dep) {
            $visit($dep, [...$path, $key]);
        }
    };
    foreach (array_keys($modules) as $key) {
        $visit($key, []);
    }
});

it('lists each module provider in config and the class exists', function () {
    foreach (config('modules.modules') as $key => $module) {
        expect(class_exists($module['provider']))->toBeTrue("Provider for {$key} missing");
    }
});

arch('contracts are interfaces')->expect('App\Modules\Core\Contracts')->toBeInterfaces();
arch('DTOs are immutable')->expect('App\Modules\Core\DTOs')->toBeFinal()->toBeReadonly();
arch('models are Eloquent models')->expect('App\Modules\Core\Models')->toExtend(Model::class);
arch('no debugging leftovers')->expect(['dd', 'dump', 'var_dump', 'ray', 'die', 'exit'])->not->toBeUsed();
arch('nobody calls env() outside config')->expect('env')->not->toBeUsedIn(['App']);
arch('an approved action can only be executed through the approval engine')
    ->expect(ExecutionToken::class)
    ->toOnlyBeUsedIn([
        ExecuteApprovedRequest::class,
        Approvable::class,
        FakePayment::class,   // test double that implements Approvable
        DemoExpense::class,   // local-only demo action that implements Approvable
        PriceListPriceChange::class,         // real approvable actions (Master Data); each only
        SupplierBankAccountChange::class,    // implements Approvable::execute(), the engine supplies the token
        StockAdjustmentPost::class,          // Inventory's approval-gated adjustment, same pattern
        ManualJournalPost::class,            // Finance's approval-gated manual journal
        SupplierPaymentPost::class,          // Finance's approval-gated supplier payment
    ]);
arch('approvals are never executed from controllers or commands')
    ->expect(ExecuteApprovedRequest::class)
    ->toOnlyBeUsedIn([ExecuteApprovedRequestJob::class, ExecutionToken::class]);
arch('approvables are interfaces, payload hashing never sees floats')
    ->expect(Approvable::class)->toBeInterface();
arch('actions and services do not depend on controllers')
    ->expect(['App\Modules\Core\Actions', 'App\Modules\Core\Services'])
    ->not->toUse('App\Modules\Core\Http\Controllers');

arch('only Inventory writes the stock ledger, balances and item costs')
    ->expect([
        StockLedgerEntry::class,
        StockBalance::class,
        ItemCost::class,
    ])
    ->toOnlyBeUsedIn('App\Modules\Inventory');

arch('only Finance writes journals, receipts/payments and open items')
    ->expect([
        JournalEntry::class,
        JournalLine::class,
        Settlement::class,
        SettlementAllocation::class,
        OpenItem::class,
    ])
    ->toOnlyBeUsedIn('App\Modules\Finance');

arch('Finance never reads Inventory: it learns about stock only from events')
    ->expect('App\Modules\Finance')
    ->not->toUse('App\Modules\Inventory');

arch('only Sales writes quotations, orders, deliveries, invoices and credit notes')
    ->expect([
        Quotation::class, QuotationLine::class, Order::class, OrderLine::class, Delivery::class, DeliveryLine::class,
        Invoice::class, InvoiceLine::class, InvoiceTax::class, CreditNote::class, CreditNoteLine::class, CreditNoteTax::class,
    ])
    ->toOnlyBeUsedIn('App\Modules\Sales');
