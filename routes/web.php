<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// One-click unsubscribe from marketing e-mails (link + RFC 8058 POST)
Route::match(['get', 'post'], '/unsubscribe/{token}', [\App\Http\Controllers\MarketingConsentController::class, 'unsubscribe'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('marketing.unsubscribe');

// Public homepage
Route::get('/', function () {
    return view('welcome');
});

// Authentication routes (login, register, logout)
Auth::routes();

// Authenticated home redirect based on role
Route::get('/home', function () {
    if (!auth()->check()) {
        return redirect()->route('login');
    }

    $user = auth()->user();

    // Redirect based on role
    if ($user->isAdmin()) {
        return redirect()->route('admin.dashboard');
    }

    if ($user->isSeller()) {
        // Check if seller is approved
        if ($user->is_approved) {
            return redirect()->route('seller.dashboard');
        } else {
            return redirect()->route('seller.pending');
        }
    }

    if ($user->isClient()) {
        return redirect()->route('client.dashboard');
    }

    // Fallback
    return redirect('/');

})->middleware('auth')->name('home');

// Include role-specific routes
require __DIR__.'/admin.php';   // Admin routes
