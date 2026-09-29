<?php

namespace App\Services\Ads\Payments;

use App\Models\AdTopUp;
use Illuminate\Http\Request;

/**
 * The seller transfers the money (D17 / bank) and submits the transfer
 * reference; the top-up stays pending until an admin confirms it
 * (POST /api/admin/ads/top-ups/{id}/confirm).
 */
class ManualAdminGateway implements AdTopUpGateway
{
    public function key(): string
    {
        return 'manual';
    }

    public function isAvailable(): bool
    {
        return (bool) config('ads.gateways.manual');
    }

    public function start(AdTopUp $topUp, array $input): array
    {
        return [
            'status'       => AdTopUp::STATUS_PENDING,
            'instructions' => [
                'method'             => 'transfer',
                'd17_account_number' => (string) config('services.d17.account_number'),
                'reference'          => $topUp->reference,
            ],
        ];
    }

    public function handleCallback(Request $request): ?array
    {
        return null;
    }
}
