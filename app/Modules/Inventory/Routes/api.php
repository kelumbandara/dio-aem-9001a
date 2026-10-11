<?php

use App\Modules\Inventory\Http\Controllers\Api\DocumentController;
use App\Modules\Inventory\Http\Controllers\Api\ReorderRuleController;
use App\Modules\Inventory\Http\Controllers\Api\StockController;
use App\Modules\Inventory\Http\Controllers\Api\WarehouseController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 (names api.v1.*) with the `module:inventory` guard.
// Items, units, warehouses and locations are referred to by code, not id.

Route::middleware(['auth:sanctum', 'mfa.enrolled'])->group(function () {
    Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
    Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('idempotent')->name('warehouses.store');
    Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show'])->name('warehouses.show');
    Route::patch('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
    Route::post('warehouses/{warehouse}/locations', [WarehouseController::class, 'addLocation'])->middleware('idempotent')->name('warehouses.locations.store');
    Route::patch('warehouses/{warehouse}/locations/{location}', [WarehouseController::class, 'updateLocation'])->name('warehouses.locations.update');

    Route::get('stock', [StockController::class, 'summary'])->name('stock.summary');
    Route::get('stock/ledger', [StockController::class, 'ledger'])->name('stock.ledger');
    Route::get('stock/lots', [StockController::class, 'lots'])->name('stock.lots');
    Route::get('stock/below-reorder', [StockController::class, 'belowReorder'])->name('stock.below-reorder');
    Route::get('stock/items/{code}', [StockController::class, 'item'])->name('stock.item');

    Route::get('stock-documents', [DocumentController::class, 'index'])->name('stock-documents.index');
    Route::post('stock-documents', [DocumentController::class, 'store'])->middleware('idempotent')->name('stock-documents.store');
    Route::get('stock-documents/{document}', [DocumentController::class, 'show'])->name('stock-documents.show');
    Route::post('stock-documents/{document}/reverse', [DocumentController::class, 'reverse'])->middleware('idempotent')->name('stock-documents.reverse');

    Route::get('reorder-rules', [ReorderRuleController::class, 'index'])->name('reorder-rules.index');
    Route::post('reorder-rules', [ReorderRuleController::class, 'store'])->middleware('idempotent')->name('reorder-rules.store');
    Route::patch('reorder-rules/{rule}', [ReorderRuleController::class, 'update'])->name('reorder-rules.update');
});
