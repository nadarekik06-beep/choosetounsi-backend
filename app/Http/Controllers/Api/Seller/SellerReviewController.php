<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\{Review, ReviewReply, ReviewReport, ReviewTag};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Log};

class SellerReviewController extends Controller
{
    private function sellerId(Request $request): int
    {
        return $request->user()->id;
    }

    // ── GET /api/seller/reviews ───────────────────────────────────────────────
    /**
     * Paginated list of reviews for the seller's products.
     * Filters: ?status=pending|approved|flagged, ?rating=1..5, ?has_reply=0|1
     */
    public function index(Request $request)
    {
        $sellerId = $this->sellerId($request);

        $query = Review::with(['user:id,name', 'product:id,name,slug', 'tags', 'media', 'reply', 'reports'])
            ->forSeller($sellerId);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', ['approved', 'flagged', 'pending']);
        }

        if ($rating = $request->query('rating')) {
            $query->where('rating', (int) $rating);
        }

        if ($request->query('has_reply') === '0') {
            $query->doesntHave('replies');
        } elseif ($request->query('has_reply') === '1') {
            $query->has('replies');
        }

        if ($request->query('with_photos') === '1') {
            $query->withPhotos();
        }

        $perPage = min((int) $request->query('per_page', 15), 50);
        $reviews = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $reviews->getCollection()->map(fn($r) => $this->formatReview($r)),
            'meta'    => [
                'current_page' => $reviews->currentPage(),
                'last_page'    => $reviews->lastPage(),
                'total'        => $reviews->total(),
            ],
        ]);
    }

    // ── GET /api/seller/reviews/stats ─────────────────────────────────────────
    public function stats(Request $request)
    {
        $sellerId = $this->sellerId($request);
        $base     = Review::forSeller($sellerId)->approved();

        $total   = (clone $base)->count();
        $average = $total > 0 ? round((clone $base)->avg('rating'), 2) : 0;

        // Rating breakdown
        $byRating = (clone $base)
            ->select('rating', DB::raw('COUNT(*) as count'))
            ->groupBy('rating')
            ->pluck('count', 'rating');

        $positive = (clone $base)->where('rating', '>=', 4)->count();
        $negative = (clone $base)->where('rating', '<=', 2)->count();

        // Response rate
        $withReply    = Review::forSeller($sellerId)->approved()->has('replies')->count();
        $responseRate = $total > 0 ? round(($withReply / $total) * 100, 1) : 0;

        // Pending reports count
        $pendingReports = ReviewReport::whereHas(
            'review', fn($q) => $q->where('seller_id', $sellerId)
        )->where('status', 'pending')->count();

        // With photos
        $withPhotos = (clone $base)->withPhotos()->count();

        // Best & worst rated products
        $productStats = Review::forSeller($sellerId)
            ->approved()
            ->select('product_id', DB::raw('AVG(rating) as avg_rating'), DB::raw('COUNT(*) as review_count'))
            ->with('product:id,name,slug')   // ← removed primary_image_url
            ->groupBy('product_id')
            ->having('review_count', '>=', 1)
            ->orderByDesc('avg_rating')
            ->get();
 
        $bestProducts  = $productStats->take(3)->map(fn($r) => [
            'product_id'   => $r->product_id,
            'name'         => $r->product?->name,
            'avg_rating'   => round($r->avg_rating, 1),
            'review_count' => $r->review_count,
        ]);
        $worstProducts = $productStats->sortBy('avg_rating')->take(3)->values()->map(fn($r) => [
            'product_id'   => $r->product_id,
            'name'         => $r->product?->name,
            'avg_rating'   => round($r->avg_rating, 1),
            'review_count' => $r->review_count,
        ]);

        // Top repeated tags (seller-wide sentiment analysis)
        $topTags = DB::table('review_tag_pivot as rtp')
            ->join('review_tags as rt', 'rt.id', '=', 'rtp.review_tag_id')
            ->join('reviews as r', 'r.id', '=', 'rtp.review_id')
            ->where('r.seller_id', $sellerId)
            ->where('r.status', 'approved')
            ->select(
                'rt.id', 'rt.label', 'rt.label_fr', 'rt.sentiment', 'rt.icon',
                DB::raw('COUNT(*) as usage_count')
            )
            ->groupBy('rt.id', 'rt.label', 'rt.label_fr', 'rt.sentiment', 'rt.icon')
            ->orderByDesc('usage_count')
            ->limit(8)
            ->get();

        // Repeated complaint alerts (negative tags with high count)
        $alerts = $topTags->where('sentiment', 'negative')->filter(fn($t) => $t->usage_count >= 3);

        // Monthly trend (last 6 months)
        $trend = Review::forSeller($sellerId)
            ->approved()
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                DB::raw('COUNT(*) as count'),
                DB::raw('ROUND(AVG(rating), 2) as avg_rating')
            )
            ->where('created_at', '>=', now()->subMonths(6))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'total'           => $total,
                'average_rating'  => $average,
                'positive_count'  => $positive,
                'negative_count'  => $negative,
                'neutral_count'   => $total - $positive - $negative,
                'with_photos'     => $withPhotos,
                'response_rate'   => $responseRate,
                'pending_reports' => $pendingReports,
                'by_rating'       => $byRating,
                'best_products'   => $bestProducts,
                'worst_products'  => $worstProducts,
                'top_tags'        => $topTags,
                'alerts'          => $alerts->values(),
                'trend'           => $trend,
            ],
        ]);
    }

    // ── POST /api/seller/reviews/{id}/reply ───────────────────────────────────
    public function reply(Request $request, int $id)
    {
        $sellerId = $this->sellerId($request);

        $review = Review::where('seller_id', $sellerId)->findOrFail($id);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        // Upsert — one reply per seller per review
        $reply = ReviewReply::updateOrCreate(
            ['review_id' => $id, 'seller_id' => $sellerId],
            ['body' => $data['body'], 'is_visible' => true]
        );

        return response()->json([
            'success' => true,
            'message' => 'Reply saved.',
            'data'    => [
                'id'         => $reply->id,
                'body'       => $reply->body,
                'created_at' => $reply->created_at->format('Y-m-d H:i'),
            ],
        ]);
    }

    // ── DELETE /api/seller/reviews/{id}/reply ─────────────────────────────────
    public function deleteReply(Request $request, int $id)
    {
        $sellerId = $this->sellerId($request);

        ReviewReply::where('review_id', $id)
            ->where('seller_id', $sellerId)
            ->delete();

        return response()->json(['success' => true]);
    }

    // ── POST /api/seller/reviews/{id}/report ──────────────────────────────────
    public function report(Request $request, int $id)
    {
        $sellerId = $this->sellerId($request);
        $data     = $request->validate([
            'reason' => ['required', 'in:spam,fake,inappropriate,offensive,other'],
            'note'   => ['nullable', 'string', 'max:500'],
        ]);

        $exists = ReviewReport::where('review_id', $id)->where('reported_by', $sellerId)->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Already reported.'], 409);
        }

        ReviewReport::create([
            'review_id'   => $id,
            'reported_by' => $sellerId,
            'reason'      => $data['reason'],
            'note'        => $data['note'] ?? null,
        ]);

        return response()->json(['success' => true, 'message' => 'Review reported to admin.']);
    }

    // ── Private: format review for seller dashboard ───────────────────────────
    private function formatReview(Review $r): array
    {
        return [
            'id'            => $r->id,
            'rating'        => $r->rating,
            'body'          => $r->body,
            'display_name'  => $r->display_name,
            'is_anonymous'  => $r->is_anonymous,
            'is_verified'   => $r->is_verified_purchase,
            'status'        => $r->status,
            'helpful_count' => $r->helpful_count,
            'tags'          => $r->tags->map(fn($t) => ['id' => $t->id, 'label' => $t->label, 'sentiment' => $t->sentiment, 'icon' => $t->icon]),
            'media'         => $r->allMedia->where('is_approved', true)->values()->map(fn($m) => ['id' => $m->id, 'url' => $m->url]),
            'reply'         => $r->reply ? ['id' => $r->reply->id, 'body' => $r->reply->body, 'created_at' => $r->reply->created_at->format('Y-m-d')] : null,
            'reports_count' => $r->reports->count(),
            'product'       => ['id' => $r->product?->id, 'name' => $r->product?->name, 'slug' => $r->product?->slug],
            'created_at'    => $r->created_at->format('Y-m-d H:i'),
        ];
    }
}