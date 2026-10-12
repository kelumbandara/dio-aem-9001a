<?php

use App\Modules\AI\Http\Controllers\Web\ChatController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'mfa.enrolled'])->prefix('assistant')->group(function () {
    Route::get('/', [ChatController::class, 'index'])->name('ai.chat.index');
    Route::post('/', [ChatController::class, 'start'])->name('ai.chat.start');
    Route::get('usage', [ChatController::class, 'usage'])->name('ai.usage');
    Route::get('{conversation}', [ChatController::class, 'show'])->name('ai.chat.show');
    Route::post('{conversation}/messages', [ChatController::class, 'message'])->name('ai.chat.message');
});
