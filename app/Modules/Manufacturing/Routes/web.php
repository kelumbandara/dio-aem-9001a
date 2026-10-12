<?php

use App\Modules\Manufacturing\Http\Controllers\Web\BomController;
use App\Modules\Manufacturing\Http\Controllers\Web\OrderController;
use App\Modules\Manufacturing\Http\Controllers\Web\SetupController;
use Illuminate\Support\Facades\Route;

// Web screens call the same services as the API: one rule set, two front doors.

Route::middleware(['auth', 'mfa.enrolled'])->prefix('manufacturing')->group(function () {
    Route::get('boms', [BomController::class, 'index'])->name('manufacturing.boms.index');
    Route::get('boms/create', [BomController::class, 'create'])->name('manufacturing.boms.create');
    Route::post('boms', [BomController::class, 'store'])->name('manufacturing.boms.store');
    Route::get('boms/{version}', [BomController::class, 'show'])->name('manufacturing.boms.show');
    Route::get('boms/{version}/edit', [BomController::class, 'edit'])->name('manufacturing.boms.edit');
    Route::put('boms/{version}', [BomController::class, 'update'])->name('manufacturing.boms.update');
    Route::post('boms/{version}/activate', [BomController::class, 'activate'])->name('manufacturing.boms.activate');
    Route::post('boms/{version}/retire', [BomController::class, 'retire'])->name('manufacturing.boms.retire');
    Route::post('boms/{version}/copy', [BomController::class, 'copy'])->name('manufacturing.boms.copy');
    Route::delete('boms/{version}', [BomController::class, 'destroy'])->name('manufacturing.boms.destroy');

    Route::get('orders', [OrderController::class, 'index'])->name('manufacturing.orders.index');
    Route::get('orders/create', [OrderController::class, 'create'])->name('manufacturing.orders.create');
    Route::post('orders', [OrderController::class, 'store'])->name('manufacturing.orders.store');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('manufacturing.orders.show');
    Route::get('orders/{order}/edit', [OrderController::class, 'edit'])->name('manufacturing.orders.edit');
    Route::put('orders/{order}', [OrderController::class, 'update'])->name('manufacturing.orders.update');
    Route::post('orders/{order}/release', [OrderController::class, 'release'])->name('manufacturing.orders.release');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('manufacturing.orders.cancel');
    Route::post('orders/{order}/issue', [OrderController::class, 'issue'])->name('manufacturing.orders.issue');
    Route::post('orders/{order}/return', [OrderController::class, 'return'])->name('manufacturing.orders.return');

    Route::get('setup', [SetupController::class, 'overview'])->name('manufacturing.setup.index');
    Route::get('setup/{entity}', [SetupController::class, 'index'])->name('manufacturing.setup.list');
    Route::get('setup/{entity}/create', [SetupController::class, 'create'])->name('manufacturing.setup.create');
    Route::post('setup/{entity}', [SetupController::class, 'store'])->name('manufacturing.setup.store');
    Route::get('setup/{entity}/{id}/edit', [SetupController::class, 'edit'])->name('manufacturing.setup.edit');
    Route::put('setup/{entity}/{id}', [SetupController::class, 'update'])->name('manufacturing.setup.update');
});
