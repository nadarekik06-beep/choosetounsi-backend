<?php

namespace App\Services\Ads\Payments;

use App\Models\AdTopUp;
use Illuminate\Http\Request;

/**
 * TODO(production): Konnect hosted payment page (https://docs.konnect.network).
 *
 *   start():          POST /api/v2/payments/init-payment with amount (millimes), wallet id,
 *                     orderId = top-up id, webhook = route('ads.top-ups.callback', 'konnect');
 *                     store paymentRef in ad_top_ups.reference; return ['status' => 'pending',
 *                     'redirect_url' => payUrl].
 *   handleCallback(): Konnect calls the webhook with ?payment_ref=…; GET
 *                     /api/v2/payments/{ref} server-side and trust only that answer
 *                     (status "completed" → paid). Never trust query parameters alone.
 *
 * Keys go in .env (KONNECT_API_KEY, KONNECT_WALLET_ID), then set ads.gateways.konnect=true.
 */
class KonnectGateway implements AdTopUpGateway
{
    public function key(): string
    {
        return 'konnect';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function start(AdTopUp $topUp, array $input): array
    {
        throw new \LogicException('Konnect top-ups are not implemented yet.');
    }

    public function handleCallback(Request $request): ?array
    {
        return null;
    }
}
