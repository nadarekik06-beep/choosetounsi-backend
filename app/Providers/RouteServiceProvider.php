<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            // ── API routes (api.php + admin.php) ──────────────────────────
            // NO ->namespace() call — Laravel 8 uses fully-qualified class names
            // in route files, so a namespace prefix would corrupt them.
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/admin.php'));

            // ── Web routes ────────────────────────────────────────────────
            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        // Reads (GET/HEAD) and writes have separate budgets per user (or IP when logged out).
        // One storefront page view makes ~10 API reads, and many Tunisian mobile users share
        // one IP, so reads get 300/min. Writes (login, register, checkout, forms) stay at 60/min.
        RateLimiter::for('api', function (Request $request) {
            $who = optional($request->user())->id ?: $request->ip();
            return $request->isMethodSafe()
                ? Limit::perMinute((int) config('app.api_reads_per_minute', 300))->by('read:' . $who)
                : Limit::perMinute((int) config('app.api_writes_per_minute', 60))->by('write:' . $who);
        });

        // Contact form: 5 messages per 10 minutes and 20 per day per IP
        RateLimiter::for('contact', function (Request $request) {
            $tooMany = fn () => response()->json([
                'success' => false, 'code' => 'rate_limited', 'message' => __('messages.contact.too_many'),
            ], 429);
            return [
                Limit::perMinutes(10, 5)->by('contact10:' . $request->ip())->response($tooMany),
                Limit::perDay(20)->by('contactday:' . $request->ip())->response($tooMany),
            ];
        });

        // Search by photo: each search runs the image model (config search.image.per_minute).
        RateLimiter::for('image-search', function (Request $request) {
            $user = $request->user('sanctum');
            return Limit::perMinute((int) config('search.image.per_minute', 10))
                ->by($user ? 'u' . $user->id : 'ip' . $request->ip())
                ->response(fn () => response()->json([
                    'success' => false, 'code' => 'rate_limited', 'message' => __('messages.search.image_too_many'),
                ], 429));
        });

        // Guest orders (no login): per IP and per delivery phone, per hour.
        // Signed-in checkouts are not limited here (the 'api' limiter still applies).
        RateLimiter::for('guest-checkout', function (Request $request) {
            if ($request->user()) {
                return Limit::none();
            }
            $tooMany = fn () => response()->json([
                'success' => false, 'code' => 'rate_limited', 'message' => __('messages.guest.too_many_orders'),
            ], 429);
            $limits = [
                Limit::perHour((int) config('guest_checkout.orders_per_ip_per_hour', 30))->by('guest-ip:' . $request->ip())->response($tooMany),
            ];
            $phone = \App\Support\TunisianPhone::normalize((string) $request->input('phone', ''));
            if (is_string($phone) && $phone !== '') {
                $limits[] = Limit::perHour((int) config('guest_checkout.orders_per_phone_per_hour', 5))->by('guest-phone:' . $phone)->response($tooMany);
            }
            return $limits;
        });

        // Order tracking by number + phone: stops anyone trying numbers one by one.
        RateLimiter::for('guest-order-lookup', function (Request $request) {
            return Limit::perHour((int) config('guest_checkout.lookups_per_ip_per_hour', 20))
                ->by('order-lookup:' . $request->ip())
                ->response(fn () => response()->json([
                    'success' => false, 'code' => 'rate_limited', 'message' => __('messages.guest.too_many_lookups'),
                ], 429));
        });
    }
}