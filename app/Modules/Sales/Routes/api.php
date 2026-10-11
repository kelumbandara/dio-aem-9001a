<?php

use App\Modules\Sales\Http\Controllers\Api\DocumentController;
use App\Modules\Sales\Http\Controllers\Api\OrderController;
use App\Modules\Sales\Http\Controllers\Api\QuotationController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 (names api.v1.*) with the `module:sales` guard.
// Customers, items, units, warehouses and tax codes are referred to by code, lines by their line number.

Route::middleware(['auth:sanctum', 'mfa.enrolled'])->group(function () {
    Route::get('quotations', [QuotationController::class, 'index'])->name('quotations.index');
    Route::post('quotations', [QuotationController::class, 'store'])->middleware('idempotent')->name('quotations.store');
    Route::get('quotations/{quotation}', [QuotationController::class, 'show'])->name('quotations.show');
    Route::put('quotations/{quotation}', [QuotationController::class, 'update'])->name('quotations.update');
    Route::post('quotations/{quotation}/{to}', [QuotationController::class, 'transition'])->whereIn('to', ['sent', 'accepted', 'rejected', 'cancelled'])->middleware('idempotent')->name('quotations.transition');
    Route::post('quotations/{quotation}/convert', [QuotationController::class, 'convert'])->middleware('idempotent')->name('quotations.convert');

    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('orders', [OrderController::class, 'store'])->middleware('idempotent')->name('orders.store');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::put('orders/{order}', [OrderController::class, 'update'])->name('orders.update');
    Route::post('orders/{order}/confirm', [OrderController::class, 'confirm'])->middleware('idempotent')->name('orders.confirm');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('idempotent')->name('orders.cancel');
    Route::post('orders/{order}/deliveries', [OrderController::class, 'deliver'])->middleware('idempotent')->name('orders.deliver');
    Route::post('orders/{order}/invoices', [OrderController::class, 'invoice'])->middleware('idempotent')->name('orders.invoice');

    Route::get('deliveries', [DocumentController::class, 'deliveries'])->name('deliveries.index');
    Route::get('deliveries/{delivery}', [DocumentController::class, 'delivery'])->name('deliveries.show');
    Route::post('deliveries/{delivery}/reverse', [DocumentController::class, 'reverseDelivery'])->middleware('idempotent')->name('deliveries.reverse');

    Route::get('invoices', [DocumentController::class, 'invoices'])->name('invoices.index');
    Route::get('invoices/{invoice}', [DocumentController::class, 'invoice'])->name('invoices.show');
    Route::post('invoices/{invoice}/credit-notes', [DocumentController::class, 'creditNote'])->middleware('idempotent')->name('invoices.credit-notes.store');

    Route::get('credit-notes', [DocumentController::class, 'creditNotes'])->name('credit-notes.index');
    Route::get('credit-notes/{creditNote}', [DocumentController::class, 'showCreditNote'])->name('credit-notes.show');
});
