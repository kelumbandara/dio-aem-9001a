<?php

use App\Modules\Communications\Http\Controllers\Web\ChannelController;
use App\Modules\Communications\Http\Controllers\Web\ConsentController;
use App\Modules\Communications\Http\Controllers\Web\MessageController;
use App\Modules\Communications\Http\Controllers\Web\TemplateController;
use App\Modules\Communications\Http\Controllers\Web\UnsubscribeController;
use Illuminate\Support\Facades\Route;

// The one-click "stop these e-mails" link at the foot of every e-mail: signed, so only the link we sent works, and no sign-in needed.
Route::get('unsubscribe/{message}', UnsubscribeController::class)->middleware('signed')->name('comm.unsubscribe');

Route::middleware(['auth', 'mfa.enrolled'])->prefix('communications')->group(function () {
    Route::get('messages', [MessageController::class, 'index'])->name('comm.messages.index');
    Route::get('messages/create', [MessageController::class, 'create'])->name('comm.messages.create');
    Route::post('messages', [MessageController::class, 'store'])->name('comm.messages.store');
    Route::get('messages/{message}', [MessageController::class, 'show'])->name('comm.messages.show');
    Route::post('messages/{message}/send', [MessageController::class, 'send'])->name('comm.messages.send');
    Route::post('messages/{message}/cancel', [MessageController::class, 'cancel'])->name('comm.messages.cancel');

    Route::get('templates', [TemplateController::class, 'index'])->name('comm.templates.index');
    Route::get('templates/{template}/edit', [TemplateController::class, 'edit'])->name('comm.templates.edit');
    Route::put('templates/{template}', [TemplateController::class, 'update'])->name('comm.templates.update');

    Route::get('consents', [ConsentController::class, 'index'])->name('comm.consents.index');
    Route::post('consents', [ConsentController::class, 'store'])->name('comm.consents.store');

    Route::get('channels', [ChannelController::class, 'index'])->name('comm.channels.index');
    Route::post('channels', [ChannelController::class, 'start'])->name('comm.channels.start');
    Route::post('channels/confirm', [ChannelController::class, 'confirm'])->name('comm.channels.confirm');
    Route::delete('channels/{identity}', [ChannelController::class, 'unlink'])->name('comm.channels.unlink');
});
