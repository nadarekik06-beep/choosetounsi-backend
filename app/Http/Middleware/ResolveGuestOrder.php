<?php

namespace App\Http\Middleware;

use App\Services\Orders\GuestOrderAccess;
use Closure;
use Illuminate\Http\Request;

/**
 * Guest endpoints (/api/guest/…): the X-Order-Token header must open a guest
 * order (GuestOrderAccess). The order is put on the request as `guest_order`;
 * everything after it is scoped to that one order. Unknown token → the same
 * generic 404 as a wrong order number / phone.
 */
class ResolveGuestOrder
{
    public function __construct(private GuestOrderAccess $access) {}

    public function handle(Request $request, Closure $next)
    {
        $order = $this->access->resolve($request->header(GuestOrderAccess::HEADER));
        if (!$order) {
            return response()->json([
                'success' => false, 'code' => 'order_not_found', 'message' => __('messages.guest.order_not_found'),
            ], 404);
        }
        $request->attributes->set('guest_order', $order);
        return $next($request);
    }
}
