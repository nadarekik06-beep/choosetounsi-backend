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

/*
| Local only: preview the seller order e-mails without sending anything.
|   /dev/mail/seller-orders                       index
|   /dev/mail/seller-orders/{event}/{locale}      event: placed|confirmed|cancelled|pickup_reminder
|   ?id=<seller_order id> (default: latest)  ·  ?text=1 for the plain-text part
| Not registered at all outside APP_ENV=local.
*/
if (app()->environment('local')) {
    Route::get('/dev/mail/seller-orders/{event?}/{locale?}', function (?string $event = null, string $locale = 'en') {
        $classes = [
            'placed'          => \App\Notifications\Orders\NewSellerOrderNotification::class,
            'confirmed'       => \App\Notifications\Orders\SellerOrderConfirmedNotification::class,
            'cancelled'       => \App\Notifications\Orders\SellerOrderCancelledNotification::class,
            'pickup_reminder' => \App\Notifications\Orders\SellerPickupReminderNotification::class,
        ];
        if ($event === null) {
            $links = collect($classes)->keys()->crossJoin(['en', 'fr', 'ar'])
                ->map(fn($p) => "<li><a href=\"/dev/mail/seller-orders/{$p[0]}/{$p[1]}\">{$p[0]} · {$p[1]}</a></li>")->implode('');
            return response("<h1>Seller order e-mails</h1><ul>{$links}</ul>");
        }
        abort_unless(isset($classes[$event]) && in_array($locale, ['en', 'fr', 'ar'], true), 404);

        $sellerOrder = \App\Models\SellerOrder::with('seller')->whereNotNull('seller_id')->whereHas('items')
            ->when(request('id'), fn($q, $id) => $q->whereKey($id))->latest('id')->firstOrFail();

        app()->setLocale($locale);
        $mail = (new $classes[$event]($sellerOrder))->toMail($sellerOrder->seller);

        return request()->boolean('text')
            ? response(view($mail->view[1], $mail->viewData)->render(), 200, ['Content-Type' => 'text/plain; charset=UTF-8'])
            : response('<!-- Subject: ' . e($mail->subject) . ' -->' . $mail->render());
    })->where(['event' => '[a-z_]+', 'locale' => 'en|fr|ar']);
}
