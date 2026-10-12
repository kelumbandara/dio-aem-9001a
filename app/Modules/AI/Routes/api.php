<?php

use App\Modules\AI\Http\Controllers\Api\ConversationController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 (names api.v1.*) with the `module:ai` guard. Each question costs money, so a repeated request with the same
// Idempotency-Key is answered from the first run instead of asking the model again.

Route::middleware(['auth:sanctum', 'mfa.enrolled'])->prefix('ai')->group(function () {
    Route::get('agents', [ConversationController::class, 'agents'])->name('ai.agents');
    Route::get('conversations', [ConversationController::class, 'index'])->name('ai.conversations.index');
    Route::post('conversations', [ConversationController::class, 'start'])->middleware('idempotent')->name('ai.conversations.start');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('ai.conversations.show');
    Route::post('conversations/{conversation}/messages', [ConversationController::class, 'message'])->middleware('idempotent')->name('ai.conversations.message');
    Route::get('usage', [ConversationController::class, 'usage'])->name('ai.usage');
});
