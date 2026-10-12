<?php

use App\Modules\Licensing\Http\Controllers\Api\LicenceController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 (names api.v1.*) with the `module:licensing` guard.
Route::middleware(['auth:sanctum', 'mfa.enrolled'])->group(function () {
    Route::get('licence', [LicenceController::class, 'show'])->name('licence.show');
    Route::post('licence', [LicenceController::class, 'store'])->name('licence.store');
});
