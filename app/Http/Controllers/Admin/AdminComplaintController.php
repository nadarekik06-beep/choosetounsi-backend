<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\SellerAdjustment;
use App\Services\Intigo\ExchangeSender;
use App\Services\Orders\DeliveryDocumentService;
use App\Services\Returns\ReturnPresenter;
use App\Services\Returns\ReturnService;
use App\Support\SellerPickup;
use Illuminate\Http\Request;

/**
 * Admin moderation of returns (role:admin route group).
 *
 *   GET   /api/admin/complaints[/stats|/{id}]
 *   GET   /api/admin/complaints/{id}/return-slip        PDF for the delivery company
 *   PATCH /api/admin/complaints/{id}/approve            approve (overrides a seller refusal)
 *   PATCH /api/admin/complaints/{id}/reject             reject (overrides a seller acceptance)
 *   PATCH /api/admin/complaints/{id}/schedule-pickup    pick-up booked with the delivery company
 *   PATCH /api/admin/complaints/{id}/picked-up          courier collected it
 *   PATCH /api/admin/complaints/{id}/receive            received & inspected (condition per item)
 *   PATCH /api/admin/complaints/{id}/refund             money sent (method + reference)
 *   PATCH /api/admin/complaints/{id}/cancel             stop a return before pick-up
 *   PATCH /api/admin/complaints/{id}/close              legacy exchange only
 *
 * Exchanges (the approval holds the replacement stock and books the Intigo parcel):
 *   PATCH /api/admin/complaints/{id}/exchange/book       book it at Intigo again (after a fix)
 *   PATCH /api/admin/complaints/{id}/exchange/nid        booked by hand / checked in Intigo: paste its nid
 *   PATCH /api/admin/complaints/{id}/exchange/delivered  manual: replacement delivered, old item collected
 *   PATCH /api/admin/complaints/{id}/exchange/failed     manual: refused at the door
 *   PATCH /api/admin/complaints/{id}/exchange/decide     failed exchange: retry | return | cancel
 *   (old item back at the seller: the same /receive as a return)
 */
class AdminComplaintController extends Controller
{
    public function __construct(private ReturnService $returns) {}

    public function stats()
    {
        $counts = Complaint::selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $n = fn(array $s) => (int) collect($s)->sum(fn($k) => $counts[$k] ?? 0);

        return response()->json([
            'success' => true,
            'data' => [
                'total'        => (int) $counts->sum(),
                'by_status'    => $counts,
                'needs_admin'  => $n(Complaint::ADMIN_DECISION_STATUSES),
                'to_schedule'  => $n([Complaint::STATUS_ADMIN_APPROVED]),
                'in_transit'   => $n([Complaint::STATUS_PICKUP_SCHEDULED, Complaint::STATUS_PICKED_UP]),
                'to_refund'    => $n([Complaint::STATUS_RETURNED_TO_SELLER]),
                'with_seller'  => $n([Complaint::STATUS_REQUESTED, Complaint::STATUS_SELLER_REJECTED]),
                'refunded'     => $n([Complaint::STATUS_REFUNDED]),
                'closed'       => $n([Complaint::STATUS_REJECTED, Complaint::STATUS_CANCELLED, Complaint::STATUS_CLOSED, Complaint::STATUS_EXCHANGE_COMPLETED]),
                // Exchanges
                'exchanges_in_transit' => $n([Complaint::STATUS_EXCHANGE_IN_TRANSIT]),
                'exchanges_failed'     => $n([Complaint::STATUS_EXCHANGE_FAILED]),
                'exchanges_to_book'    => Complaint::where('status', Complaint::STATUS_ADMIN_APPROVED)->where('resolution_type', Complaint::RESOLUTION_EXCHANGE)
                    ->whereNotNull('fee_size')->count(),
                'exchanges_needs_check' => Complaint::where('exchange_sync_status', Complaint::EXCHANGE_NEEDS_CHECK)->count(),
                'old_item_not_received' => Complaint::where('status', Complaint::STATUS_EXCHANGED)
                    ->where('exchanged_at', '<', now()->subDays(Complaint::OLD_ITEM_DAYS))->count(),
                'fee_exceeds_refund'   => Complaint::where('fee_exceeds_refund', true)->whereIn('status', Complaint::OPEN_STATUSES)->count(),
            ],
        ]);
    }

