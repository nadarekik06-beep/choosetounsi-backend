<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCommissionOverride;
use App\Models\ProductVariant;
use App\Models\SellerApplication;
use App\Services\CommissionService;
use App\Services\Orders\OrderPricing;
use App\Services\ProductCommissionPreview;
use App\Support\Millimes;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CommissionController
 *
 * Provides LIVE PREVIEW commission data to the seller frontend.
 * This is estimation only — the REAL calculation is done in CheckoutController.
 *
 * Route: POST /api/seller/commission/calculate
 */
class CommissionController extends Controller
{
    public function __construct(
        private CommissionService $commission,
        private ProductCommissionPreview $preview,
        private OrderPricing $pricing,
    ) {}

    /**
     * POST /api/seller/commission/calculate
     *
     * Body: { price: float, quantity?: int }
     *
     * Returns full breakdown + upgrade suggestions.
     * Reads the seller's active plan from seller_applications.plan.
     */
    public function calculate(Request $request): JsonResponse
    {
        $request->validate([
            'price'    => 'required|numeric|min:0',
            'quantity' => 'nullable|integer|min:1|max:9999',
            // Original (pre-discount) unit price: the bracket comes from it (AUDIT M-7)
            'tier_price' => 'nullable|numeric|min:0',
            // The seller's own product: a live admin special commission on it applies to the preview
            'product_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        $productId = $request->filled('product_id') ? (int) $request->product_id : null;
        if ($productId !== null && !Product::where('id', $productId)->where('seller_id', $user->id)->exists()) {
            return response()->json(['success' => false, 'message' => __('seller.common.product_not_found')], 404);
        }

        // Fetch active plan — defaults to 'free' if no application exists
        $application = SellerApplication::where('user_id', $user->id)->first();
        $plan        = $application?->plan ?? 'free';

        $price    = (float) $request->price;
        $quantity = (int)   ($request->quantity ?? 1);

        // Same resolution as checkout (seller override → plan → default)
        $tier             = $request->filled('tier_price') ? max((float) $request->tier_price, $price) : null;
        $breakdown        = $this->preview->forPrice($user->id, $productId, $price, $tier, $quantity);
        $upgradeSuggestions = $this->commission->getUpgradeSavings($price, $plan, $quantity);

        return response()->json([
            'success' => true,
            'data'    => array_merge($breakdown, [
                'upgrade_suggestions' => $upgradeSuggestions,
            ]),
        ]);
    }

    /**
     * POST /api/seller/commission/pack-preview
     *
     * Body: { pack_price, quantity?, items: [{ product_id, quantity, variant_id? }] }
     *
     * What checkout will charge a pack: the price spread over its lines like
     * OrderPricing::packLines(), each line with ITS product's rate (special commission
     * included). Same code path as checkout (OrderPricing::previewPack).
     */
    public function packPreview(Request $request): JsonResponse
    {
        $d = $request->validate([
            'pack_price'         => 'required|numeric|min:0.001',
            'quantity'           => 'nullable|integer|min:1|max:999',
            'items'              => 'required|array|min:1|max:50',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity'   => 'required|integer|min:1|max:999',
            'items.*.variant_id' => 'nullable|integer',
        ]);
        $sellerId = $request->user()->id;

        $items = [];
        foreach ($d['items'] as $row) {
            $product = Product::where('id', $row['product_id'])->where('seller_id', $sellerId)->first();
            if (!$product) {
                return response()->json(['success' => false, 'message' => __('seller.common.products_not_yours')], 404);
            }
            $variant = !empty($row['variant_id']) ? ProductVariant::where('id', $row['variant_id'])->where('product_id', $product->id)->first() : null;
            $items[] = ['product' => $product, 'variant' => $variant, 'per_pack' => (int) $row['quantity']];
        }

        $r = $this->pricing->previewPack($sellerId, Millimes::of($d['pack_price']), $items, (int) ($d['quantity'] ?? 1));
        $net = $r['price_m'];

        return response()->json(['success' => true, 'data' => [
            'pack_price'      => Millimes::toFloat($net),
            // Same field names as /commission/calculate, so the form's preview card renders either answer
            'unit_price'      => Millimes::toFloat($net),
            'plan_used'       => $this->commission->resolveForSeller($sellerId, 0.0)['plan'],
            'base_rate'       => 0.0,
            'plan_reduction'  => 0.0,
            'saved_with_plan' => 0.0,
            'upgrade_suggestions' => [],
            'commission_amount' => Millimes::toFloat($r['commission_m']),
            'seller_amount'   => Millimes::toFloat($r['seller_m']),
            // Effective rate over the whole pack (lines can differ)
            'commission_percentage' => $net > 0 ? round($r['commission_m'] / $net * 100, 2) : 0.0,
            'special_offer'   => collect($r['lines'])->contains(fn ($l) => $l['override_id'] !== null),
            'lines'           => $r['lines'],
        ]]);
    }

    /** GET /api/seller/commission/upgrade-nudge — the "upgrade and save" figures, from the seller's real last 30 days. */
    public function upgradeNudge(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->commission->upgradeNudge($request->user()->id)]);
    }

    /**
     * GET /api/seller/commission/offers
     *
     * The special commissions live or scheduled on this seller's products (product list
     * badges). Never carries the admin's note.
     */
    public function offers(Request $request): JsonResponse
    {
        $now = Carbon::now();
        $rows = ProductCommissionOverride::where('seller_id', $request->user()->id)->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->with('product:id,price')->orderBy('id')->get();

        return response()->json(['success' => true, 'data' => $rows->map(function ($o) use ($now, $request) {
            $price = $o->product ? (float) $o->product->price : 0.0;
            $calc  = $this->commission->calculateForSeller($request->user()->id, $price, 1, 0.0, null, 0, $o->product_id, $o->starts_at && $o->starts_at->gt($now) ? $o->starts_at : $now);
            return [
                'product_id' => $o->product_id,
                'type'       => $o->type,
                'value'      => $o->value,
                'rate'       => $calc['commission_percentage'],   // at the product's current price
                'starts_at'  => optional($o->starts_at)->toIso8601String(),
                'ends_at'    => optional($o->ends_at)->toIso8601String(),
                'status'     => $o->statusAt($now),
            ];
        })->values()]);
    }

    /**
     * GET /api/seller/shipping-cost
     *
     * Kept for older builds of the seller dashboard only. Sellers no longer
     * offer (or pay for) free delivery: it is included in the displayed price
     * and handled by ChooseTounsi, so nothing is ever charged to them here.
     */
    public function shippingCost(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'free_delivery_contribution' => 0.0,
                'shipping_cost'              => 0.0,
                'customer_delivery_fee'      => 0.0,
                'delivery_included'          => true,
            ],
        ]);
    }
}
