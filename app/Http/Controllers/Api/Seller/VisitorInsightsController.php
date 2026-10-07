<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Services\VisitorInsights\FunnelDiagnosis;
use App\Services\VisitorInsights\InsightActions;
use App\Services\VisitorInsights\VisitorInsights;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Analyse des visiteurs (Black Pepper — routes behind seller.feature:black_hub).
 *
 *   GET  /api/seller/black/visitor-insights?period=7|30|90
 *   POST /api/seller/black/visitor-insights/actions   {product_id, kind, problem_code?, stage?}
 */
class VisitorInsightsController extends Controller
{
    public function show(Request $request, VisitorInsights $insights): JsonResponse
    {
        $data = $request->validate(['period' => 'nullable|integer|in:' . implode(',', config('funnel.periods'))]);
        $days = (int) ($data['period'] ?? 30);

        return response()->json(['success' => true, 'data' => $insights->get((int) $request->user()->id, $days)]);
    }

    /** A listing edit / restock saved from a Visitor Insights action (promotions and boosts record themselves). */
    public function applied(Request $request, InsightActions $actions): JsonResponse
    {
        $data = $request->validate([
            'product_id'   => 'required|integer|min:1',
            'kind'         => 'required|string|in:edit,restock',
            'problem_code' => 'nullable|string|max:32',
            'stage'        => 'nullable|string|in:' . implode(',', FunnelDiagnosis::STAGES),
        ]);
        $id = $actions->record((int) $request->user()->id, (int) $data['product_id'], $data['kind'],
            $data['problem_code'] ?? null, $data['stage'] ?? null);

        return $id
            ? response()->json(['success' => true, 'data' => ['id' => $id]], 201)
            : response()->json(['success' => false, 'message' => __('messages.not_found.product')], 404);
    }
}
