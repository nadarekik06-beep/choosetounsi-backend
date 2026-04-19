<?php
// app/Http/Controllers/Admin/AdminSponsorshipController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sponsorship;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Admin-facing sponsorship management.
 *
 * Routes (add inside admin middleware group):
 *   GET    /api/admin/sponsorships/stats
 *   GET    /api/admin/sponsorships
 *   PATCH  /api/admin/sponsorships/{id}/cancel
 *   PATCH  /api/admin/sponsorships/{id}/boost
 */
class AdminSponsorshipController extends Controller
{
    // ── Stats ─────────────────────────────────────────────────────────────────

    public function stats(): JsonResponse
    {
        // Expire overdue before counting
        Sponsorship::expireOverdue();

        return response()->json([
            'success' => true,
            'data'    => [
                'total_active'      => Sponsorship::where('status', 'active')->count(),
                'total_all_time'    => Sponsorship::count(),
                'by_plan'           => Sponsorship::select('plan_type', \DB::raw('count(*) as count'))
                    ->where('status', 'active')
                    ->groupBy('plan_type')
                    ->pluck('count', 'plan_type'),
                'total_impressions' => Sponsorship::where('status', 'active')->sum('impressions'),
                'total_clicks'      => Sponsorship::where('status', 'active')->sum('clicks'),
                'total_revenue'     => Sponsorship::where('was_paid', true)->sum('amount_charged'),
            ],
        ]);
    }

    // ── List ─────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        Sponsorship::expireOverdue();

        $query = Sponsorship::with([
            'product:id,name,slug,is_active,is_approved',
            'seller:id,name,email',
        ])->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($plan = $request->query('plan_type')) {
            $query->where('plan_type', $plan);
        }
        if ($search = $request->query('search')) {
            $query->whereHas('product', fn($q) => $q->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('seller', fn($q) => $q->where('name', 'like', "%{$search}%"));
        }

        $sponsorships = $query->paginate((int) $request->query('per_page', 20));

        $sponsorships->getCollection()->transform(function ($s) {
            if ($s->product) {
                $img = \App\Models\ProductImage::where('product_id', $s->product->id)
                    ->where('is_primary', true)
                    ->first();
                $s->product->image_url = $img ? Storage::url($img->image_path) : null;
            }
            return $s;
        });

        return response()->json(['success' => true, 'data' => $sponsorships]);
    }

    // ── Admin cancel ─────────────────────────────────────────────────────────

    public function cancel(int $id): JsonResponse
    {
        $s = Sponsorship::findOrFail($id);

        if ($s->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Sponsorship is already ' . $s->status,
            ], 422);
        }

        $s->update(['status' => 'cancelled']);
        Sponsorship::syncProductFlags($s->product_id);

        return response()->json(['success' => true, 'message' => 'Sponsorship cancelled by admin.']);
    }

    // ── Admin boost override ─────────────────────────────────────────────────

    public function boost(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'boost_score' => 'required|integer|min:1|max:100',
        ]);

        $s = Sponsorship::findOrFail($id);
        $s->update(['boost_score' => $request->boost_score]);
        Sponsorship::syncProductFlags($s->product_id);

        return response()->json([
            'success' => true,
            'message' => 'Boost score updated.',
            'data'    => $s->fresh(),
        ]);
    }
}