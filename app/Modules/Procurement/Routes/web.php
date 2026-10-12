<?php

use App\Modules\Procurement\Http\Controllers\Web\DocumentController;
use App\Modules\Procurement\Http\Controllers\Web\OrderController;
use Illuminate\Support\Facades\Route;

// Web screens call the same services as the API: one rule set, two front doors.

Route::middleware(['auth', 'mfa.enrolled'])->prefix('procurement')->group(function () {
    Route::get('orders', [OrderController::class, 'index'])->name('procurement.orders.index');
    Route::get('orders/create', [OrderController::class, 'create'])->name('procurement.orders.create');
    Route::post('orders', [OrderController::class, 'store'])->name('procurement.orders.store');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('procurement.orders.show');
    Route::get('orders/{order}/print', [OrderController::class, 'print'])->name('procurement.orders.print');
    Route::get('orders/{order}/edit', [OrderController::class, 'edit'])->name('procurement.orders.edit');
    Route::put('orders/{order}', [OrderController::class, 'update'])->name('procurement.orders.update');
    Route::post('orders/{order}/submit', [OrderController::class, 'submit'])->name('procurement.orders.submit');
    Route::post('orders/{order}/withdraw', [OrderController::class, 'withdraw'])->name('procurement.orders.withdraw');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('procurement.orders.cancel');
    Route::post('orders/{order}/receipts', [OrderController::class, 'receive'])->name('procurement.orders.receive');
    Route::post('orders/{order}/bills', [OrderController::class, 'bill'])->name('procurement.orders.bill');
    Route::post('orders/{order}/returns', [OrderController::class, 'sendBack'])->name('procurement.orders.return');

    Route::get('receipts', [DocumentController::class, 'receipts'])->name('procurement.receipts.index');
    Route::get('receipts/{receipt}', [DocumentController::class, 'receipt'])->name('procurement.receipts.show');
    Route::post('receipts/{receipt}/reverse', [DocumentController::class, 'reverseReceipt'])->name('procurement.receipts.reverse');

    Route::get('bills', [DocumentController::class, 'bills'])->name('procurement.bills.index');
    Route::get('bills/{bill}', [DocumentController::class, 'bill'])->name('procurement.bills.show');

    Route::get('returns', [DocumentController::class, 'returns'])->name('procurement.returns.index');
    Route::get('returns/{return}', [DocumentController::class, 'purchaseReturn'])->name('procurement.returns.show');
});
