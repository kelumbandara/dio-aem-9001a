<?php

use App\Modules\Sales\Http\Controllers\Web\DocumentController;
use App\Modules\Sales\Http\Controllers\Web\OrderController;
use App\Modules\Sales\Http\Controllers\Web\QuotationController;
use Illuminate\Support\Facades\Route;

// Web screens call the same services as the API: one rule set, two front doors.

Route::middleware(['auth', 'mfa.enrolled'])->prefix('sales')->group(function () {
    Route::get('quotations', [QuotationController::class, 'index'])->name('sales.quotations.index');
    Route::get('quotations/create', [QuotationController::class, 'create'])->name('sales.quotations.create');
    Route::post('quotations', [QuotationController::class, 'store'])->name('sales.quotations.store');
    Route::get('quotations/{quotation}', [QuotationController::class, 'show'])->name('sales.quotations.show');
    Route::get('quotations/{quotation}/edit', [QuotationController::class, 'edit'])->name('sales.quotations.edit');
    Route::put('quotations/{quotation}', [QuotationController::class, 'update'])->name('sales.quotations.update');
    Route::post('quotations/{quotation}/convert', [QuotationController::class, 'convert'])->name('sales.quotations.convert');
    Route::post('quotations/{quotation}/{to}', [QuotationController::class, 'transition'])->whereIn('to', ['sent', 'accepted', 'rejected', 'cancelled'])->name('sales.quotations.transition');

    Route::get('orders', [OrderController::class, 'index'])->name('sales.orders.index');
    Route::get('orders/create', [OrderController::class, 'create'])->name('sales.orders.create');
    Route::post('orders', [OrderController::class, 'store'])->name('sales.orders.store');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('sales.orders.show');
    Route::get('orders/{order}/edit', [OrderController::class, 'edit'])->name('sales.orders.edit');
    Route::put('orders/{order}', [OrderController::class, 'update'])->name('sales.orders.update');
    Route::post('orders/{order}/confirm', [OrderController::class, 'confirm'])->name('sales.orders.confirm');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('sales.orders.cancel');
    Route::post('orders/{order}/deliveries', [OrderController::class, 'deliver'])->name('sales.orders.deliver');
    Route::post('orders/{order}/invoices', [OrderController::class, 'invoice'])->name('sales.orders.invoice');

    Route::get('deliveries', [DocumentController::class, 'deliveries'])->name('sales.deliveries.index');
    Route::get('deliveries/{delivery}', [DocumentController::class, 'delivery'])->name('sales.deliveries.show');
    Route::post('deliveries/{delivery}/reverse', [DocumentController::class, 'reverseDelivery'])->name('sales.deliveries.reverse');

    Route::get('invoices', [DocumentController::class, 'invoices'])->name('sales.invoices.index');
    Route::get('invoices/{invoice}', [DocumentController::class, 'invoice'])->name('sales.invoices.show');
    Route::get('invoices/{invoice}/print', [DocumentController::class, 'print'])->name('sales.invoices.print');
    Route::post('invoices/{invoice}/credit-notes', [DocumentController::class, 'creditNote'])->name('sales.invoices.credit-notes.store');

    Route::get('credit-notes', [DocumentController::class, 'creditNotes'])->name('sales.credit-notes.index');
    Route::get('credit-notes/{creditNote}', [DocumentController::class, 'showCreditNote'])->name('sales.credit-notes.show');
});
