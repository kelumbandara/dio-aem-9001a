<?php

use App\Http\Controllers\Api\LicenceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

// Staff sign-in (password, then authenticator code)
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');
Route::get('/mfa/setup', [AuthController::class, 'showSetup'])->name('mfa.setup');
Route::post('/mfa/setup', [AuthController::class, 'confirmSetup'])->name('mfa.setup.post');
Route::get('/mfa', [AuthController::class, 'showChallenge'])->name('mfa.challenge');
Route::post('/mfa', [AuthController::class, 'challenge'])->name('mfa.challenge.post');

// Customer-facing (found by an unguessable token) and PayHere's server message
Route::get('/i/{token}', [PublicInvoiceController::class, 'invoice'])->name('invoice.public');
Route::get('/pay/{token}', [PublicInvoiceController::class, 'show'])->name('pay.show');
Route::get('/pay/{token}/thanks', [PublicInvoiceController::class, 'returned'])->name('pay.return');
Route::post('/payhere/notify', [PublicInvoiceController::class, 'notify'])->name('payhere.notify');

// An installation fetching its licence
Route::get('/api/v1/installations/{installation}/licence', [LicenceController::class, 'show'])->middleware('throttle:60,1')->name('api.licence');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::resource('customers', CustomerController::class)->except('destroy');
    Route::post('customers/{customer}/licence', [CustomerController::class, 'issueLicence'])->name('customers.licence');

    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/print', [InvoiceController::class, 'print'])->name('invoices.print');
    Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'pay'])->name('invoices.pay');

    Route::middleware('owner')->group(function () {
        Route::post('customers/{customer}/token', [CustomerController::class, 'token'])->name('customers.token');
        Route::post('customers/{customer}/suspend', [CustomerController::class, 'suspend'])->name('customers.suspend');
        Route::post('customers/{customer}/resume', [CustomerController::class, 'resume'])->name('customers.resume');
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
        Route::post('invoices/{invoice}/payments/{payment}/reverse', [InvoiceController::class, 'reverse'])->name('invoices.reverse');
        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::get('activity', [SettingsController::class, 'activity'])->name('activity');
    });
});
