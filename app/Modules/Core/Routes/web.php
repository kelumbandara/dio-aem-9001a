<?php

use App\Modules\Core\Http\Controllers\Web\AppearanceController;
use App\Modules\Core\Http\Controllers\Web\AttachmentController;
use App\Modules\Core\Http\Controllers\Web\AuditLogController;
use App\Modules\Core\Http\Controllers\Web\BackupController;
use App\Modules\Core\Http\Controllers\Web\BrandingController;
use App\Modules\Core\Http\Controllers\Web\CompanyController;
use App\Modules\Core\Http\Controllers\Web\DashboardController;
use App\Modules\Core\Http\Controllers\Web\LocaleController;
use App\Modules\Core\Http\Controllers\Web\LoginController;
use App\Modules\Core\Http\Controllers\Web\MfaController;
use App\Modules\Core\Http\Controllers\Web\ModuleController;
use App\Modules\Core\Http\Controllers\Web\NotificationController;
use App\Modules\Core\Http\Controllers\Web\PasswordController;
use App\Modules\Core\Http\Controllers\Web\UserController;
use Illuminate\Support\Facades\Route;

Route::post('locale', [LocaleController::class, 'update'])->name('locale.update');

// The company logo is public: the sign-in page shows it. Its address carries a version, so browsers keep it.
Route::get('branding/logo', [BrandingController::class, 'logo'])->name('branding.logo');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');

    Route::get('forgot-password', [PasswordController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('reset-password/{token}', [PasswordController::class, 'reset'])->name('password.reset');
    Route::post('reset-password', [PasswordController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
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

        Route::get('admin/appearance', [AppearanceController::class, 'edit'])->name('appearance.edit');
        Route::put('admin/appearance', [AppearanceController::class, 'update'])->name('appearance.update');
        Route::post('admin/appearance/reset', [AppearanceController::class, 'reset'])->name('appearance.reset');
        Route::post('admin/appearance/preview', [AppearanceController::class, 'preview'])->name('appearance.preview');

        Route::get('admin/users', [UserController::class, 'index'])->name('users.index');
        Route::get('admin/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('admin/users', [UserController::class, 'store'])->name('users.store');
        Route::get('admin/users/{user}', [UserController::class, 'edit'])->name('users.edit');
        Route::put('admin/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('admin/users/{user}/password-link', [UserController::class, 'sendLink'])->middleware('throttle:10,1')->name('users.password-link');
        Route::post('admin/users/{user}/reset-mfa', [UserController::class, 'resetMfa'])->name('users.reset-mfa');

        Route::get('account/password', [PasswordController::class, 'edit'])->name('account.password');
        Route::put('account/password', [PasswordController::class, 'change'])->middleware('throttle:10,1')->name('account.password.update');

        Route::get('admin/backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('admin/backups', [BackupController::class, 'store'])->middleware('throttle:6,1')->name('backups.store');
        Route::get('admin/backups/{name}/download', [BackupController::class, 'download'])->middleware('throttle:20,1')->where('name', '[A-Za-z0-9_.\-]+')->name('backups.download');

        Route::post('admin/backups/{name}/restore', [BackupController::class, 'restore'])->middleware('throttle:3,10')->where('name', '[A-Za-z0-9_.\-]+')->name('backups.restore');

        Route::get('admin/audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

        Route::post('attachments', [AttachmentController::class, 'store'])->middleware('throttle:30,1')->name('attachments.store');
        Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
        Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])->name('attachments.download');
    });
});
