<?php

use App\Modules\Inventory\Http\Controllers\Web\DocumentController;
use App\Modules\Inventory\Http\Controllers\Web\ReorderController;
use App\Modules\Inventory\Http\Controllers\Web\StockController;
use App\Modules\Inventory\Http\Controllers\Web\WarehouseController;
use Illuminate\Support\Facades\Route;

// Web screens call the same Actions and StockPoster as the API: one rule set, two front doors.

Route::middleware(['auth', 'mfa.enrolled'])->prefix('inventory')->group(function () {
    Route::get('stock', [StockController::class, 'index'])->name('inventory.stock.index');
    Route::get('stock/items/{code}', [StockController::class, 'item'])->name('inventory.stock.item');
    Route::get('ledger', [StockController::class, 'ledger'])->name('inventory.ledger.index');
    Route::get('lots', [StockController::class, 'lots'])->name('inventory.lots.index');

    Route::get('documents', [DocumentController::class, 'index'])->name('inventory.documents.index');
    Route::get('documents/new/{type}', [DocumentController::class, 'create'])->name('inventory.documents.create');
    Route::post('documents', [DocumentController::class, 'store'])->name('inventory.documents.store');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('inventory.documents.show');
    Route::post('documents/{document}/reverse', [DocumentController::class, 'reverse'])->name('inventory.documents.reverse');

    Route::get('reorder', [ReorderController::class, 'index'])->name('inventory.reorder.index');
    Route::post('reorder', [ReorderController::class, 'store'])->name('inventory.reorder.store');
    Route::post('reorder/{rule}', [ReorderController::class, 'update'])->name('inventory.reorder.update');

    Route::get('warehouses', [WarehouseController::class, 'index'])->name('inventory.warehouses.index');
    Route::get('warehouses/create', [WarehouseController::class, 'create'])->name('inventory.warehouses.create');
    Route::post('warehouses', [WarehouseController::class, 'store'])->name('inventory.warehouses.store');
    Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show'])->name('inventory.warehouses.show');
    Route::get('warehouses/{warehouse}/edit', [WarehouseController::class, 'edit'])->name('inventory.warehouses.edit');
    Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('inventory.warehouses.update');
    Route::post('warehouses/{warehouse}/locations', [WarehouseController::class, 'addLocation'])->name('inventory.warehouses.locations.store');
    Route::put('warehouses/{warehouse}/locations/{location}', [WarehouseController::class, 'updateLocation'])->name('inventory.warehouses.locations.update');
});
