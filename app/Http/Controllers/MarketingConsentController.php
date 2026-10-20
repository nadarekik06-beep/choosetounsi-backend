<?php

namespace App\Http\Controllers;

use App\Services\Ads\MarketingConsent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 *   GET  /api/account/marketing-consent        (auth) current choice
 *   POST /api/account/marketing-consent        (auth) {opt_in: bool}
 *   GET  /unsubscribe/{token}                  (web)  redirect to the storefront page (changes nothing)
 *   POST /unsubscribe/{token}                  (web)  RFC 8058 List-Unsubscribe-Post one-click
 *   GET  /api/unsubscribe/{token}              link valid? (storefront page)
 *   POST /api/unsubscribe/{token}              confirm button of the storefront page
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

    /**
     * Web /unsubscribe/{token}. POST = RFC 8058 one-click (List-Unsubscribe header):
     * opts out, 204 / 404. GET (old e-mails, a header URL opened by hand) changes
     * nothing: it redirects to the storefront confirmation page.
     */
    public function unsubscribe(Request $request, string $token)
    {
        if (!$request->isMethod('post')) {
            return redirect()->away(frontend_url('/unsubscribe/' . $token));
        }
        return response('', $this->consent->unsubscribe($token) ? 204 : 404);
    }

    /** GET /api/unsubscribe/{token}: is the link valid? Read-only, reveals nothing about the account. */
    public function check(string $token): JsonResponse
    {
        $found = $this->consent->tokenExists($token);
        return response()->json(['success' => $found, 'found' => $found], $found ? 200 : 404);
    }

    /** POST /api/unsubscribe/{token}: the storefront page's confirm button. */
    public function confirm(string $token): JsonResponse
    {
        $user = $this->consent->unsubscribe($token);
        return response()->json(['success' => (bool) $user, 'found' => (bool) $user], $user ? 200 : 404);
    }
}
