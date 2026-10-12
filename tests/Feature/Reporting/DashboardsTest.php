<?php

use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Core\Services\OutboxRelay;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\MasterData\Actions\SaveCustomer;
use App\Modules\Reporting\Services\DashboardRegistry;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Services\DeliveryService;
use App\Modules\Sales\Services\InvoiceService;
use App\Modules\Sales\Services\OrderService;
use Carbon\CarbonImmutable;

const REPORT_KEYS = ['ceo', 'sales', 'purchasing', 'stores', 'finance', 'production', 'quality'];

function rptEveryone(): array
{
    return ['reporting.executive', 'sales.view', 'procurement.view', 'inventory.stock.view', 'finance.report.view', 'manufacturing.view'];
}

beforeEach(function () {
    config(['sales.prices_include_vat' => false]);
    $this->seed(FinanceSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-04 15:00', 'Asia/Colombo'));
    $this->wh = invWarehouse(['code' => 'MAIN']);
    $this->widget = mdItem(['name' => 'Widget', 'default_tax_code' => 'VAT']);
    invReceive($this->widget, $this->wh, '100', '60');
    $this->customer = app(SaveCustomer::class)(['name' => 'Lanka Traders']);
    $order = app(OrderService::class)->confirm(app(OrderService::class)->save([
        'customer_id' => $this->customer->id, 'warehouse_id' => $this->wh->id, 'lines' => [['item_id' => $this->widget->id, 'qty' => '10', 'unit_price' => '100']],
    ]));
    app(DeliveryService::class)->post($order, []);
    $this->invoice = app(InvoiceService::class)->create($order->refresh(), []);
    app(OutboxRelay::class)->run(2000);
    $this->boss = userWithPermissions(...rptEveryone());
});

describe('who sees which dashboard', function () {
    it('sends signed-out people to sign in', function () {
        foreach (['/reports', '/reports/sales', '/reports/sales/csv', '/reports/sales/print'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->getJson('/api/v1/reports')->assertUnauthorized();
    });

    it('lists only the dashboards the person has the module permission for', function () {
        $salesOnly = userWithPermissions('sales.view');
        $names = fn ($u) => array_map(fn ($b) => $b->key(), app(DashboardRegistry::class)->availableTo($u));
        expect($names($salesOnly))->toBe(['sales'])->and($names($this->boss))->toBe(REPORT_KEYS)->and($names(userWithPermissions('manufacturing.view')))->toBe(['production', 'quality'])
            ->and($names(userWithPermissions('finance.report.view')))->toBe(['finance'])->and($names(userWithPermissions('finance.view')))->toBe([]);
        $this->actingAs($salesOnly)->get('/reports')->assertOk()->assertSee('Sales')->assertDontSee('Owner overview')->assertDontSee('Finance');
        $this->actingAs(userWithPermissions('finance.view'))->get('/reports')->assertOk()->assertSee('No dashboard is available');
    });

    it('answers 404 for a dashboard that is not theirs or does not exist, on every door', function () {
        $salesOnly = userWithPermissions('sales.view');
        foreach (['finance', 'ceo', 'production', 'nonsense'] as $key) {
            $this->actingAs($salesOnly)->get("/reports/{$key}")->assertNotFound();
            $this->actingAs($salesOnly)->get("/reports/{$key}/csv")->assertNotFound();
            $this->actingAs($salesOnly)->get("/reports/{$key}/print")->assertNotFound();
            asApi($salesOnly)->getJson("/api/v1/reports/{$key}")->assertNotFound();
        }
        $this->actingAs($salesOnly)->get('/reports/sales')->assertOk();
    });

    it('keeps the owner overview for those holding the executive permission', function () {
        $everythingElse = userWithPermissions('sales.view', 'procurement.view', 'inventory.stock.view', 'finance.report.view', 'manufacturing.view');
        $this->actingAs($everythingElse)->get('/reports/ceo')->assertNotFound();
        $this->actingAs($this->boss)->get('/reports/ceo')->assertOk();
    });
});

describe('what a dashboard shows', function () {
    it('shows the sales numbers with their comparison and links to the documents behind them', function () {
        $page = $this->actingAs($this->boss)->get('/reports/sales?period=this_month&compare=previous');
        $page->assertOk()->assertSee('Net sales')->assertSee('LKR 1,000.00')->assertSee('Lanka Traders')
            ->assertSee(route('sales.invoices.show', $this->invoice->public_id), false)
            ->assertSee(route('customers.show', $this->customer->public_id), false)
            ->assertSee('2026-11-01')->assertSee('2026-10-28');
        $page->assertSee('data-kpi="net_sales"', false)->assertSee('<svg', false);
    });

    it('shows no comparison when none is asked for', function () {
        $this->actingAs($this->boss)->get('/reports/sales?compare=none')->assertOk()->assertDontSee('compared with')->assertDontSee('was LKR');
        $this->actingAs($this->boss)->get('/reports/sales?compare=previous')->assertOk()->assertSee('compared with 2026-10-28 to 2026-10-31');
    });

    it('draws the earlier period beside the current one when comparing', function () {
        $with = $this->actingAs($this->boss)->get('/reports/sales?compare=previous')->getContent();
        $without = $this->actingAs($this->boss)->get('/reports/sales?compare=none')->getContent();
        expect(substr_count($with, 'class="rp-prev"'))->toBeGreaterThan(0)->and(substr_count($without, 'class="rp-prev"'))->toBe(0)
            ->and(substr_count($with, 'class="rp-cur"'))->toBeGreaterThan(0);
    });

    it('says plainly when there is nothing to show', function () {
        $this->actingAs($this->boss)->get('/reports/sales?period=custom&from=2026-01-01&to=2026-01-31')->assertOk()->assertSee('Nothing to show for this period.');
    });

    it('opens every dashboard for every period and comparison, with data and without', function () {
        foreach (REPORT_KEYS as $key) {
            foreach (['today', 'last_week', 'this_month', 'last_month', 'last_quarter', 'this_year', 'last_year'] as $period) {
                foreach (['previous', 'year', 'none'] as $compare) {
                    $this->actingAs($this->boss)->get("/reports/{$key}?period={$period}&compare={$compare}")->assertOk();
                }
            }
        }
    });

    it('falls back to this month and explains when the typed dates are wrong', function () {
        $this->actingAs($this->boss)->get('/reports/sales?period=custom&from=2026-02-30&to=2026-03-01')->assertOk()->assertSee('Use dates like 2026-11-15.')->assertSee('2026-11-01');
        $this->actingAs($this->boss)->get('/reports/sales?period=custom&from=2026-03-05&to=2026-03-01')->assertOk()->assertSee('cannot end before it starts');
    });

    it('leaves a switched-off module out of the overview and out of the list', function () {
        $registry = app(ModuleRegistry::class);
        $registry->disable('manufacturing');
        $keys = array_map(fn ($b) => $b->key(), app(DashboardRegistry::class)->availableTo($this->boss));
        expect($keys)->not->toContain('production')->and($keys)->not->toContain('quality')->and($keys)->toContain('ceo');
        $this->actingAs($this->boss)->get('/reports/production')->assertNotFound();
        $this->actingAs($this->boss)->get('/reports/ceo')->assertOk()->assertDontSee('Good units made')->assertSee('Net sales');
        $registry->disable('sales');
        $this->actingAs($this->boss)->get('/reports/ceo')->assertOk()->assertDontSee('Net sales');
    });

    it('answers 403 for everything when the reporting module is switched off', function () {
        app(ModuleRegistry::class)->disable('reporting');
        $this->actingAs($this->boss)->get('/reports')->assertForbidden();
        asApi($this->boss)->getJson('/api/v1/reports/sales')->assertForbidden();
    });

    it('builds the owner overview from the same figures as the dashboards themselves', function () {
        $find = fn (array $kpis, string $key) => collect($kpis)->firstWhere('key', $key);
        $ceo = asApi($this->boss)->getJson('/api/v1/reports/ceo?period=this_month&compare=none')->assertOk()->json('data.kpis');
        $sales = asApi($this->boss)->getJson('/api/v1/reports/sales?period=this_month&compare=none')->assertOk()->json('data.kpis');
        $fin = asApi($this->boss)->getJson('/api/v1/reports/finance?period=this_month&compare=none')->assertOk()->json('data.kpis');
        expect($find($ceo, 'net_sales')['value'])->toBe($find($sales, 'net_sales')['value'])->and($find($ceo, 'net_profit')['value'])->toBe($find($fin, 'net_profit')['value'])
            ->and($find($ceo, 'cash_total')['value'])->toBe($find($fin, 'cash_total')['value']);
    });
});

describe('exports', function () {
    it('downloads the dashboard as a CSV file with the right name and headers', function () {
        $res = $this->actingAs($this->boss)->get('/reports/sales/csv?period=this_month&compare=none');
        $res->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('Content-Disposition', 'attachment; filename="dio-sales-2026-11-01_2026-11-04.csv"');
        $csv = $res->getContent();
        expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")->and($csv)->toContain('"Net sales",1000.00,lkr')->and($csv)->toContain('Lanka Traders')->and($csv)->toContain($this->invoice->document_no);
    });

    it('prints a plain page without the menu that opens the print box', function () {
        $res = $this->actingAs($this->boss)->get('/reports/sales/print?period=this_month&compare=previous');
        $res->assertOk()->assertSee('window.print()', false)->assertSee('Printed 2026-11-04 15:00')->assertSee('Net sales')->assertDontSee('Find a page');
    });

    it('does not let the export be used to read a dashboard the person may not open', function () {
        $this->actingAs(userWithPermissions('sales.view'))->get('/reports/finance/csv')->assertNotFound();
    });
});

describe('the API', function () {
    it('lists the dashboards and returns one as data', function () {
        asApi($this->boss)->getJson('/api/v1/reports')->assertOk()->assertJsonCount(7, 'data')->assertJsonPath('data.0.key', 'ceo');
        $json = asApi($this->boss)->getJson('/api/v1/reports/sales?period=this_month&compare=previous')->assertOk()
            ->assertJsonPath('data.period.from', '2026-11-01')->assertJsonPath('data.period.to', '2026-11-04')->assertJsonPath('data.compare.from', '2026-10-28')->json('data');
        $net = collect($json['kpis'])->firstWhere('key', 'net_sales');
        expect($net['value'])->toBe('1000.00')->and($net['previous'])->toBe('0.00')->and($net['trend'])->toBe('up')->and($net['change_pct'])->toBeNull()
            ->and($net['link']['url'])->toBe(route('sales.invoices.index'));
        expect(collect($json['tables'])->firstWhere('id', 'top_customers')['rows'][0]['url'])->toBe(route('customers.show', $this->customer->public_id));
    });

    it('answers a bad period with a clear error rather than a crash', function () {
        asApi($this->boss)->getJson('/api/v1/reports/sales?period=custom&from=2026-02-30&to=2026-03-01')->assertStatus(422)->assertJsonPath('error.code', 'reporting.date_invalid');
        asApi($this->boss)->getJson('/api/v1/reports/sales?period=whenever')->assertStatus(422)->assertJsonPath('error.code', 'reporting.period_invalid');
        asApi($this->boss)->getJson('/api/v1/reports/sales?compare=tomorrow')->assertStatus(422)->assertJsonPath('error.code', 'reporting.compare_invalid');
    });

    it('never changes anything: reading a dashboard writes no rows', function () {
        $before = fn () => [DB::table('sales_invoices')->count(), DB::table('journal_entries')->count(), DB::table('stock_ledger')->count(), DB::table('outbox_events')->count()];
        $was = $before();
        foreach (REPORT_KEYS as $key) {
            $this->actingAs($this->boss)->get("/reports/{$key}")->assertOk();
            $this->actingAs($this->boss)->get("/reports/{$key}/csv")->assertOk();
        }
        expect($before())->toBe($was);
        expect(Invoice::count())->toBe(1);
    });
});
