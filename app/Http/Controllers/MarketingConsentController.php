<?php

namespace App\Http\Controllers;

use App\Services\Ads\MarketingConsent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 *   GET  /api/account/marketing-consent        (auth) current choice
 *   POST /api/account/marketing-consent        (auth) {opt_in: bool}
 *   GET  /unsubscribe/{token}                  (web)  one-click unsubscribe link in every marketing e-mail
 *   POST /unsubscribe/{token}                  (web)  RFC 8058 List-Unsubscribe-Post
 */
class MarketingConsentController extends Controller
{
    public function __construct(private MarketingConsent $consent) {}

    public function show(Request $request): JsonResponse
    {
        $u = $request->user();
        return response()->json(['success' => true, 'data' => [
            'opt_in'    => (bool) $u->marketing_emails_opt_in,
            'opt_in_at' => $u->marketing_opt_in_at?->toIso8601String(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['opt_in' => ['required', 'boolean']]);
        $u = $this->consent->set($request->user(), (bool) $data['opt_in']);

        return response()->json(['success' => true, 'data' => [
            'opt_in'    => (bool) $u->marketing_emails_opt_in,
            'opt_in_at' => $u->marketing_opt_in_at?->toIso8601String(),
        ]]);
    }

    public function unsubscribe(Request $request, string $token): Response
    {
        $user = $this->consent->unsubscribe($token);
        if ($user) {
            app()->setLocale(in_array($user->locale, ['en', 'fr', 'ar'], true) ? $user->locale : config('app.locale'));
        }

        if ($request->isMethod('post')) {
            return response('', $user ? 204 : 404);
        }

        return response()->view('marketing.unsubscribed', [
            'found' => (bool) $user,
            'shopUrl' => rtrim((string) config('app.frontend_url'), '/'),
        ], $user ? 200 : 404);
    }
}
