<?php

use App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\FinanceReconciler;
use App\Modules\Finance\Services\FinancialReports;
use App\Modules\MasterData\Actions\Reference\AddTaxRate;
use App\Modules\MasterData\Actions\SaveCustomer;
use App\Modules\MasterData\Models\TaxCode;
use App\Modules\Sales\Services\DeliveryService;
use App\Modules\Sales\Services\InvoiceService;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Sales\Services\SalesCreditNoteService;
use Spatie\Permission\Models\Role;

/** The owner's rule: all sales prices include VAT; SSCL is then added on the net value of the non-exempt lines. */
beforeEach(function () {
    $this->seed(FinanceSeeder::class);
    TaxCode::where('code', 'SSCL')->update(['is_active' => true]);
    app(AddTaxRate::class)(TaxCode::where('code', 'SSCL')->first(), '2.5', '2024-01-01');
    $this->wh = invWarehouse(['code' => 'MAIN']);
    $this->item = mdItem(['name' => 'Widget']);   // no tax code on the item: sold with VAT anyway
    invReceive($this->item, $this->wh, '100', '40');
    $this->customer = app(SaveCustomer::class)(['name' => 'Lanka Traders']);
    $this->orders = app(OrderService::class);
    $this->bal = fn (string $code) => app(FinancialReports::class)->balanceOf(Account::where('code', $code)->value('id'));
});

it('is the default: a typed price of 118 is 100 net plus 18 VAT, and SSCL goes on the 100', function () {
    expect(config('sales.prices_include_vat'))->toBeTrue();
    $order = $this->orders->save(['customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->item->id, 'qty' => '10', 'unit_price' => '118']]]);

    expect($order->prices_include_vat)->toBeTrue()
        ->and($order->only(['net_total', 'vat_total', 'levy_total', 'gross_total']))->toBe(['net_total' => '1000.0000', 'vat_total' => '180.0000', 'levy_total' => '25.0000', 'gross_total' => '1205.0000']);
});

it('rounds on the line total so the customer pays exactly the typed price', function () {
    // 3 x 100.00 incl. VAT = 300.00 -> net 254.24, VAT 45.76 (not 3 x rounded unit amounts)
    $order = $this->orders->save(['customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->item->id, 'qty' => '3', 'unit_price' => '100']]]);
    expect($order->net_total)->toBe('254.2400')->and($order->vat_total)->toBe('45.7600')
        ->and(bcadd($order->net_total, $order->vat_total, 2))->toBe('300.00');
});

it('invoices in slices that add up to the typed line total, and posts net revenue with VAT and SSCL', function () {
    $order = $this->orders->confirm($this->orders->save(['customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->item->id, 'qty' => '7', 'unit_price' => '99.99']]]));
    $deliveries = app(DeliveryService::class);
    $invoices = app(InvoiceService::class);

    $deliveries->post($order, ['lines' => [['line' => 1, 'qty' => '3']]]);
    $first = $invoices->create($order->refresh(), []);
    $deliveries->post($order->refresh(), []);
    $second = $invoices->create($order->refresh(), []);

    // 7 x 99.99 = 699.93 incl. VAT in total across both invoices
    expect(bcadd(bcadd($first->net_total, $first->vat_total, 2), bcadd($second->net_total, $second->vat_total, 2), 2))->toBe('699.93')
        ->and($first->prices_include_vat)->toBeTrue()->and($order->refresh()->status)->toBe('completed');
    expect(bcadd($first->net_total, $second->net_total, 2))->toBe($order->net_total === null ? '0' : bcadd($order->net_total, '0', 2));
    expect(app(FinanceReconciler::class)->discrepancies())->toBe([]);

    // credit everything: all accounts net to zero again
    $notes = app(SalesCreditNoteService::class);
    $notes->create($first, ['reason' => 'Return']);
    $notes->create($second, ['reason' => 'Return']);
    foreach (['1125', '2000', '1524', '1525'] as $code) {
        expect(($this->bal)($code))->toBe('0.0000', $code);
    }
});

it('treats exempt items as having no VAT in the price and no SSCL', function () {
    $exempt = mdItem(['name' => 'Books', 'default_tax_code' => 'EXEMPT']);
    $order = $this->orders->save(['customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $exempt->id, 'qty' => '1', 'unit_price' => '500']]]);
    expect($order->only(['net_total', 'vat_total', 'levy_total', 'gross_total']))->toBe(['net_total' => '500.0000', 'vat_total' => '0.0000', 'levy_total' => '0.0000', 'gross_total' => '500.0000']);
});

it('keeps old documents as they were when the setting changes', function () {
    config(['sales.prices_include_vat' => false]);
    $net = $this->orders->save(['customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->item->id, 'qty' => '1', 'unit_price' => '100']]]);
    config(['sales.prices_include_vat' => true]);
    $net = $this->orders->save(['customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->item->id, 'qty' => '1', 'unit_price' => '200']]], $net);

    expect($net->prices_include_vat)->toBeFalse()->and($net->net_total)->toBe('200.0000')->and($net->vat_total)->toBe('36.0000');
});

it('gives sales staff the selling permissions but not invoicing, reversing or the credit override', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $granted = Role::findByName('sales', 'web')->permissions->pluck('name');

    expect($granted)->toContain('sales.view', 'sales.quotation.manage', 'sales.order.manage', 'sales.order.confirm', 'sales.delivery.post', 'masterdata.item.view', 'inventory.stock.view')
        ->not->toContain('sales.invoice.post', 'sales.credit_note.post', 'sales.reverse', 'sales.credit.override', 'masterdata.item.manage', 'inventory.receive')
        ->and(Role::findByName('admin', 'web')->permissions->pluck('name'))->toContain('sales.credit.override');
});
