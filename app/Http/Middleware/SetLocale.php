<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Sets the application locale from the Accept-Language header sent by the storefront
 * (fr | ar | en). French is the default. The admin API keeps English because the admin
 * panel is not translated.
 *
 * For authenticated customers, the chosen locale is saved on users.locale so notifications
 * and e-mails created later (e.g. by an admin action) use the recipient's language.
 */
class SetLocale
{
    public const SUPPORTED = ['fr', 'ar', 'en'];
    public const DEFAULT   = 'fr';

    public function handle(Request $request, Closure $next)
    {
        $locale = $request->is('api/admin', 'api/admin/*')
            ? 'en'
            : self::fromRequest($request);

        App::setLocale($locale);
        Carbon::setLocale($locale);

        // Translated catalog content: product texts on the storefront only (seller and admin
        // screens edit them); category / attribute names also on the seller dashboard.
        \App\Support\Localization::enable(
            products: !self::isBackOffice($request),
            catalog:  !self::isBackOffice($request) || $request->is('api/seller', 'api/seller/*'),
        );

        $response = $next($request);

        if ($response instanceof \Symfony\Component\HttpFoundation\Response) {
            $response->headers->set('Content-Language', $locale);
        }

        // Remember the customer's language. Only a user already resolved by the route's auth
        // middleware is used (no extra token lookup), and only when the header is explicit.
        $user = $request->user();
        if ($user instanceof \App\Models\User
            && $request->headers->has('Accept-Language')
            && !$request->is('api/admin', 'api/admin/*')
            && $user->locale !== $locale) {
            try {
                $user->forceFill(['locale' => $locale])->saveQuietly();
            } catch (\Throwable $e) {
                // never fail a request because the preference could not be saved
            }
        }

        return $response;
    }

    public static function isBackOffice(Request $request): bool
    {
        return $request->is('api/admin', 'api/admin/*', 'api/seller', 'api/seller/*', 'api/delivery', 'api/delivery/*');
    }

    public static function fromRequest(Request $request): string
    {
        $header = $request->header('Accept-Language');
        if (!$header) {
            return self::DEFAULT;
        }

        return $request->getPreferredLanguage(self::SUPPORTED) ?? self::DEFAULT;
    }
}
