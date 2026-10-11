<?php

use App\Modules\Finance\Contracts\OpenItemPoster;
use App\Modules\Finance\Database\Seeders\FinanceSeeder;
use App\Modules\Finance\DTOs\InvoicePosting;
use App\Modules\Finance\DTOs\JournalLineData;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Services\FinanceReconciler;
use App\Modules\MasterData\Actions\SaveCustomer;
use App\Modules\MasterData\Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Real parallelism against the committed test database: separate PHP processes settle the same invoice at the same
 * moment. "An invoice is never over-settled" cannot be shown inside one transaction, so the data is committed and
 * truncated afterwards.
 */
beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    cleanFinanceData();
    (new ReferenceDataSeeder)->run();
    (new FinanceSeeder)->run();
});

afterEach(fn () => cleanFinanceData());

function cleanFinanceData(): void
{
    DB::unprepared('TRUNCATE TABLE users, accounts, fiscal_years, customers, suppliers, units_of_measure, item_categories, currencies, tax_codes, payment_terms, price_lists, number_sequences, outbox_events, audit_logs, settings RESTART IDENTITY CASCADE');
}

it('never settles an invoice twice over, however many receipts arrive at once', function () {
    $customer = app(SaveCustomer::class)(['name' => 'Lanka Traders']);
    $bank = BankAccount::whereHas('account', fn ($q) => $q->where('code', '1155'))->first();
    $today = now('Asia/Colombo')->toDateString();
    app(OpenItemPoster::class)->postInvoice(new InvoicePosting('receivable', $customer->id, 'INV-1', $today, $today, [JournalLineData::credit('1000', 'sales_revenue')], 'sales_invoice', '1'));
    $invoice = OpenItem::sole();

    $startAt = microtime(true) + 3.0;   // every worker must have booted before the gun goes off
    $processes = [];
    for ($i = 0; $i < 6; $i++) {
        $p = new Process([PHP_BINARY, base_path('tests/Support/settle-worker.php'), (string) $customer->id, (string) $bank->id, (string) $invoice->id, '100', '4', (string) $startAt],
            base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database')]);
        $p->start();
        $processes[] = $p;
    }

    $posted = $refused = 0;
    foreach ($processes as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getErrorOutput().$p->getOutput());
        [$a, $b] = array_map('intval', explode(':', trim($p->getOutput())));
        $posted += $a;
        $refused += $b;
    }

    expect($posted)->toBe(10)->and($refused)->toBe(14)   // 24 attempts at 100 each against an invoice of 1000
        ->and($invoice->fresh()->open_amount)->toBe('0.0000')->and(DB::table('settlements')->count())->toBe(10)
        ->and(DB::table('settlement_allocations')->sum('amount'))->toEqual(1000);
    expect(app(FinanceReconciler::class)->discrepancies())->toBe([]);
});
