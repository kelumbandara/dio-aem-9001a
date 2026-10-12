<?php

use App\Modules\Reporting\Http\Controllers\Web\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'mfa.enrolled'])->prefix('reports')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('reporting.index');
    Route::get('{dashboard}', [DashboardController::class, 'show'])->name('reporting.show');
    Route::get('{dashboard}/print', [DashboardController::class, 'print'])->name('reporting.print');
    Route::get('{dashboard}/csv', [DashboardController::class, 'csv'])->name('reporting.csv');
});
