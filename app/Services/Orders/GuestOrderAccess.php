<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Support\TunisianPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Who may open a GUEST order (no account): only whoever holds one of its device
 * tokens, or who knows its order number AND the phone used at checkout.
 * Never by order id alone.
 *
 *   issue()   a new 64-char random token for this order; only its sha256 is stored
 *             (guest_order_tokens). Checkout gets one; every successful
 *             "number + phone" lookup gets its own (one per phone / browser).
 *   resolve() token → order (null when unknown or malformed)
 *   match()   order number + phone → order, with failed attempts limited per IP
 *             and per order number (config guest_checkout.lookup_*).
 */
class GuestOrderAccess
{
    public const HEADER = 'X-Order-Token';
    private const TOKEN_REGEX = '/^[A-Za-z0-9]{32,64}$/';
    /** Device tokens kept per order (the oldest go first). */
    private const MAX_TOKENS_PER_ORDER = 20;

    public function issue(Order $order, string $source = 'checkout'): string
    {
        $token = Str::random(64);
        DB::table('guest_order_tokens')->insert([
            'order_id'   => $order->id,
            'token_hash' => self::hash($token),
            'source'     => $source,
            'created_at' => now(),
        ]);

        $keep = DB::table('guest_order_tokens')->where('order_id', $order->id)
            ->orderByDesc('id')->limit(self::MAX_TOKENS_PER_ORDER)->pluck('id');
        if ($keep->count() >= self::MAX_TOKENS_PER_ORDER) {
            DB::table('guest_order_tokens')->where('order_id', $order->id)->whereNotIn('id', $keep)->delete();
        }

        $order->plainAccessToken = $token;
        return $token;
    }

    /** Guest order opened by this token, or null. */
    public function resolve(?string $token): ?Order
    {
        if (!is_string($token) || !preg_match(self::TOKEN_REGEX, $token)) {
            return null;
        }

        $row = DB::table('guest_order_tokens')->where('token_hash', self::hash($token))->first(['id', 'order_id', 'last_used_at']);
        $order = $row
            ? Order::where('id', $row->order_id)->where('is_guest', true)->first()
            // Safety net: a plain token written before guest_order_tokens existed
            : Order::where('tracking_token', $token)->where('is_guest', true)->first();

        if ($row && $order && (!$row->last_used_at || now()->diffInMinutes($row->last_used_at) >= 60)) {
            DB::table('guest_order_tokens')->where('id', $row->id)->update(['last_used_at' => now()]);
        }
        return $order;
    }

    /** The token sent by the storefront (header, else route / body), resolved. */
    public function fromRequest(Request $request, ?string $routeToken = null): ?Order
    {
        return $this->resolve($routeToken ?? $request->header(self::HEADER) ?? $request->input('token'));
    }

    /**
     * Order number + phone → the guest order, or null. Only FAILED attempts
     * count towards the limit, so a buyer who types right is never blocked.
     *
     * @return array{0: ?Order, 1: bool} [order, rate limited]
     */
    public function match(Request $request, string $orderNumber, string $phone): array
    {
        $number = strtoupper(preg_replace('/\s+/', '', $orderNumber));
        $keys   = $this->limiterKeys($request, $number);

        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return [null, true];
            }
        }

        $digits = TunisianPhone::normalize($phone);
        $order  = TunisianPhone::isValid($digits)
            ? Order::where('order_number', $number)->where('is_guest', true)
                ->where(fn ($q) => $q->where('guest_phone', $digits)->orWhere('phone', $digits))
                ->first()
            : null;

        if (!$order) {
            $decay = max(1, (int) config('guest_checkout.lookup_decay_minutes', 10)) * 60;
            foreach (array_keys($keys) as $key) {
                RateLimiter::hit($key, $decay);
            }
        }
        return [$order, false];
    }

    /**
     * Every guest order placed with this phone that no account has taken over,
     * newest first. Only call it once match() proved the phone.
     */
    public function ordersOfPhone(string $phone, int $limit = 20)
    {
        $digits = TunisianPhone::normalize($phone);
        if (!TunisianPhone::isValid($digits)) {
            return collect();
        }
        return Order::where('is_guest', true)->whereNull('user_id')
            ->where(fn ($q) => $q->where('guest_phone', $digits)->orWhere('phone', $digits))
            ->orderByDesc('id')->limit($limit)->get();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array<string, int> limiter key => max failed attempts */
    private function limiterKeys(Request $request, string $number): array
    {
        return [
            'guest-lookup-ip:' . $request->ip()            => max(1, (int) config('guest_checkout.lookup_failures_per_ip', 5)),
            'guest-lookup-order:' . sha1($number)          => max(1, (int) config('guest_checkout.lookup_failures_per_order', 5)),
        ];
    }
}
