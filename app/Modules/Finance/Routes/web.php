<?php

use App\Modules\Finance\Http\Controllers\Web\AccountController;
use App\Modules\Finance\Http\Controllers\Web\JournalController;
use App\Modules\Finance\Http\Controllers\Web\ReportController;
use App\Modules\Finance\Http\Controllers\Web\SetupController;
use App\Modules\Finance\Http\Controllers\Web\SubledgerController;
use Illuminate\Support\Facades\Route;

// Web screens call the same Actions and posters as the API: one rule set, two front doors.

Route::middleware(['auth', 'mfa.enrolled'])->prefix('finance')->group(function () {
    Route::get('accounts', [AccountController::class, 'index'])->name('finance.accounts.index');
    Route::get('accounts/create', [AccountController::class, 'create'])->name('finance.accounts.create');
    Route::post('accounts', [AccountController::class, 'store'])->name('finance.accounts.store');
    Route::get('accounts/{account}', [AccountController::class, 'ledger'])->name('finance.accounts.ledger');
    Route::get('accounts/{account}/edit', [AccountController::class, 'edit'])->name('finance.accounts.edit');
    Route::put('accounts/{account}', [AccountController::class, 'update'])->name('finance.accounts.update');

    Route::get('journals', [JournalController::class, 'index'])->name('finance.journals.index');
    Route::get('journals/new', [JournalController::class, 'create'])->name('finance.journals.create');
    Route::post('journals', [JournalController::class, 'store'])->name('finance.journals.store');
    Route::get('journals/{journal}', [JournalController::class, 'show'])->name('finance.journals.show');
    Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->name('finance.journals.reverse');

    foreach (['receivables' => 'receivable', 'payables' => 'payable'] as $path => $side) {
        $routes = [
            ['get', '', 'index', 'index'], ['get', '/settlements', 'settlements', 'settlements.index'], ['get', '/settlements/new', 'create', 'settlements.create'],
            ['post', '/settlements', 'store', 'settlements.store'], ['get', '/settlements/{settlement}', 'show', 'settlements.show'],
            ['post', '/settlements/{settlement}/allocate', 'allocate', 'settlements.allocate'], ['post', '/settlements/{settlement}/reverse', 'reverse', 'settlements.reverse'],
            ['get', '/parties/{code}', 'ledger', 'ledger'],
        ];
        foreach ($routes as [$verb, $uri, $method, $name]) {
            Route::$verb($path.$uri, [SubledgerController::class, $method])->defaults('side', $side)->name("finance.{$path}.{$name}");
        }
    }

    Route::get('reports', [ReportController::class, 'index'])->name('finance.reports.index');
    Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->name('finance.reports.trial-balance');
    Route::get('reports/general-ledger', [ReportController::class, 'generalLedger'])->name('finance.reports.general-ledger');
    Route::get('reports/profit-and-loss', [ReportController::class, 'profitAndLoss'])->name('finance.reports.profit-and-loss');
    Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->name('finance.reports.balance-sheet');

    Route::get('periods', [SetupController::class, 'periods'])->name('finance.periods.index');
    Route::post('periods/years', [SetupController::class, 'createYear'])->name('finance.periods.years.store');
    Route::post('periods/{period}/{action}', [SetupController::class, 'changePeriod'])->whereIn('action', ['close', 'reopen', 'lock'])->name('finance.periods.change');

    Route::get('setup', [SetupController::class, 'index'])->name('finance.setup.index');
    Route::post('setup/mappings/{key}', [SetupController::class, 'saveMapping'])->name('finance.setup.mappings.save');
    Route::post('setup/banks', [SetupController::class, 'storeBank'])->name('finance.setup.banks.store');
    Route::post('setup/banks/{bank}/toggle', [SetupController::class, 'toggleBank'])->name('finance.setup.banks.toggle');
});
