<?php

use App\Modules\MasterData\Http\Controllers\Api\CustomerController;
use App\Modules\MasterData\Http\Controllers\Api\ItemController;
use App\Modules\MasterData\Http\Controllers\Api\PriceListController;
use App\Modules\MasterData\Http\Controllers\Api\RateController;
use App\Modules\MasterData\Http\Controllers\Api\ReferenceController;
use App\Modules\MasterData\Http\Controllers\Api\SupplierController;
use App\Modules\MasterData\Reference\ReferenceRegistry;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 (names api.v1.*) with the `module:masterdata` guard.
// Related records are referred to by code (KG, VAT, LKR, NET30…), not by id.

Route::middleware(['auth:sanctum', 'mfa.enrolled'])->group(function () {
    Route::get('items', [ItemController::class, 'index'])->name('items.index');
    Route::post('items', [ItemController::class, 'store'])->middleware('idempotent')->name('items.store');
    Route::get('items/{item}', [ItemController::class, 'show'])->name('items.show');
    Route::patch('items/{item}', [ItemController::class, 'update'])->name('items.update');

    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::post('customers', [CustomerController::class, 'store'])->middleware('idempotent')->name('customers.store');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::patch('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');

    Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::post('suppliers', [SupplierController::class, 'store'])->middleware('idempotent')->name('suppliers.store');
    Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
    Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::post('suppliers/{supplier}/bank-accounts', [SupplierController::class, 'addBankAccount'])->middleware('idempotent')->name('suppliers.bank-accounts.store');
    Route::patch('suppliers/{supplier}/bank-accounts/{account}', [SupplierController::class, 'updateBankAccount'])->name('suppliers.bank-accounts.update');
    Route::delete('suppliers/{supplier}/bank-accounts/{account}', [SupplierController::class, 'deactivateBankAccount'])->name('suppliers.bank-accounts.deactivate');

    Route::get('price-lists', [PriceListController::class, 'index'])->name('price-lists.index');
    Route::post('price-lists', [PriceListController::class, 'store'])->middleware('idempotent')->name('price-lists.store');
    Route::get('price-lists/{priceList}', [PriceListController::class, 'show'])->name('price-lists.show');
    Route::patch('price-lists/{priceList}', [PriceListController::class, 'update'])->name('price-lists.update');
    Route::get('price-lists/{priceList}/prices', [PriceListController::class, 'prices'])->name('price-lists.prices');
    Route::post('price-lists/{priceList}/prices', [PriceListController::class, 'changePrices'])->middleware('idempotent')->name('price-lists.prices.change');

    // Currencies are addressed by ISO code; tax codes by public id or code.
    Route::get('currencies/{currency:code}/rates', [RateController::class, 'exchangeRates'])->name('currencies.rates');
    Route::post('currencies/{currency:code}/rates', [RateController::class, 'setExchangeRate'])->name('currencies.rates.store');
    Route::post('tax-codes/{taxCode}/rates', [RateController::class, 'addTaxRate'])->name('tax-codes.rates.store');
    Route::delete('tax-codes/{taxCode}/rates/{rate}', [RateController::class, 'removeTaxRate'])->name('tax-codes.rates.destroy');

    foreach (app(ReferenceRegistry::class)->keys() as $key) {
        Route::get($key, [ReferenceController::class, 'index'])->defaults('resource', $key)->name("{$key}.index");
        Route::post($key, [ReferenceController::class, 'store'])->defaults('resource', $key)->middleware('idempotent')->name("{$key}.store");
        Route::get("{$key}/{id}", [ReferenceController::class, 'show'])->defaults('resource', $key)->name("{$key}.show");
        Route::patch("{$key}/{id}", [ReferenceController::class, 'update'])->defaults('resource', $key)->name("{$key}.update");
    }
});
