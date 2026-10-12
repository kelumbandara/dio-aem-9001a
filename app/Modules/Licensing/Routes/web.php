<?php

use App\Modules\Licensing\Http\Controllers\Web\LicenceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'mfa.enrolled'])->group(function () {
    Route::get('admin/licence', [LicenceController::class, 'show'])->name('licence.show');
    Route::post('admin/licence', [LicenceController::class, 'store'])->name('licence.store');
});
