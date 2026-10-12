<?php

use App\Modules\Reporting\Http\Controllers\Api\DashboardController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 (names api.v1.*) with the `module:reporting` guard.

Route::middleware(['auth:sanctum', 'mfa.enrolled'])->prefix('reports')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('reports.index');
    Route::get('{dashboard}', [DashboardController::class, 'show'])->name('reports.show');
});
