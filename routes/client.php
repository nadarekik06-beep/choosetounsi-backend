<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Client\ClientDashboardController;
use App\Http\Controllers\Client\ClientOrderController;
use App\Http\Controllers\Client\ProfileController;

/**
 * Client Routes
 * All routes are protected by 'auth' middleware
 * Prefix: /client
 */
Route::prefix('client')->name('client.')->middleware('auth')->group(function () {
    
    // Dashboard
    Route::get('/dashboard', [ClientDashboardController::class, 'index'])
        ->middleware('client')
        ->name('dashboard');

    // Orders
    Route::prefix('orders')->name('orders.')->middleware('client')->group(function () {
        Route::get('/', [ClientOrderController::class, 'index'])
            ->name('index');
        Route::get('/{order}', [ClientOrderController::class, 'show'])
            ->name('show');
    });

    // Profile (accessible by both clients and sellers)
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/', [ProfileController::class, 'index'])
            ->name('index');
        Route::put('/', [ProfileController::class, 'update'])
            ->name('update');
        Route::put('/password', [ProfileController::class, 'updatePassword'])
            ->name('update-password');
        
        // Request seller role
        Route::post('/request-seller', [ProfileController::class, 'requestSellerRole'])
            ->name('request-seller');
    });
});