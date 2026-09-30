<?php
// app/Http/Controllers/Api/Seller/SponsorshipController.php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Services\Ads\AdRequest;
use App\Services\Ads\AdServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Transitional public endpoints from the old sponsoring system. Sellers manage
 * campaigns through /api/seller/ads/* (Ads\AdCampaignController & co.).
 *
 *   GET  /api/sponsored-products                  wrapper over the ad server
 *   POST /api/sponsorships/{id}/impression|click  deprecated no-ops (use POST /api/ads/events)
 */
class SponsorshipController extends Controller
{
    /**
     * Category pages get category_top ads, everything else home_row. Ads only
     * (no organic backfill); each carries sponsor_data.token for POST /api/ads/events.
     */
    public function publicFeed(Request $request): JsonResponse
    {
        $limit      = min(max(1, (int) $request->query('limit', 8)), 20);
        $categoryId = $request->filled('category_slug')
            ? DB::table('categories')->where('slug', $request->query('category_slug'))->value('id')
            : null;

        $req = AdRequest::fromHttp($request, $categoryId ? 'category_top' : 'home_row', [
            'limit'             => $limit,
            'contextCategoryId' => $categoryId ? (int) $categoryId : null,
        ]);

        return response()->json(['success' => true, 'data' => app(AdServer::class)->serve($req)['ads']]);
    }

    /**
     * Unsigned ids can't be trusted for billing or stats; events are reported with
     * ad tokens (POST /api/ads/events). Accepted and ignored until every caller has moved.
     */
    public function recordImpression(int $id): JsonResponse
    {
        return response()->json(['success' => true, 'deprecated' => 'POST /api/ads/events'], 202);
    }

    public function recordClick(int $id): JsonResponse
    {
        return response()->json(['success' => true, 'deprecated' => 'POST /api/ads/events'], 202);
    }
}
