<?php

use App\Modules\MasterData\Http\Controllers\Web\CustomerController;
use App\Modules\MasterData\Http\Controllers\Web\ImportController;
use App\Modules\MasterData\Http\Controllers\Web\ItemController;
use App\Modules\MasterData\Http\Controllers\Web\PriceListController;
use App\Modules\MasterData\Http\Controllers\Web\ReferenceController;
use App\Modules\MasterData\Http\Controllers\Web\SupplierController;
use Illuminate\Support\Facades\Route;

// Web screens call the same Actions as the API (docs/architecture/overview.md): one rule set, two front doors.

Route::middleware(['auth', 'mfa.enrolled'])->group(function () {
    Route::get('items', [ItemController::class, 'index'])->name('items.index');
    Route::get('items/create', [ItemController::class, 'create'])->name('items.create');
    Route::post('items', [ItemController::class, 'store'])->name('items.store');
    Route::get('items/{item}', [ItemController::class, 'show'])->name('items.show');
    Route::get('items/{item}/edit', [ItemController::class, 'edit'])->name('items.edit');
    Route::put('items/{item}', [ItemController::class, 'update'])->name('items.update');

    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('customers/create', [CustomerController::class, 'create'])->name('customers.create');
    Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');

    Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
    Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
    Route::get('suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit');
    Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::post('suppliers/{supplier}/bank-accounts', [SupplierController::class, 'addBankAccount'])->name('suppliers.bank-accounts.store');
    Route::delete('suppliers/{supplier}/bank-accounts/{account}', [SupplierController::class, 'deactivateBankAccount'])->name('suppliers.bank-accounts.deactivate');

    Route::get('price-lists', [PriceListController::class, 'index'])->name('price-lists.index');
    Route::get('price-lists/create', [PriceListController::class, 'create'])->name('price-lists.create');
    Route::post('price-lists', [PriceListController::class, 'store'])->name('price-lists.store');
    Route::get('price-lists/{priceList}', [PriceListController::class, 'show'])->name('price-lists.show');
    Route::get('price-lists/{priceList}/edit', [PriceListController::class, 'edit'])->name('price-lists.edit');
    Route::put('price-lists/{priceList}', [PriceListController::class, 'update'])->name('price-lists.update');
    Route::post('price-lists/{priceList}/prices', [PriceListController::class, 'changePrices'])->name('price-lists.prices.change');

    Route::prefix('masterdata')->group(function () {
        Route::get('reference/{key?}', [ReferenceController::class, 'index'])->name('masterdata.reference.index');
        Route::post('reference/{key}', [ReferenceController::class, 'store'])->name('masterdata.reference.store');
        Route::put('reference/{key}/{id}', [ReferenceController::class, 'update'])->name('masterdata.reference.update');
        Route::post('currencies/{currency:code}/rates', [ReferenceController::class, 'setExchangeRate'])->name('masterdata.currencies.rates.store');
        Route::post('tax-codes/{taxCode}/rates', [ReferenceController::class, 'addTaxRate'])->name('masterdata.tax-codes.rates.store');
        Route::delete('tax-codes/{taxCode}/rates/{rate}', [ReferenceController::class, 'removeTaxRate'])->name('masterdata.tax-codes.rates.destroy');

        Route::get('import', [ImportController::class, 'create'])->name('masterdata.import.create');
        Route::post('import', [ImportController::class, 'store'])->name('masterdata.import.store');
        Route::get('import/template/{kind}', [ImportController::class, 'template'])->name('masterdata.import.template');
    });
});
