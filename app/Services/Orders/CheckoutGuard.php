<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Support\GuestCheckout;
use App\Support\TunisianPhone;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Safety net around order creation (POST /checkout and /checkout/buy-now).
 *
 * 1. Idempotency: the storefront sends one `idempotency_key` (UUID) per
 *    confirmation. It is stored on the order (unique column): the same key
 *    again returns that order instead of creating a second one, including when
 *    both requests arrive together (the database refuses the second insert).
 *    Requests without a key still work (older storefront) and are logged.
 * 2. Throttle: the same buyer cannot place the same lines again within
 *    RECENT_SECONDS, nor two orders at the same moment (short lock).
 *
 * Nothing here touches pricing, stock, coupons, notifications or delivery.
 */
class CheckoutGuard
{
    /** Same buyer + same lines + same payment method within this window = duplicate */
    public const RECENT_SECONDS = 10;
    /** One order placement per buyer at a time (expires by itself if PHP dies) */
    private const LOCK_SECONDS = 30;
    /** How long the exact first response is kept for a repeated key */
    private const RESPONSE_TTL_SECONDS = 86400;

    public static function rules(): array
    {
        return ['idempotency_key' => 'nullable|string|min:16|max:64|regex:/^[A-Za-z0-9\-_]+$/'];
    }

    /** The request's key (validated by rules()), or null — logged, still accepted. */
    public function key(Request $request): ?string
    {
        $key = $request->input('idempotency_key');
        if (is_string($key) && $key !== '') {
            return $key;
        }
        Log::warning('[Checkout] order request without idempotency_key', [
            'path'    => $request->path(),
            'user_id' => $request->user()?->id,
            'ip'      => $request->ip(),
            'agent'   => substr((string) $request->userAgent(), 0, 160),
        ]);
        return null;
    }

    /**
     * The order already placed with this key, as the same response the first
     * request got (HTTP 200). Another buyer's key: refused (409). Null = new key.
     */
    public function replay(?string $key, ?User $user, Request $request): ?JsonResponse
    {
        if ($key === null) {
            return null;
        }
        $order = Order::where('idempotency_key', $key)->first();
        if (!$order) {
            return null;
        }
        if (!$this->sameBuyer($order, $user, $request)) {
            Log::warning('[Checkout] idempotency_key reused by another buyer', ['order_id' => $order->id, 'user_id' => $user?->id, 'ip' => $request->ip()]);
            return response()->json([
                'success' => false,
                'code'    => 'idempotency_conflict',
                'message' => __('messages.checkout.idempotency_conflict'),
            ], 409);
        }

        Log::info('[Checkout] repeated idempotency_key: existing order returned', ['order_id' => $order->id]);
        $cached = Cache::get($this->responseCacheKey($key));
        return response()->json(is_array($cached) ? $cached : $this->responseFromOrder($order), 200);
    }

    /** Insert refused by the unique index: the same key won the race a moment ago. */
    public function isDuplicateKey(\Throwable $e): bool
    {
        return $e instanceof QueryException
            && ($e->errorInfo[1] ?? null) === 1062
            && str_contains($e->getMessage(), 'idempotency_key');
    }

