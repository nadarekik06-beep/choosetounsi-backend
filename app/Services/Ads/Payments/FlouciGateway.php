<?php

namespace App\Services\Ads\Payments;

use App\Models\AdTopUp;
use Illuminate\Http\Request;

/**
 * TODO(production): Flouci hosted payment page (https://developers.flouci.com).
 *
 *   start():          POST /api/generate_payment with app_token/app_secret, amount in
 *                     millimes, success_link / fail_link back to the seller dashboard,
 *                     developer_tracking_id = top-up id; store payment_id in
 *                     ad_top_ups.reference; return ['status' => 'pending', 'redirect_url' => link].
 *   handleCallback(): on return, GET /api/verify_payment/{payment_id} server-side and
 *                     settle only when result.status = "SUCCESS".
 *
 * Keys go in .env (FLOUCI_APP_TOKEN, FLOUCI_APP_SECRET), then set ads.gateways.flouci=true.
 */
class FlouciGateway implements AdTopUpGateway
{
    public function key(): string
    {
        return 'flouci';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function start(AdTopUp $topUp, array $input): array
    {
        throw new \LogicException('Flouci top-ups are not implemented yet.');
    }

    public function handleCallback(Request $request): ?array
    {
        return null;
    }
}
