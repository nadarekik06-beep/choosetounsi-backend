<?php

namespace App\Http\Controllers\Api\Client;

use App\Helpers\PlatformUser;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SellerApplication;
use App\Models\SellerOrder;
use App\Services\Orders\GuestOrderAccess;
use App\Services\Orders\GuestOrderClaimer;
use App\Services\Payments\ManualPaymentSettings;
use App\Support\GuestCheckout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Public tracking of a GUEST order (no account):
 *
 *   GET  /api/orders/track/{token}   the link from the confirmation page / e-mail
 *   POST /api/orders/track           order number + phone (lost link) → the same payload
 *                                    + a new token for this device
 *   POST /api/orders/track/all       order number + phone → every guest order of that
 *                                    phone (never by phone alone), each with a token
 *
 * Tokens are stored hashed only (GuestOrderAccess); wrong number / phone pairs
 * are limited per IP and per order number, always with one generic message.
 *
 * Shows what the buyer needs and nothing more: items, each parcel's shop and
 * status, delivery status, totals, the city, a masked street and a masked
 * phone. No e-mail, no full phone. Account orders are never shown here (they
 * live under /orders, behind the login).
 */
class OrderTrackingController extends Controller
{
    /** seller_orders.status → step shown to the buyer. */
    private const STEPS = [
        'pending'            => 'placed',
        'processing'         => 'placed',
        'confirmed'          => 'confirmed',
        'completed'          => 'packed',
        'handed_to_courier'  => 'shipped',
        'out_for_delivery'   => 'out_for_delivery',
        'delivered'          => 'delivered',
        'refused'            => 'refused',
        'returned_to_seller' => 'refused',
        'cancelled'          => 'cancelled',
        'refunded'           => 'refunded',
    ];

    public function __construct(private GuestOrderAccess $access) {}

    public function show(string $token)
    {
        $order = $this->access->resolve($token);
        if (!$order) {
            return $this->notFound();
        }
        return response()->json(['success' => true, 'data' => $this->payload($order)]);
    }

    public function lookup(Request $request)
    {
        $request->validate([
            'order_number' => 'required|string|max:40',
            'phone'        => 'required|string|max:30',
        ]);

        [$order, $limited] = $this->access->match($request, (string) $request->order_number, (string) $request->phone);
        if ($limited) {
            return $this->tooMany();
        }
        if (!$order) {
            return $this->notFound();
        }

        $token = $this->access->issue($order, 'lookup');
        return response()->json(['success' => true, 'data' => $this->payload($order) + ['tracking_token' => $token]]);
    }

    /**
     * POST /api/orders/track/all — "find all my orders": proven by ONE order
     * number + its phone, then every guest order of that phone not added to an
     * account, newest first, each with a token for this device.
     */
    public function all(Request $request)
    {
        $request->validate([
            'order_number' => 'required|string|max:40',
            'phone'        => 'required|string|max:30',
        ]);

        [$order, $limited] = $this->access->match($request, (string) $request->order_number, (string) $request->phone);
        if ($limited) {
            return $this->tooMany();
        }
        if (!$order) {
            return $this->notFound();
        }

        $orders = $this->access->ordersOfPhone((string) ($order->guest_phone ?: $order->phone));
        if (!$orders->contains('id', $order->id)) {
            $orders->prepend($order);
        }

        return response()->json(['success' => true, 'data' => $orders->map(fn (Order $o) => $this->summary($o) + [
            'tracking_token' => $this->access->issue($o, 'lookup'),
        ])->values()]);
    }

    /**
     * POST /api/orders/guest/claimable (signed in) {tokens: [...]}: which guest
     * orders saved on this device were placed with this account's phone / e-mail.
     */
    public function claimable(Request $request, GuestOrderClaimer $claimer)
    {
        return response()->json(['success' => true, 'data' => $claimer->claimable($request->user(), $this->tokensFrom($request))
            ->map(fn (Order $o) => $this->summary($o))->values()]);
    }

    /**
     * POST /api/orders/guest/claim (signed in) {tokens: [...]}: add them to my
     * account — on the buyer's request only, and only orders with my phone / e-mail.
     */
    public function claimMany(Request $request, GuestOrderClaimer $claimer)
    {
        return response()->json(['success' => true, 'claimed' => $claimer->claimTokens($request->user(), $this->tokensFrom($request))]);
    }

    /** POST /api/orders/track/{token}/claim (signed in): add this guest order to my account. */
    public function claim(Request $request, string $token)
    {
        $order = $this->access->resolve($token);
        if (!$order) {
            return $this->notFound();
        }
        if ((int) $order->user_id === (int) $request->user()->id) {
            return response()->json(['success' => true, 'already' => true]);
        }
        if (!app(\App\Services\Orders\GuestOrderClaimer::class)->claimOne($request->user(), $order)) {
            return response()->json(['success' => false, 'code' => 'not_yours', 'message' => __('messages.guest.claim_mismatch')], 403);
        }
        return response()->json(['success' => true]);
    }

