<?php

use App\Modules\Core\Http\Controllers\Web\AttachmentController;
use App\Modules\Core\Http\Controllers\Web\AuditLogController;
use App\Modules\Core\Http\Controllers\Web\CompanyController;
use App\Modules\Core\Http\Controllers\Web\DashboardController;
use App\Modules\Core\Http\Controllers\Web\LocaleController;
use App\Modules\Core\Http\Controllers\Web\LoginController;
use App\Modules\Core\Http\Controllers\Web\MfaController;
use App\Modules\Core\Http\Controllers\Web\ModuleController;
use App\Modules\Core\Http\Controllers\Web\NotificationController;
use Illuminate\Support\Facades\Route;

Route::post('locale', [LocaleController::class, 'update'])->name('locale.update');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    // Reachable before MFA enrolment so privileged users can complete it.
    Route::get('mfa/setup', [MfaController::class, 'setup'])->name('mfa.setup');
    Route::post('mfa/confirm', [MfaController::class, 'confirm'])->name('mfa.confirm');

    Route::middleware('mfa.enrolled')->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('admin/modules', [ModuleController::class, 'index'])->name('modules.index');
        Route::post('admin/modules/{module}/enable', [ModuleController::class, 'enable'])->name('modules.enable');
        Route::post('admin/modules/{module}/disable', [ModuleController::class, 'disable'])->name('modules.disable');

        Route::get('admin/company', [CompanyController::class, 'edit'])->name('company.edit');
        Route::put('admin/company', [CompanyController::class, 'update'])->name('company.update');

        Route::get('admin/audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

        Route::post('attachments', [AttachmentController::class, 'store'])->middleware('throttle:30,1')->name('attachments.store');
        Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
        Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])->name('attachments.download');
    });
});
