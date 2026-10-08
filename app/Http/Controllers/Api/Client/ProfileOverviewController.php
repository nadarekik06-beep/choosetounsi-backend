<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Order;
use App\Models\Review;
use App\Models\SellerApplication;
use App\Models\SellerFollow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The customer profile page's data: one call for the dashboard, plus the two
 * lists nothing else served (my reviews, shops I follow).
 */
class ProfileOverviewController extends Controller
{
    /** Order statuses grouped the way the storefront shows them. */
    private const STATUS_GROUPS = [
        'pending'   => ['pending'],
        'confirmed' => ['processing', 'confirmed'],
        'shipped'   => ['out_for_delivery'],
        'delivered' => ['delivered', 'completed'],
        'cancelled' => ['cancelled', 'refunded'],
    ];


    /** GET /api/profile/overview */
    public function overview(Request $request)
    {
        $user   = $request->user();
        $userId = $user->id;

        // Orders: one grouped query for counts + amounts.
        $byStatus = Order::where('user_id', $userId)
            ->selectRaw('status, COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS amount')
            ->groupBy('status')
            ->get()
            ->toBase()   // plain collection: Eloquent's only() would match model keys
            ->keyBy('status');

        $orders = ['total' => (int) $byStatus->sum('n')];
        foreach (self::STATUS_GROUPS as $group => $statuses) {
            $orders[$group] = (int) $byStatus->only($statuses)->sum('n');
        }
        $totalSpent = (float) $byStatus->only(self::STATUS_GROUPS['delivered'])->sum('amount');

        // Everything else: one row of sub-selects.
        $open   = Complaint::OPEN_STATUSES;
        $marks  = implode(', ', array_fill(0, count($open), '?'));
        $counts = DB::table('users')->where('users.id', $userId)->selectRaw('
            (SELECT COUNT(*) FROM reviews        WHERE reviews.user_id = users.id)        AS reviews,
            (SELECT COUNT(*) FROM favorites      WHERE favorites.user_id = users.id)      AS favorites,
            (SELECT COUNT(*) FROM seller_follows WHERE seller_follows.user_id = users.id) AS followed_sellers,
            (SELECT COUNT(*) FROM user_addresses WHERE user_addresses.user_id = users.id) AS addresses,
            (SELECT COUNT(*) FROM complaints WHERE complaints.user_id = users.id AND complaints.status IN (' . $marks . ')) AS complaints_open,
            (SELECT COUNT(*) FROM complaints WHERE complaints.user_id = users.id AND complaints.status NOT IN (' . $marks . ')) AS complaints_resolved,
            (SELECT COUNT(*) FROM notifications WHERE notifications.notifiable_type = ? AND notifications.notifiable_id = users.id AND notifications.audience = ? AND notifications.read_at IS NULL) AS unread_notifications,
            (SELECT COUNT(*) FROM wallet_transactions WHERE wallet_transactions.user_id = users.id) AS wallet_transactions,
            users.wallet_balance
        ', [...$open, ...$open, get_class($user), 'buyer'])->first();

        $recent = Order::where('user_id', $userId)
            ->withCount('items')
            ->with([
                // resolved_image_url: the line as bought (snapshot, else its own variant's image)
                'items' => fn($q) => $q->select('id', 'order_id', 'product_id', 'variant_id', 'variant_label', 'variant_attributes', 'product_name', 'image_url', 'quantity')
                    ->with([
                        'product' => fn($pq) => $pq->withTrashed()->select('id', 'name', 'slug')->with(['images', 'variants.attributeOptions.attribute']),
                        'variant.attributeOptions.attribute',
                    ]),
            ])
            ->latest()->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'order_number', 'status', 'return_status', 'payment_status', 'total_amount', 'created_at']);

        return response()->json(['success' => true, 'data' => [
            'profile' => ProfileApiController::payload($user),
            'stats'   => [
                'orders'               => $orders,
                'total_spent'          => round($totalSpent, 3),
                'reviews'              => (int) $counts->reviews,
                'favorites'            => (int) $counts->favorites,
                'followed_sellers'     => (int) $counts->followed_sellers,
                'addresses'            => (int) $counts->addresses,
                'complaints'           => [
                    'open'     => (int) $counts->complaints_open,
                    'resolved' => (int) $counts->complaints_resolved,
                ],
                'unread_notifications' => (int) $counts->unread_notifications,
                'wallet'               => [
                    'balance' => (float) $counts->wallet_balance,
                    'active'  => (float) $counts->wallet_balance > 0 || $counts->wallet_transactions > 0,
                ],
            ],
            'recent_orders' => $recent->map(fn(Order $o) => [
                'id'           => $o->id,
                'order_number' => $o->order_number,
                'status'       => $o->display_status,   // partially_returned shows as such
                'status_group' => $this->statusGroup($o->status),
                'payment_status' => $o->payment_status,
                'total_amount' => (float) $o->total_amount,
                'created_at'   => $o->created_at?->toISOString(),
                'items_count'  => (int) $o->items_count,
                'thumbnails'   => $o->items->take(4)->map(fn($i) => [
                    'name'  => $i->product_name ?: $i->product?->name,
                    'image' => $i->resolved_image_url,
                ])->values(),
            ]),
        ]]);
    }

    /** GET /api/profile/reviews — reviews I wrote, newest first. */
    public function reviews(Request $request)
    {
        $reviews = Review::where('user_id', $request->user()->id)
            ->with(['product' => fn($q) => $q->withTrashed()->select('id', 'name', 'slug')->with('primaryImage')])
            ->latest()
            ->paginate(min(50, max(1, (int) $request->query('per_page', 10))));

        $reviews->getCollection()->transform(fn(Review $r) => [
            'id'         => $r->id,
            'rating'     => (int) $r->rating,
            'body'       => $r->body,
            'status'     => $r->status,
            'created_at' => $r->created_at?->toISOString(),
            'product'    => $r->product ? [
                'id'    => $r->product->id,
                'name'  => $r->product->name,
                'slug'  => $r->product->slug,
                'image' => $r->product->primaryImage ? Storage::url($r->product->primaryImage->image_path) : null,
                'available' => !$r->product->trashed(),
            ] : null,
        ]);

        return response()->json(['success' => true, 'data' => $reviews]);
    }

    /** GET /api/profile/followed-sellers */
    public function followedSellers(Request $request)
    {
        $follows = SellerFollow::where('user_id', $request->user()->id)
            ->with('seller:id,name,avatar,is_active')
            ->latest()
            ->get();

        $follows = $follows->filter(fn($f) => $f->seller && $f->seller->is_active);

        // Store branding (same rule as User::storefrontBranding) in one query.
        $apps = SellerApplication::approved()
            ->whereIn('user_id', $follows->pluck('seller_id'))
            ->latest()
            ->get(['id', 'user_id', 'business_name', 'profile_picture'])
            ->unique('user_id')
            ->keyBy('user_id');

        $data = $follows->map(function ($f) use ($apps) {
            $app = $apps[$f->seller_id] ?? null;
            return [
                'id'          => $f->seller->id,
                'name'        => $app?->business_name ?? $f->seller->name,
                'avatar'      => $app?->profile_picture ? Storage::url($app->profile_picture) : $f->seller->avatar,
                'followed_at' => $f->created_at?->toISOString(),
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function statusGroup(string $status): string
    {
        foreach (self::STATUS_GROUPS as $group => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $group;
            }
        }
        return 'pending';
    }
}
