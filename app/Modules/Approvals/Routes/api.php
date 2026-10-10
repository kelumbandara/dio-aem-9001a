<?php

use App\Modules\Approvals\Http\Controllers\Api\ApprovalController;
use App\Modules\Approvals\Http\Controllers\Api\DelegationController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 (names api.v1.*) with the `module:approvals` guard by ModuleServiceProvider.

Route::middleware(['auth:sanctum', 'mfa.enrolled'])->group(function () {
    Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');

    // Defined before approvals/{approval} so "delegations" is not read as an id.
    Route::get('approvals/delegations', [DelegationController::class, 'index'])->name('approvals.delegations.index');
    Route::post('approvals/delegations', [DelegationController::class, 'store'])->middleware('idempotent')->name('approvals.delegations.store');
    Route::delete('approvals/delegations/{delegation}', [DelegationController::class, 'destroy'])->name('approvals.delegations.destroy');

    Route::get('approvals/{approval}', [ApprovalController::class, 'show'])->name('approvals.show');
    Route::post('approvals/{approval}/approve', [ApprovalController::class, 'approve'])->middleware('idempotent')->name('approvals.approve');
    Route::post('approvals/{approval}/reject', [ApprovalController::class, 'reject'])->middleware('idempotent')->name('approvals.reject');
    Route::post('approvals/{approval}/cancel', [ApprovalController::class, 'cancel'])->middleware('idempotent')->name('approvals.cancel');
    Route::post('approvals/{approval}/retry', [ApprovalController::class, 'retry'])->middleware('idempotent')->name('approvals.retry');
});
