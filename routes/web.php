<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\BlueReturnStatementPdfController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth'])->group(function () {

    Route::get('/dashboard', PortalController::class)->name('dashboard');
    Route::get('/initialize', [SetupController::class, 'initialize'])->name('initialize');

    Route::get('/fixed-expenses', [PortalController::class, 'fixedExpenses'])
        ->name('fixed-expenses');

    Route::get('/blue-return-statement/pdf', [BlueReturnStatementPdfController::class, 'show'])
        ->name('blue-return-statement.pdf.show');
    Route::post('/blue-return-statement/pdf', [BlueReturnStatementPdfController::class, 'download'])
        ->name('blue-return-statement.pdf.download');

    Route::get('/transactions/revenues', [PortalController::class, 'transactionIndex'])
        ->defaults('kind', 'revenue')
        ->name('transactions.revenues');
    Route::get('/transactions/expenses', [PortalController::class, 'transactionIndex'])
        ->defaults('kind', 'expense')
        ->name('transactions.expenses');
    Route::get('/transactions/expense-types', [PortalController::class, 'transactionIndex'])
        ->defaults('kind', 'expense_type')
        ->name('transactions.expense-types');
    Route::get('/transactions/purchases', [PortalController::class, 'transactionIndex'])
        ->defaults('kind', 'purchase')
        ->name('transactions.purchases');

    Route::get('/accounts/summary', [PortalController::class, 'accountSummary'])
        ->name('accounts.summary');
});

Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('admin.users');
    });

require __DIR__.'/auth.php';
