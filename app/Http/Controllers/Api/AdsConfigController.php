<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sponsorship;
use App\Services\Ads\AdSettings;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/ads/config — buyer-side ad settings the storefront needs (popup
 * rules, density caps, reserved grid slots). No prices or bids: sellers get
 * those from GET /api/seller/ads/config.
 */
class AdsConfigController extends Controller
{
    public function show(AdSettings $settings): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'placements'     => Sponsorship::PLACEMENTS,
            'max_ads'        => $settings->get('max_ads'),
            'reserved_slots' => $settings->get('reserved_slots'),
            'popup'          => [
                'enabled'               => (bool) $settings->get('popup_enabled'),
                'delay_seconds'         => $settings->int('popup_delay_seconds'),
                'dismiss_hours'         => $settings->int('popup_dismiss_hours'),
                'dismiss_days_after_3'  => $settings->int('popup_dismiss_days_after_3'),
            ],
        ]])->header('Cache-Control', 'public, max-age=300');
    }
}
