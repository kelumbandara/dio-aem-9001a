<?php

use App\Modules\Procurement\Http\Controllers\Api\DocumentController;
use App\Modules\Procurement\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 (names api.v1.*) with the `module:procurement` guard.
// Suppliers, items, units, warehouses and tax codes are referred to by code, order lines by their line number.

Route::middleware(['auth:sanctum', 'mfa.enrolled'])->group(function () {
    Route::get('purchase-orders', [OrderController::class, 'index'])->name('purchase-orders.index');
    Route::post('purchase-orders', [OrderController::class, 'store'])->middleware('idempotent')->name('purchase-orders.store');
    Route::get('purchase-orders/{order}', [OrderController::class, 'show'])->name('purchase-orders.show');
    Route::put('purchase-orders/{order}', [OrderController::class, 'update'])->name('purchase-orders.update');
    Route::post('purchase-orders/{order}/submit', [OrderController::class, 'submit'])->middleware('idempotent')->name('purchase-orders.submit');
    Route::post('purchase-orders/{order}/withdraw', [OrderController::class, 'withdraw'])->middleware('idempotent')->name('purchase-orders.withdraw');
    Route::post('purchase-orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('idempotent')->name('purchase-orders.cancel');
    Route::post('purchase-orders/{order}/receipts', [OrderController::class, 'receive'])->middleware('idempotent')->name('purchase-orders.receive');
    Route::post('purchase-orders/{order}/bills', [OrderController::class, 'bill'])->middleware('idempotent')->name('purchase-orders.bill');
    Route::post('purchase-orders/{order}/returns', [OrderController::class, 'sendBack'])->middleware('idempotent')->name('purchase-orders.return');

    Route::get('goods-receipts', [DocumentController::class, 'receipts'])->name('goods-receipts.index');
    Route::get('goods-receipts/{receipt}', [DocumentController::class, 'receipt'])->name('goods-receipts.show');
    Route::post('goods-receipts/{receipt}/reverse', [DocumentController::class, 'reverseReceipt'])->middleware('idempotent')->name('goods-receipts.reverse');

    Route::get('supplier-bills', [DocumentController::class, 'bills'])->name('supplier-bills.index');
    Route::get('supplier-bills/{bill}', [DocumentController::class, 'bill'])->name('supplier-bills.show');

    Route::get('purchase-returns', [DocumentController::class, 'returns'])->name('purchase-returns.index');
    Route::get('purchase-returns/{return}', [DocumentController::class, 'purchaseReturn'])->name('purchase-returns.show');
});
