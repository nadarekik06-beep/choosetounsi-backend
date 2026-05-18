<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\ReviewMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * GET /api/products/{slug}/reviews
 *
 * Public endpoint — no auth required.
 * Returns summary + paginated approved reviews.
 *
 * Summary shape:
 *   average, total, distribution, top_tags,
 *   recent_photos, photo_count, verified_count, with_photos_count
 *
 * Review shape:
 *   id, rating, body, display_name, is_anonymous, is_verified,
 *   helpful_count, not_helpful_count, user_vote,
 *   tags[], media[], reply{body, seller_name, created_at}, created_at
 */
class ProductReviewController extends Controller
{
    public function index(Request $request, string $slug)
    {
        // ── 1. Resolve product ────────────────────────────────────────────────
        $product = \App\Models\Product::where('slug', $slug)->firstOrFail();

        // ── 2. Base query — approved reviews for this product only ────────────
        $base = Review::where('product_id', $product->id)
                      ->where('status', 'approved');

        // ── 3. Summary block ──────────────────────────────────────────────────
        $total   = (clone $base)->count();
        $average = $total > 0 ? round((clone $base)->avg('rating'), 1) : 0;

        // Rating distribution (1–5)
        $distribution = [];
        for ($star = 1; $star <= 5; $star++) {
            $count               = (clone $base)->where('rating', $star)->count();
            $distribution[$star] = [
                'count'   => $count,
                'percent' => $total > 0 ? round(($count / $total) * 100) : 0,
            ];
        }

        // Top tags used on this product's approved reviews
        $topTags = DB::table('review_tag_pivot as rtp')
            ->join('review_tags as rt', 'rt.id', '=', 'rtp.review_tag_id')
            ->join('reviews as r',      'r.id',  '=', 'rtp.review_id')
            ->where('r.product_id', $product->id)
            ->where('r.status', 'approved')
            ->select(
                'rt.id', 'rt.label', 'rt.label_fr', 'rt.sentiment', 'rt.icon',
                DB::raw('COUNT(*) as usage_count')
            )
            ->groupBy('rt.id', 'rt.label', 'rt.label_fr', 'rt.sentiment', 'rt.icon')
            ->orderByDesc('usage_count')
            ->limit(10)
            ->get();

        // Recent customer photos (approved media from approved reviews)
        $recentPhotos = ReviewMedia::whereHas('review', function ($q) use ($product) {
                $q->where('product_id', $product->id)
                  ->where('status', 'approved');
            })
            ->where('is_approved', true)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(12)
            ->get(['id', 'review_id', 'path'])
            ->map(fn($m) => [
                'id'        => $m->id,
                'review_id' => $m->review_id,
                'url'       => Storage::url($m->path),
            ]);

        $photoCount    = (clone $base)->whereHas('media')->count();
        $verifiedCount = (clone $base)->where('is_verified_purchase', true)->count();

        // ── 4. Build the paginated review query ───────────────────────────────
        $query = (clone $base)->with([
            'user:id,name',
            'media',        // scoped: approved + not deleted (see Review model)
            'tags',
            'reply.seller:id,name',
        ]);

        // Apply filter
        $filter = $request->query('filter', '');

        if ($filter === 'helpful') {
            $query->orderByDesc('helpful_count')->orderByDesc('created_at');
        } else {
            $query->latest();
        }

        if ($filter === 'with_photos') {
            $query->withPhotos();
        } elseif ($filter === 'verified') {
            $query->verified();
        }

        // Star filter
        if ($rating = $request->query('rating')) {
            $query->where('rating', (int) $rating);
        }

        // Tag filter
        if ($tagId = $request->query('tag_id')) {
            $query->whereHas('tags', fn($q) => $q->where('review_tags.id', (int) $tagId));
        }

        // ── 5. Resolve authenticated user for vote overlay ────────────────────
        $userId    = null;
        $userVotes = [];

        try {
            $user   = $request->user();
            $userId = $user?->id;
        } catch (\Exception $e) {
            // Public route — auth optional
        }

        $perPage = min((int) $request->query('per_page', 8), 30);
        $reviews = $query->paginate($perPage);

        if ($userId && $reviews->isNotEmpty()) {
            $reviewIds = $reviews->getCollection()->pluck('id')->toArray();
            $userVotes = \App\Models\ReviewVote::where('user_id', $userId)
                ->whereIn('review_id', $reviewIds)
                ->pluck('type', 'review_id')
                ->toArray();
        }

        // ── 6. Format review collection ───────────────────────────────────────
        $formatted = $reviews->getCollection()->map(function (Review $r) use ($userVotes) {
            return [
                'id'                => $r->id,
                'rating'            => $r->rating,
                'body'              => $r->body,
                'display_name'      => $r->display_name,
                'is_anonymous'      => $r->is_anonymous,
                'is_verified'       => $r->is_verified_purchase,
                'helpful_count'     => $r->helpful_count,
                'not_helpful_count' => $r->not_helpful_count,
                'user_vote'         => $userVotes[$r->id] ?? null,

                'tags' => $r->tags->map(fn($t) => [
                    'id'        => $t->id,
                    'label'     => $t->label,
                    'label_fr'  => $t->label_fr,
                    'sentiment' => $t->sentiment,
                    'icon'      => $t->icon,
                ])->values(),

                'media' => $r->media->map(fn($m) => [
                    'id'   => $m->id,
                    'url'  => $m->url,
                    'type' => $m->type,
                ])->values(),

                'reply' => $r->reply ? [
                    'body'        => $r->reply->body,
                    'seller_name' => $r->reply->seller?->name ?? 'Seller',
                    'created_at'  => $r->reply->created_at->format('Y-m-d'),
                ] : null,

                'created_at' => $r->created_at->format('M d, Y'),
            ];
        });

        return response()->json([
            'success' => true,
            'summary' => [
                'average'           => $average,
                'total'             => $total,
                'distribution'      => $distribution,
                'top_tags'          => $topTags,
                'recent_photos'     => $recentPhotos,
                'photo_count'       => $photoCount,
                'verified_count'    => $verifiedCount,
                'with_photos_count' => $photoCount,
            ],
            'data' => $formatted,
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page'    => $reviews->lastPage(),
                'per_page'     => $reviews->perPage(),
                'total'        => $reviews->total(),
            ],
        ]);
    }
}