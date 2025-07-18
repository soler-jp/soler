<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth'])->group(function () {

    Route::get('/dashboard', \App\Http\Controllers\PortalController::class)->name('dashboard');
    Route::get('/initialize', [\App\Http\Controllers\SetupController::class, 'initialize'])->name('initialize');
});


Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/users',  [\App\Http\Controllers\Admin\UserController::class, 'index'])->name('admin.users');
    });

require __DIR__ . '/auth.php';
