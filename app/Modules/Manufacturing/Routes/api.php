<?php

use App\Modules\Manufacturing\Http\Controllers\Api\BomController;
use App\Modules\Manufacturing\Http\Controllers\Api\OrderController;
use App\Modules\Manufacturing\Http\Controllers\Api\SetupController;
use App\Modules\Manufacturing\Http\Controllers\Api\ShopfloorController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 (names api.v1.*) with the `module:manufacturing` guard.
// Items, units, warehouses, work centres, machines and lines are referred to by code, material lines by their line number.

Route::middleware(['auth:sanctum', 'mfa.enrolled'])->group(function () {
    Route::get('boms', [BomController::class, 'index'])->name('boms.index');
    Route::post('boms', [BomController::class, 'store'])->middleware('idempotent')->name('boms.store');
    Route::get('boms/{version}', [BomController::class, 'show'])->name('boms.show');
    Route::put('boms/{version}', [BomController::class, 'update'])->name('boms.update');
    Route::post('boms/{version}/activate', [BomController::class, 'activate'])->middleware('idempotent')->name('boms.activate');
    Route::post('boms/{version}/retire', [BomController::class, 'retire'])->middleware('idempotent')->name('boms.retire');
    Route::post('boms/{version}/copy', [BomController::class, 'copy'])->middleware('idempotent')->name('boms.copy');
    Route::delete('boms/{version}', [BomController::class, 'destroy'])->name('boms.destroy');

    Route::get('production-orders', [OrderController::class, 'index'])->name('production-orders.index');
    Route::post('production-orders', [OrderController::class, 'store'])->middleware('idempotent')->name('production-orders.store');
    Route::get('production-orders/{order}', [OrderController::class, 'show'])->name('production-orders.show');
    Route::put('production-orders/{order}', [OrderController::class, 'update'])->name('production-orders.update');
    Route::post('production-orders/{order}/release', [OrderController::class, 'release'])->middleware('idempotent')->name('production-orders.release');
    Route::post('production-orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('idempotent')->name('production-orders.cancel');
    Route::post('production-orders/{order}/issues', [OrderController::class, 'issue'])->middleware('idempotent')->name('production-orders.issue');
    Route::post('production-orders/{order}/returns', [OrderController::class, 'return'])->middleware('idempotent')->name('production-orders.return');

    Route::post('production-orders/{order}/output', [OrderController::class, 'output'])->middleware('idempotent')->name('production-orders.output');
    Route::post('production-orders/{order}/rework-done', [OrderController::class, 'reworkDone'])->middleware('idempotent')->name('production-orders.rework');
    Route::post('production-orders/{order}/inspections', [OrderController::class, 'inspect'])->middleware('idempotent')->name('production-orders.inspect');
    Route::post('production-orders/{order}/receipts', [OrderController::class, 'receive'])->middleware('idempotent')->name('production-orders.receive');
    Route::post('production-orders/{order}/complete', [OrderController::class, 'complete'])->middleware('idempotent')->name('production-orders.complete');

    Route::post('production-reports/{report}/void', [ShopfloorController::class, 'voidReport'])->middleware('idempotent')->name('production-reports.void');
    Route::post('downtime-events', [ShopfloorController::class, 'downtime'])->middleware('idempotent')->name('downtime-events.store');
    Route::post('downtime-events/{event}/void', [ShopfloorController::class, 'voidDowntime'])->middleware('idempotent')->name('downtime-events.void');
    Route::get('shift-report', [ShopfloorController::class, 'shiftReport'])->name('shift-report');
    Route::get('shift-targets', [ShopfloorController::class, 'targets'])->name('shift-targets.index');
    Route::put('shift-targets', [ShopfloorController::class, 'setTarget'])->name('shift-targets.set');

    Route::get('manufacturing/{entity}', [SetupController::class, 'index'])->name('manufacturing.setup.index');
    Route::post('manufacturing/{entity}', [SetupController::class, 'store'])->name('manufacturing.setup.store');
    Route::put('manufacturing/{entity}/{id}', [SetupController::class, 'update'])->name('manufacturing.setup.update');
});
