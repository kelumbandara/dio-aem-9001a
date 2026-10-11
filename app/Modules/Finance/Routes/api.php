<?php

use App\Modules\Finance\Http\Controllers\Api\AccountController;
use App\Modules\Finance\Http\Controllers\Api\JournalController;
use App\Modules\Finance\Http\Controllers\Api\ReportController;
use App\Modules\Finance\Http\Controllers\Api\SetupController;
use App\Modules\Finance\Http\Controllers\Api\SubledgerController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 (names api.v1.*) with the `module:finance` guard.
// Accounts, parties and bank accounts are referred to by code or public id, never by internal id.

Route::middleware(['auth:sanctum', 'mfa.enrolled'])->group(function () {
    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('accounts', [AccountController::class, 'store'])->middleware('idempotent')->name('accounts.store');
    Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
    Route::patch('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');

    Route::get('account-mappings', [SetupController::class, 'mappings'])->name('mappings.index');
    Route::put('account-mappings/{key}', [SetupController::class, 'setMapping'])->name('mappings.set');
    Route::get('bank-accounts', [SetupController::class, 'bankAccounts'])->name('bank-accounts.index');
    Route::post('bank-accounts', [SetupController::class, 'storeBank'])->middleware('idempotent')->name('bank-accounts.store');
    Route::patch('bank-accounts/{bank}', [SetupController::class, 'updateBank'])->name('bank-accounts.update');
    Route::get('fiscal-years', [SetupController::class, 'years'])->name('fiscal-years.index');
    Route::post('fiscal-years', [SetupController::class, 'createYear'])->middleware('idempotent')->name('fiscal-years.store');
    Route::post('fiscal-periods/{period}/{action}', [SetupController::class, 'changePeriod'])->whereIn('action', ['close', 'reopen', 'lock'])->name('fiscal-periods.change');

    Route::get('journals', [JournalController::class, 'index'])->name('journals.index');
    Route::post('journals', [JournalController::class, 'store'])->middleware('idempotent')->name('journals.store');
    Route::get('journals/{journal}', [JournalController::class, 'show'])->name('journals.show');
    Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->middleware('idempotent')->name('journals.reverse');

    foreach (['receivables' => 'receivable', 'payables' => 'payable'] as $path => $side) {
        $routes = [
            ['get', 'open-items', 'openItems', 'open-items', false], ['get', 'ageing', 'ageing', 'ageing', false], ['get', 'tie-out', 'tieOut', 'tie-out', false], ['get', 'unallocated', 'unallocated', 'unallocated', false],
            ['get', 'parties/{code}/ledger', 'ledger', 'ledger', false], ['get', 'settlements', 'settlements', 'settlements.index', false], ['post', 'settlements', 'store', 'settlements.store', true],
            ['get', 'settlements/{settlement}', 'showSettlement', 'settlements.show', false], ['post', 'settlements/{settlement}/allocate', 'allocate', 'settlements.allocate', false],
            ['post', 'settlements/{settlement}/reverse', 'reverse', 'settlements.reverse', true],
        ];
        foreach ($routes as [$verb, $uri, $method, $name, $idempotent]) {
            $route = Route::$verb("{$path}/{$uri}", [SubledgerController::class, $method])->defaults('side', $side)->name("{$path}.{$name}");
            if ($idempotent) {
                $route->middleware('idempotent');
            }
        }
    }

    Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->name('reports.trial-balance');
    Route::get('reports/general-ledger', [ReportController::class, 'generalLedger'])->name('reports.general-ledger');
    Route::get('reports/profit-and-loss', [ReportController::class, 'profitAndLoss'])->name('reports.profit-and-loss');
    Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
});
