<?php

namespace App\Services\Ads\Payments;

use App\Models\AdTopUp;
use Illuminate\Http\Request;

/**
 * A way for a seller to put money in their ad wallet. We never collect card
 * data ourselves: real gateways redirect to their hosted payment page and
 * confirm through a server callback.
 */
interface AdTopUpGateway
{
    /** Stored on ad_top_ups.gateway. */
    public function key(): string;

    public function isAvailable(): bool;

    /**
     * Begin paying a pending top-up.
     *
     * @return array{status: string, redirect_url?: string, instructions?: array}
     *         status: paid (settled already) | pending (redirect or wait for an admin)
     */
    public function start(AdTopUp $topUp, array $input): array;

    /**
     * Server-to-server confirmation from the gateway.
     *
     * @return array{top_up_id: int, paid: bool, reference: ?string}|null  null = not ours / invalid
     */
    public function handleCallback(Request $request): ?array;
}
