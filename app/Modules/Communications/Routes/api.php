<?php

use App\Modules\Communications\Http\Controllers\Api\ChannelController;
use App\Modules\Communications\Http\Controllers\Api\ConsentController;
use App\Modules\Communications\Http\Controllers\Api\MessageController;
use App\Modules\Communications\Http\Controllers\Api\TemplateController;
use App\Modules\Communications\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 (names api.v1.*) with the `module:communications` guard.

// WhatsApp calls these without signing in; every call is checked by its signature (see WhatsAppWebhook).
Route::get('webhooks/whatsapp', [WebhookController::class, 'verify'])->middleware('throttle:60,1')->name('webhooks.whatsapp.verify');
Route::post('webhooks/whatsapp', [WebhookController::class, 'receive'])->middleware('throttle:600,1')->name('webhooks.whatsapp.receive');

Route::middleware(['auth:sanctum', 'mfa.enrolled'])->group(function () {
    Route::get('messages', [MessageController::class, 'index'])->name('messages.index');
    Route::post('messages', [MessageController::class, 'store'])->middleware('idempotent')->name('messages.store');
    Route::get('messages/{message}', [MessageController::class, 'show'])->name('messages.show');
    Route::post('messages/{message}/send', [MessageController::class, 'send'])->middleware('idempotent')->name('messages.send');
    Route::post('messages/{message}/cancel', [MessageController::class, 'cancel'])->middleware('idempotent')->name('messages.cancel');

    Route::get('message-templates', [TemplateController::class, 'index'])->name('message-templates.index');
    Route::put('message-templates/{template}', [TemplateController::class, 'update'])->name('message-templates.update');

    Route::get('consents', [ConsentController::class, 'index'])->name('consents.index');
    Route::post('consents', [ConsentController::class, 'store'])->middleware('idempotent')->name('consents.store');

    Route::get('me/channels', [ChannelController::class, 'index'])->name('me.channels.index');
    Route::post('me/channels', [ChannelController::class, 'start'])->middleware('throttle:10,60')->name('me.channels.start');
    Route::post('me/channels/confirm', [ChannelController::class, 'confirm'])->middleware('throttle:20,60')->name('me.channels.confirm');
    Route::delete('me/channels/{identity}', [ChannelController::class, 'unlink'])->name('me.channels.unlink');
});
