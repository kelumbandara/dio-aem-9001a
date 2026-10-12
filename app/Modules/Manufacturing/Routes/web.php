<?php

use App\Modules\Manufacturing\Http\Controllers\Web\BomController;
use App\Modules\Manufacturing\Http\Controllers\Web\OrderController;
use App\Modules\Manufacturing\Http\Controllers\Web\ReportController;
use App\Modules\Manufacturing\Http\Controllers\Web\SetupController;
use App\Modules\Manufacturing\Http\Controllers\Web\ShiftReportController;
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

    Route::post('orders/{order}/rework-done', [OrderController::class, 'reworkDone'])->name('manufacturing.orders.rework');
    Route::post('orders/{order}/inspections', [OrderController::class, 'inspect'])->name('manufacturing.orders.inspect');
    Route::post('orders/{order}/receipts', [OrderController::class, 'receive'])->name('manufacturing.orders.receive');
    Route::post('orders/{order}/complete', [OrderController::class, 'complete'])->name('manufacturing.orders.complete');

    Route::get('report', [ReportController::class, 'index'])->name('manufacturing.report.index');
    Route::get('report/{order}', [ReportController::class, 'show'])->name('manufacturing.report.show');
    Route::post('report/{order}/output', [ReportController::class, 'output'])->name('manufacturing.report.output');
    Route::post('report/{order}/downtime', [ReportController::class, 'downtime'])->name('manufacturing.report.downtime');
    Route::post('reports/{report}/void', [ReportController::class, 'voidReport'])->name('manufacturing.reports.void');
    Route::post('downtime/{event}/void', [ReportController::class, 'voidDowntime'])->name('manufacturing.downtime.void');
    Route::get('shift-report', [ShiftReportController::class, 'index'])->name('manufacturing.shifts.report');

    Route::get('setup', [SetupController::class, 'overview'])->name('manufacturing.setup.index');
    Route::get('setup/{entity}', [SetupController::class, 'index'])->name('manufacturing.setup.list');
    Route::get('setup/{entity}/create', [SetupController::class, 'create'])->name('manufacturing.setup.create');
    Route::post('setup/{entity}', [SetupController::class, 'store'])->name('manufacturing.setup.store');
    Route::get('setup/{entity}/{id}/edit', [SetupController::class, 'edit'])->name('manufacturing.setup.edit');
    Route::put('setup/{entity}/{id}', [SetupController::class, 'update'])->name('manufacturing.setup.update');
});
