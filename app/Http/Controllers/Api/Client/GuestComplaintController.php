<?php

namespace App\Http\Controllers\Api\Client;

use App\Models\Complaint;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Returns / exchanges of a GUEST order (no account), behind guest.order:
 * the X-Order-Token header opens exactly one order, and every query here is
 * scoped to it. Same rules, fees, photos and workflow as signed-in buyers —
 * only "whose" changes (see ComplaintController::ownedOrders()).
 *
 *   GET   /api/guest/complaints/eligible-orders   this order, if still returnable
 *   GET   /api/guest/complaints                   returns of this order
 *   GET   /api/guest/complaints/{id}              one of them
 *   POST  /api/guest/complaints                   request a return / exchange (multipart)
 *   PATCH /api/guest/complaints/{id}/escalate     contest the shop's refusal
 *   PATCH /api/guest/complaints/{id}/cancel       withdraw a request not answered yet
 */
class GuestComplaintController extends ComplaintController
{
    public function store(Request $request)
    {
        // The order is the token's. Another order id → the same 404 as an unknown order (no IDOR).
        $order = $this->guestOrder($request);
        if ($request->filled('order_id') && (int) $request->input('order_id') !== (int) $order->id) {
            return response()->json(['success' => false, 'message' => __('messages.complaint.order_not_found')], 404);
        }
        $request->merge(['order_id' => $order->id]);

        return parent::store($request);
    }

    protected function ownedOrders(Request $request): Builder
    {
        return Order::whereKey($this->guestOrder($request)->id);
    }

    protected function ownedComplaints(Request $request): Builder
    {
        return Complaint::where('order_id', $this->guestOrder($request)->id);
    }

    /** No account. The order's account if it was added to one since (its returns follow it). */
    protected function client(Request $request): ?User
    {
        return $this->guestOrder($request)->user;
    }

    private function guestOrder(Request $request): Order
    {
        $order = $request->attributes->get('guest_order');
        abort_unless($order instanceof Order, 404);
        return $order;
    }
}
