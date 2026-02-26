<?php
// routes/admin.php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\SellerController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\StatisticsController;

Route::prefix('admin')->name('admin.')->group(function () {

    // ─── Public (no auth) ───────────────────────────────────────────
    Route::post('/login',  [AuthController::class, 'login'])->name('login');

    // ─── Protected (admin guard) ────────────────────────────────────
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {

        // Auth
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me',      [AuthController::class, 'me'])->name('me');

        // Dashboard KPIs
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Users (clients)
        Route::get('/users',           [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{id}',      [UserController::class, 'show'])->name('users.show');
        Route::patch('/users/{id}/ban',   [UserController::class, 'ban'])->name('users.ban');
        Route::patch('/users/{id}/unban', [UserController::class, 'unban'])->name('users.unban');
        Route::delete('/users/{id}',   [UserController::class, 'destroy'])->name('users.destroy');

        // Sellers
        Route::get('/sellers',                   [SellerController::class, 'index'])->name('sellers.index');
        Route::get('/sellers/{id}',              [SellerController::class, 'show'])->name('sellers.show');
        Route::patch('/sellers/{id}/approve',    [SellerController::class, 'approve'])->name('sellers.approve');
        Route::patch('/sellers/{id}/reject',     [SellerController::class, 'reject'])->name('sellers.reject');
        Route::patch('/sellers/{id}/suspend',    [SellerController::class, 'suspend'])->name('sellers.suspend');

        // Products
        Route::get('/products',                  [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/{id}',             [ProductController::class, 'show'])->name('products.show');
        Route::patch('/products/{id}/approve',   [ProductController::class, 'approve'])->name('products.approve');
        Route::patch('/products/{id}/disable',   [ProductController::class, 'disable'])->name('products.disable');
        Route::delete('/products/{id}',          [ProductController::class, 'destroy'])->name('products.destroy');

        // Orders
        Route::get('/orders',      [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');

        // Statistics / Charts
        Route::get('/statistics',              [StatisticsController::class, 'index'])->name('statistics.index');
        Route::get('/statistics/revenue',      [StatisticsController::class, 'revenue'])->name('statistics.revenue');
        Route::get('/statistics/orders-trend', [StatisticsController::class, 'ordersTrend'])->name('statistics.orders');
        Route::get('/statistics/categories',   [StatisticsController::class, 'categories'])->name('statistics.categories');
    });
});