<?php

use App\Http\Controllers\Admin\UserController;
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
});

Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('admin.users');
    });

require __DIR__.'/auth.php';
