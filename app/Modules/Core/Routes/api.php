<?php

use App\Modules\Core\Http\Controllers\Api\AttachmentController;
use App\Modules\Core\Http\Controllers\Api\AuthController;
use App\Modules\Core\Http\Controllers\Api\BranchController;
use App\Modules\Core\Http\Controllers\Api\HealthController;
use App\Modules\Core\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 with name prefix api.v1. by ModuleServiceProvider.

Route::get('health', [HealthController::class, 'live'])->name('health.live');
Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');

Route::middleware('auth:sanctum')->group(function () {
    // Available before MFA enrolment so privileged users can complete it.
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::post('auth/mfa/setup', [AuthController::class, 'mfaSetup'])->name('auth.mfa.setup');
    Route::post('auth/mfa/confirm', [AuthController::class, 'mfaConfirm'])->name('auth.mfa.confirm');

    Route::middleware('mfa.enrolled')->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');

        Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
        Route::post('branches', [BranchController::class, 'store'])->middleware('idempotent')->name('branches.store');
        Route::get('branches/{branch}', [BranchController::class, 'show'])->name('branches.show');
        Route::patch('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
        Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

        // Uploads are not idempotency-keyed: multipart bodies are not part of the request hash.
        Route::get('attachments', [AttachmentController::class, 'index'])->name('attachments.index');
        Route::post('attachments', [AttachmentController::class, 'store'])->middleware('throttle:30,1')->name('attachments.store');
        Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
        Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
        Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])
            ->middleware('signed')->name('attachments.download');
    });
});