    /**
     * Same buyer, same lines, same payment method placed a moment ago, or an
     * order of this buyer being placed right now: 429 (nothing is created).
     * The same key sent twice at once waits for the first and gets its order.
     * On success, returns the lock to release after the order (always, in finally).
     *
     * @return array{0: ?JsonResponse, 1: ?Lock}
     */
    public function begin(Request $request, ?User $user, array $quote, string $paymentMethod, ?string $key): array
    {
        if (Cache::has($this->recentKey($request, $user, $quote, $paymentMethod))) {
            Log::warning('[Checkout] identical order refused (placed seconds ago)', ['user_id' => $user?->id, 'ip' => $request->ip()]);
            return [$this->replay($key, $user, $request) ?? $this->tooSoon(), null];
        }
        try {
            $lock = Cache::lock('checkout:placing:' . $this->buyerKey($request, $user), self::LOCK_SECONDS);
            if (!$lock->get()) {
                if ($key !== null) {
                    try {
                        $lock->block(8);   // the first request of this buyer finishes, then decide
                    } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                        return [$this->tooSoon(), null];
                    }
                    if ($replay = $this->replay($key, $user, $request)) {
                        $this->release($lock);
                        return [$replay, null];
                    }
                    if (Cache::has($this->recentKey($request, $user, $quote, $paymentMethod))) {
                        $this->release($lock);
                        return [$this->tooSoon(), null];
                    }
                    return [null, $lock];
                }
                Log::warning('[Checkout] order refused: another order of this buyer is being placed', ['user_id' => $user?->id, 'ip' => $request->ip()]);
                return [$this->tooSoon(), null];
            }
            return [null, $lock];
        } catch (\Throwable $e) {
            // The cache store has no locks / is down: the idempotency key still protects
            Log::warning('[Checkout] placement lock unavailable: ' . $e->getMessage());
            return [null, null];
        }
    }

    /** After the order is committed: remember it for repeated keys and identical requests. */
    public function placed(?string $key, array $response, Request $request, ?User $user, array $quote, string $paymentMethod): void
    {
        try {
            if ($key !== null) {
                Cache::put($this->responseCacheKey($key), $response, self::RESPONSE_TTL_SECONDS);
            }
            Cache::put($this->recentKey($request, $user, $quote, $paymentMethod), $response['order_id'] ?? true, self::RECENT_SECONDS);
        } catch (\Throwable $e) {
            Log::warning('[Checkout] guard cache write failed: ' . $e->getMessage());
        }
    }

    public function release(?Lock $lock): void
    {
        try {
            $lock?->release();
        } catch (\Throwable $e) {
            // expires by itself
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function tooSoon(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code'    => 'duplicate_order',
            'message' => __('messages.checkout.duplicate_order'),
        ], 429);
    }

    private function sameBuyer(Order $order, ?User $user, Request $request): bool
    {
        if ($user) {
            return (int) $order->user_id === (int) $user->id;
        }
        $phone = TunisianPhone::normalize((string) $request->input('phone', ''));
        return $order->user_id === null && $order->is_guest && $order->guest_phone !== null && $order->guest_phone === $phone;
    }

    private function buyerKey(Request $request, ?User $user): string
    {
        if ($user) {
            return 'u:' . $user->id;
        }
        $phone = TunisianPhone::normalize((string) $request->input('phone', ''));
        return 'g:' . sha1(($phone ?: '') . '|' . (GuestCheckout::cartToken($request) ?: $request->ip()));
    }

    /** Buyer + exact lines (product / variant / pack, quantity) + payment method. */
    private function recentKey(Request $request, ?User $user, array $quote, string $paymentMethod): string
    {
        $lines = [];
        foreach ($quote['parcels'] ?? [] as $parcel) {
            foreach ($parcel['lines'] ?? [] as $line) {
                $lines[] = ($line['kind'] ?? 'product') === 'pack'
                    ? 'k' . ($line['pack']->id ?? '?') . 'x' . ($line['pack_quantity'] ?? 1)
                    : 'p' . ($line['product']->id ?? '?') . 'v' . ($line['variant']->id ?? 0) . 'x' . ($line['quantity'] ?? 1);
            }
        }
        sort($lines);
        return 'checkout:recent:' . sha1($this->buyerKey($request, $user) . '|' . $paymentMethod . '|' . implode(',', array_unique($lines)));
    }

    private function responseCacheKey(string $key): string
    {
        return 'checkout:idem:' . sha1($key);
    }

    /** Same fields as CheckoutController::orderResponse(), rebuilt from the order (cache expired). */
    private function responseFromOrder(Order $order): array
    {
        return [
            'success'         => true,
            'message'         => __('messages.checkout.order_placed'),
            'order_number'    => $order->order_number,
            'order_id'        => $order->id,
            'subtotal'        => (float) $order->subtotal,
            'discount_amount' => (float) $order->discount_amount,
            'delivery_fee'    => (float) $order->shipping_fee,
            'shipping_fee'    => (float) $order->shipping_fee,
            'total'           => (float) $order->total_amount,
            'parcels'         => [],
            'seller_count'    => $order->sellerOrders()->count(),
            'needs_payment'   => $order->payment_method === 'card' && $order->payment_status !== 'paid',
        ] + ($order->is_guest ? [
            'is_guest'       => true,
            // Replayed after the cache expired: a fresh device token (only hashes are stored)
            'tracking_token' => $order->tracking_token ?: app(GuestOrderAccess::class)->issue($order, 'checkout'),
            'phone'          => $order->phone,
            'email'          => $order->guest_email,
            'has_account'    => false,
        ] : []);
    }
}