    private function payload(Order $order): array
    {
        $order->loadMissing(['items', 'sellerOrders' => fn ($q) => $q->orderBy('id'), 'sellerOrders.seller:id,name']);
        $money = $order->clientMoneySummary();
        $shops = $this->shopNames($order->sellerOrders);

        $parcels = $order->sellerOrders->map(function (SellerOrder $so) use ($order, $shops) {
            return [
                'id'              => $so->id,
                'shop'            => $shops[$so->seller_id] ?? null,
                'status'          => $so->status,
                'step'            => self::STEPS[$so->status] ?? 'placed',
                'delivery_status' => $so->intigo_status_label ?: null,
                'delivery_at'     => optional($so->intigo_status_at)->toIso8601String(),
                'tracking_ref'    => $so->intigo_nid && $so->intigo_sync_status !== 'cancelled' ? $so->intigo_nid : null,
                'timeline'        => $so->intigo_sync_status !== 'cancelled' ? \App\Services\Intigo\IntigoStatusSync::timeline($so) : [],
                'items'           => $order->items->where('seller_order_id', $so->id)->values()->map(fn ($i) => [
                    'name'     => $i->product_name,
                    'variant'  => $i->variant_label,
                    'quantity' => (int) $i->quantity,
                    'total'    => round((float) ($i->net_total ?? $i->total ?? 0) + $i->includedFeeTotal(), 3),
                    'image'    => $this->imageUrl($i->image_url),
                ])->all(),
            ];
        })->values()->all();

        $delivered = $order->sellerOrders->contains(fn ($so) => $so->status === 'delivered')
            || $order->status === 'delivered';

        return [
            'order_number'   => $order->order_number,
            'placed_at'      => optional($order->created_at)->toIso8601String(),
            'status'         => $order->display_status,
            'payment_method' => $order->payment_method,
            'subtotal'       => $money['subtotal'],
            'discount'       => $money['discount_amount'],
            'shipping_fee'   => $money['shipping_fee'],
            'multi_item_discount' => $money['multi_item_discount'],
            'free_delivery'  => $money['free_delivery'],
            'total'          => $money['total'],
            'is_cancelled'   => $money['is_cancelled'],
            'recipient'      => $this->firstName($order->recipient_name ?: $order->guest_name),
            'phone_masked'   => GuestCheckout::maskPhone($order->phone),
            'city'           => $order->city_name ?: $order->wilaya,
            'address_masked' => GuestCheckout::maskAddress($order),
            'parcels'        => $parcels,
            // Returns for guests (v1): WhatsApp with the order number, once something was delivered
            // Signed-in viewers: whether "Add to my account" applies (claimed or not)
            'claimed'            => $order->user_id !== null,
            'can_request_return' => $delivered,
            // Guest returns: /api/guest/complaints, same form and rules as accounts.
            // WhatsApp stays as a help link.
            'returns_count'      => \App\Models\Complaint::where('order_id', $order->id)->count(),
            'whatsapp'           => $delivered ? (app(ManualPaymentSettings::class)->whatsappDigits() ?: null) : null,
        ];
    }

    /** One line per order in "my orders on this phone". */
    private function summary(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'placed_at'    => optional($order->created_at)->toIso8601String(),
            'status'       => $order->display_status,
            'total'        => round((float) $order->total_amount, 3),
            'items_count'  => (int) $order->items()->sum('quantity'),
            'claimed'      => $order->user_id !== null,
        ];
    }

    /** @return string[] */
    private function tokensFrom(Request $request): array
    {
        $request->validate(['tokens' => 'required|array|max:50', 'tokens.*' => 'string|max:64']);
        return array_values(array_filter($request->input('tokens', []), 'is_string'));
    }

    private function tooMany()
    {
        return response()->json(['success' => false, 'code' => 'rate_limited', 'message' => __('messages.guest.too_many_lookups')], 429);
    }

    /** seller_id → shop name ("ChooseTounsi" for the platform's own products). */
    private function shopNames($sellerOrders): array
    {
        $ids        = $sellerOrders->pluck('seller_id')->filter()->unique()->values();
        $platformId = PlatformUser::id();
        $business   = SellerApplication::whereIn('user_id', $ids)->approved()->orderByDesc('id')
            ->get(['user_id', 'business_name'])->unique('user_id')->pluck('business_name', 'user_id');

        return $ids->mapWithKeys(fn ($id) => [$id => ($platformId && (int) $id === (int) $platformId)
            ? config('app.name')
            : ($business[$id] ?? $sellerOrders->firstWhere('seller_id', $id)?->seller?->name)])->all();
    }

    private function imageUrl(?string $path): ?string
    {
        if (!$path) return null;
        if (preg_match('#^https?://#', $path)) return $path;
        // Snapshots are stored as "/storage/order-snapshots/…" (public URL path)
        return str_starts_with($path, '/') ? url($path) : Storage::disk('public')->url($path);
    }

    private function firstName(?string $name): ?string
    {
        $name = trim((string) $name);
        return $name === '' ? null : preg_split('/\s+/u', $name)[0];
    }

    private function notFound()
    {
        return response()->json(['success' => false, 'code' => 'order_not_found', 'message' => __('messages.guest.order_not_found')], 404);
    }
}
