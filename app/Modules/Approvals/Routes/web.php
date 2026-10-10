<?php

use App\Modules\Approvals\Http\Controllers\Web\ApprovalController;
use App\Modules\Approvals\Http\Controllers\Web\AuthorityController;
use App\Modules\Approvals\Http\Controllers\Web\DelegationController;
use App\Modules\Approvals\Http\Controllers\Web\DemoController;
use App\Modules\Approvals\Http\Controllers\Web\PolicyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'mfa.enrolled'])->group(function () {
    Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');

    // Before approvals/{approval} so "delegations" is not taken as an id.
    Route::get('approvals/delegations', [DelegationController::class, 'index'])->name('approvals.delegations.index');
    Route::post('approvals/delegations', [DelegationController::class, 'store'])->name('approvals.delegations.store');
    Route::delete('approvals/delegations/{delegation}', [DelegationController::class, 'destroy'])->name('approvals.delegations.destroy');

    Route::get('approvals/{approval}', [ApprovalController::class, 'show'])->name('approvals.show');
    Route::post('approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');
    Route::post('approvals/{approval}/cancel', [ApprovalController::class, 'cancel'])->name('approvals.cancel');
    Route::post('approvals/{approval}/retry', [ApprovalController::class, 'retry'])->name('approvals.retry');

    // Development demo of the approval flow; never routed outside the local environment.
    if (app()->environment('local')) {
        Route::get('dev/approvals-demo', [DemoController::class, 'create'])->name('approvals.demo');
        Route::post('dev/approvals-demo', [DemoController::class, 'store'])->name('approvals.demo.store');
    }

    Route::prefix('admin/approvals')->group(function () {
        Route::get('policies', [PolicyController::class, 'index'])->name('approvals.policies.index');
        Route::get('policies/create', [PolicyController::class, 'create'])->name('approvals.policies.create');
        Route::post('policies', [PolicyController::class, 'store'])->name('approvals.policies.store');
        Route::get('policies/{policy}/edit', [PolicyController::class, 'edit'])->name('approvals.policies.edit');
        Route::put('policies/{policy}', [PolicyController::class, 'update'])->name('approvals.policies.update');
        Route::delete('policies/{policy}', [PolicyController::class, 'destroy'])->name('approvals.policies.destroy');

        Route::get('authority', [AuthorityController::class, 'index'])->name('approvals.authority.index');
        Route::post('authority', [AuthorityController::class, 'store'])->name('approvals.authority.store');
        Route::delete('authority/{authority}', [AuthorityController::class, 'destroy'])->name('approvals.authority.destroy');
    });
});