    public function index(Request $request)
    {
        $query = Complaint::with([
            'user:id,name,email',
            'seller:id,name,email',
            'order:id,order_number,total_amount,status,return_status,payment_method,guest_email,' . Complaint::BUYER_ORDER_COLUMNS,
        ]);

        if ($request->filled('status'))    $query->whereIn('status', explode(',', $request->status));
        if ($request->filled('seller_id')) $query->where('seller_id', $request->seller_id);
        if ($request->filled('from_date')) $query->whereDate('created_at', '>=', $request->from_date);
        if ($request->filled('to_date'))   $query->whereDate('created_at', '<=', $request->to_date);
        if ($request->filled('resolution')) $query->where('resolution_type', $request->resolution === 'exchange' ? Complaint::RESOLUTION_EXCHANGE : Complaint::RESOLUTION_RETURN_REFUND);
        if ($request->boolean('old_item_overdue')) {
            $query->where('status', Complaint::STATUS_EXCHANGED)->where('exchanged_at', '<', now()->subDays(Complaint::OLD_ITEM_DAYS));
        }
        if ($request->boolean('needs_check')) $query->where('exchange_sync_status', Complaint::EXCHANGE_NEEDS_CHECK);
        if ($request->boolean('fee_exceeds_refund')) $query->where('fee_exceeds_refund', true);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('reference', 'like', "%{$s}%")
                  ->orWhereHas('user',  fn($u) => $u->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"))
                  // Guests: name / phone / e-mail given at checkout
                  ->orWhereHas('order', fn($o) => $o->where(fn($w) => $w->where('order_number', 'like', "%{$s}%")
                      ->orWhere('guest_name', 'like', "%{$s}%")->orWhere('guest_phone', 'like', "%{$s}%")->orWhere('guest_email', 'like', "%{$s}%")));
            });
        }

        $complaints = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 15));
        Complaint::withItemSnapshots($complaints);
        Complaint::withBuyer($complaints, true);

        return response()->json(['success' => true, 'data' => $complaints]);
    }

    public function show(DeliveryDocumentService $documents, ExchangeSender $exchanges, $id)
    {
        $complaint = Complaint::with([
            'user:id,name,email',
            'seller:id,name,email',
            'seller.sellerApplication',
            'order:id,order_number,total_amount,status,return_status,payment_method,payment_status,shipping_fee,created_at,updated_at,wilaya,address,phone,phone_secondary,recipient_name,delegation,postal_code,notes,user_id,guest_email,' . Complaint::BUYER_ORDER_COLUMNS,
            'refundTask.deliveryGuy:id,name',
        ])->findOrFail($id);

        Complaint::withItemSnapshots($complaint);
        ReturnPresenter::forStaff($complaint);
        Complaint::withBuyer($complaint, true);

        $complaint->setAttribute('client_address', $documents->recipient($complaint->order));
        $complaint->setAttribute('seller_pickup', SellerPickup::for($complaint->seller, $complaint->seller_id));
        $complaint->setAttribute('refund_methods', $this->returns->refundMethodsFor($complaint->order));
        $complaint->setAttribute('cash_refund', $this->returns->isCashRefund($complaint));
        $complaint->setAttribute('adjustments', SellerAdjustment::where('complaint_id', $complaint->id)->get());
        $complaint->setAttribute('allowed_transitions', $complaint->allowedTransitions());

        // Fees for the payer switch before approval (recomputed live in the drawer)
        $complaint->setAttribute('fees', [
            'case_fee'     => round($complaint->caseFee(), 3),
            'return_fee'   => round((float) $complaint->return_shipping_fee, 3),
            'exchange_fee' => round((float) $complaint->exchange_fee, 3),
            'client_fee'   => round($complaint->clientFee(), 3),
            'seller_fee'   => round($complaint->sellerFee(), 3),
            'items_amount' => round((float) $complaint->items_amount, 3),
            'default_payer' => Complaint::payerFor((string) $complaint->complaint_type),
        ]);
        $complaint->setAttribute('costs', $complaint->costs()->orderBy('id')->get());

        if ($complaint->isExchange() && !$complaint->isLegacyExchange()) {
            $preview = $exchanges->preview($complaint);
            $complaint->setAttribute('exchange', [
                'original_nid' => $preview['original_nid'],
                'can_book'     => !$preview['problems'] && $preview['payload'] !== null,
                'problems'     => $preview['problems'],
                'payload'      => $preview['payload'],
                'cod'          => round($complaint->clientFee(), 3),
                'intigo_mode'  => config('intigo.mode'),
            ]);
        }

        return response()->json(['success' => true, 'data' => $complaint]);
    }

    public function returnSlip(Request $request, DeliveryDocumentService $documents, $id)
    {
        $complaint = Complaint::findOrFail($id);
        abort_if($complaint->isLegacyExchange(), 422, 'Legacy exchange requests have no slip.');

        $pdf = $documents->returnSlipPdf($complaint);
        $this->returns->event($complaint, 'slip_exported', $request->user(), 'admin', null);

        $name = 'CT-' . $complaint->reference . ($complaint->isExchange() ? '-exchange-slip.pdf' : '-return-slip.pdf');
        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control'       => 'no-store, private',
        ]);
    }

    public function approve(Request $request, $id)
    {
        $request->validate([
            'note'           => 'nullable|string|max:1000',
            'shipping_payer' => 'nullable|in:seller,client',
        ]);
        $c = $this->returns->adminApprove(Complaint::findOrFail($id), $request->user(), $request->note, $request->shipping_payer);

        if ($c->isExchange()) {
            return response()->json(['success' => true, 'message' => 'Exchange approved, replacement stock held. ' . $this->bookingMessage($c), 'data' => $c]);
        }
        return response()->json(['success' => true, 'message' => 'Return approved. Client and seller notified; the pick-up can be scheduled.', 'data' => $c]);
    }

    public function exchangeBook(Request $request, ExchangeSender $exchanges, $id)
    {
        $complaint = Complaint::findOrFail($id);
        abort_unless($complaint->isExchange() && !$complaint->isLegacyExchange(), 422, 'Not an exchange.');
        $r = $exchanges->book($complaint, $request->user());

        return response()->json(['success' => $r['result'] === Complaint::EXCHANGE_SENT, 'result' => $r['result'], 'message' => $r['message'], 'data' => $complaint->fresh()],
            $r['result'] === Complaint::EXCHANGE_SENT ? 200 : 422);
    }

    /** Booked by hand in the Intigo dashboard (or found there after a lost answer): its tracking number. */
    public function exchangeNid(Request $request, $id)
    {
        $data = $request->validate(['nid' => 'required|string|max:32']);
        $complaint = Complaint::findOrFail($id);
        abort_unless($complaint->isExchange() && !$complaint->isLegacyExchange(), 422, 'Not an exchange.');
        abort_if($complaint->exchange_sync_status === Complaint::EXCHANGE_SENDING, 422, 'A booking is in progress: wait a few seconds.');

        $c = $this->returns->exchangeShipped($complaint, $request->user(), 'admin', $data['nid'], 'manual');
        return response()->json(['success' => true, 'message' => "Exchange parcel {$c->intigo_exchange_nid} saved. The client has been notified.", 'data' => $c]);
    }

    public function exchangeDelivered(Request $request, $id)
    {
        $request->validate(['note' => 'nullable|string|max:500']);
        $c = $this->returns->markExchanged(Complaint::findOrFail($id), $request->user(), 'admin', $request->note);
        return response()->json(['success' => true, 'message' => 'Marked as exchanged. The seller confirms the old item when it is back.', 'data' => $c]);
    }

    public function exchangeFailed(Request $request, $id)
    {
        $request->validate(['note' => 'nullable|string|max:500']);
        $c = $this->returns->markExchangeFailed(Complaint::findOrFail($id), $request->user(), 'admin', $request->note);
        return response()->json(['success' => true, 'message' => 'Exchange marked as failed: decide what happens next.', 'data' => $c]);
    }

    public function exchangeDecide(Request $request, $id)
    {
        $data = $request->validate(['action' => 'required|in:retry,return,cancel', 'note' => 'nullable|string|max:1000']);
        $c = $this->returns->decideFailedExchange(Complaint::findOrFail($id), $request->user(), $data['action'], $data['note'] ?? null);

        $message = match ($data['action']) {
            'retry'  => $this->bookingMessage($c),
            'return' => 'Switched to a return: the replacement is back in stock and the pick-up can be scheduled.',
            default  => 'Exchange cancelled: the replacement is back in stock.',
        };
        return response()->json(['success' => true, 'message' => $message, 'data' => $c]);
    }

    private function bookingMessage(Complaint $c): string
    {
        return match ($c->exchange_sync_status) {
            Complaint::EXCHANGE_SENT        => "Intigo exchange parcel {$c->intigo_exchange_nid} booked.",
            Complaint::EXCHANGE_NEEDS_CHECK => 'Intigo did not answer clearly: check in Intigo and paste the tracking number (never re-sent automatically).',
            Complaint::EXCHANGE_SEND_FAILED => 'Intigo refused the exchange: ' . $c->exchange_sync_error,
            default                         => 'Book the exchange in the Intigo dashboard and paste its tracking number: ' . $c->exchange_sync_error,
        };
    }

    public function reject(Request $request, $id)
    {
        $request->validate(['rejection_reason' => 'required|string|min:10|max:1000']);
        $c = $this->returns->adminReject(Complaint::findOrFail($id), $request->user(), $request->rejection_reason);

        return response()->json(['success' => true, 'message' => 'Return rejected. The client has been notified.', 'data' => $c]);
    }

    /** Legacy route: confirm the seller's refusal. */
    public function confirmRejection(Request $request, $id)
    {
        $complaint = Complaint::findOrFail($id);
        $reason = $request->input('rejection_reason') ?: ($complaint->rejection_reason ?: 'Refusal confirmed by ChooseTounsi.');
        $c = $this->returns->adminReject($complaint, $request->user(), $reason);

        return response()->json(['success' => true, 'message' => 'Seller refusal confirmed. The client has been notified.', 'data' => $c]);
    }

    /** Legacy route: override the seller's refusal. */
    public function overrideToApproved(Request $request, $id)
    {
        return $this->approve($request, $id);
    }

    public function schedulePickup(Request $request, $id)
    {
        $request->validate([
            'note'     => 'nullable|string|max:500',
            'carrier'  => 'nullable|string|max:100',
            'date'     => 'nullable|date',
            'tracking' => 'nullable|string|max:100',
        ]);
        $c = $this->returns->schedulePickup(Complaint::findOrFail($id), $request->user(), 'admin', $request->note,
            array_filter($request->only('carrier', 'date', 'tracking')));

        return response()->json(['success' => true, 'message' => 'Pick-up scheduled. The client has been notified.', 'data' => $c]);
    }

    public function pickedUp(Request $request, $id)
    {
        $request->validate(['note' => 'nullable|string|max:500', 'courier' => 'nullable|string|max:100']);
        $c = $this->returns->markPickedUp(Complaint::findOrFail($id), $request->user(), 'admin', $request->note, $request->courier);

        return response()->json(['success' => true, 'data' => $c, 'message' => $c->refund_method === 'cash'
            ? 'Picked up — the courier paid the client back in cash. Finance updated, client notified.'
            : 'Marked as picked up.']);
    }

    public function receive(Request $request, $id)
    {
        $request->validate([
            'conditions'   => 'required|array|min:1',
            'conditions.*' => 'required|in:resaleable,damaged',
            'note'         => 'nullable|string|max:1000',
        ]);
        $c = $this->returns->receive(Complaint::findOrFail($id), $request->user(), 'admin', $request->conditions, $request->note);

        return response()->json(['success' => true, 'message' => 'Return received. Resaleable items are back in stock.', 'data' => $c]);
    }

    public function refund(Request $request, $id)
    {
        $request->validate([
            'method'    => 'required|in:wallet,bank_transfer,d17,original',
            'reference' => 'nullable|string|max:100',
            'note'      => 'nullable|string|max:1000',
        ]);
        $c = $this->returns->refund(Complaint::findOrFail($id), $request->user(), $request->input('method'), $request->reference, $request->note);

        return response()->json(['success' => true, 'message' => 'Refund recorded. Finance updated and client notified.', 'data' => $c]);
    }

    public function cancel(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $c = $this->returns->adminCancel(Complaint::findOrFail($id), $request->user(), $request->reason);

        return response()->json(['success' => true, 'message' => 'Return cancelled.', 'data' => $c]);
    }

    public function close(Request $request, $id)
    {
        $request->validate(['note' => 'nullable|string|max:1000']);
        $c = $this->returns->closeLegacyExchange(Complaint::findOrFail($id), $request->user(), $request->note);

        return response()->json(['success' => true, 'message' => 'Legacy exchange closed.', 'data' => $c]);
    }

    /**
     * PATCH /api/admin/complaints/{id}/intigo-return-ref
     * After-sale returns are booked by hand in the Intigo dashboard; the admin
     * pastes Intigo's reference here so the return file shows it.
     */
    public function intigoReturnRef(Request $request, $id)
    {
        $data = $request->validate(['intigo_return_ref' => 'nullable|string|max:64']);
        $complaint = Complaint::findOrFail($id);
        $complaint->forceFill(['intigo_return_ref' => trim((string) ($data['intigo_return_ref'] ?? '')) ?: null])->save();

        return response()->json(['success' => true, 'message' => 'Intigo return reference saved.', 'data' => ['intigo_return_ref' => $complaint->intigo_return_ref]]);
    }
}
