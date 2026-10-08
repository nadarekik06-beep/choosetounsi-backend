<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\SellerAdjustment;
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
                'closed'       => $n([Complaint::STATUS_REJECTED, Complaint::STATUS_CANCELLED, Complaint::STATUS_CLOSED]),
            ],
        ]);
    }

    public function index(Request $request)
    {
        $query = Complaint::with([
            'user:id,name,email',
            'seller:id,name,email',
            'order:id,order_number,total_amount,status,return_status,payment_method',
        ]);

        if ($request->filled('status'))    $query->whereIn('status', explode(',', $request->status));
        if ($request->filled('seller_id')) $query->where('seller_id', $request->seller_id);
        if ($request->filled('from_date')) $query->whereDate('created_at', '>=', $request->from_date);
        if ($request->filled('to_date'))   $query->whereDate('created_at', '<=', $request->to_date);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('reference', 'like', "%{$s}%")
                  ->orWhereHas('user',  fn($u) => $u->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"))
                  ->orWhereHas('order', fn($o) => $o->where('order_number', 'like', "%{$s}%"));
            });
        }

        $complaints = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 15));
        Complaint::withItemSnapshots($complaints);

        return response()->json(['success' => true, 'data' => $complaints]);
    }

    public function show(DeliveryDocumentService $documents, $id)
    {
        $complaint = Complaint::with([
            'user:id,name,email',
            'seller:id,name,email',
            'seller.sellerApplication',
            'order:id,order_number,total_amount,status,return_status,payment_method,payment_status,shipping_fee,created_at,updated_at,wilaya,address,phone,phone_secondary,recipient_name,delegation,postal_code,notes,user_id',
            'refundTask.deliveryGuy:id,name',
        ])->findOrFail($id);

        Complaint::withItemSnapshots($complaint);
        ReturnPresenter::forStaff($complaint);

        $complaint->setAttribute('client_address', $documents->recipient($complaint->order));
        $complaint->setAttribute('seller_pickup', SellerPickup::for($complaint->seller, $complaint->seller_id));
        $complaint->setAttribute('refund_methods', $this->returns->refundMethodsFor($complaint->order));
        $complaint->setAttribute('cash_refund', $this->returns->isCashRefund($complaint));
        $complaint->setAttribute('adjustments', SellerAdjustment::where('complaint_id', $complaint->id)->get());
        $complaint->setAttribute('allowed_transitions', Complaint::TRANSITIONS[$complaint->status] ?? []);

        return response()->json(['success' => true, 'data' => $complaint]);
    }

    public function returnSlip(Request $request, DeliveryDocumentService $documents, $id)
    {
        $complaint = Complaint::findOrFail($id);
        abort_if($complaint->isExchange(), 422, 'Legacy exchange requests have no return slip.');

        $pdf = $documents->returnSlipPdf($complaint);
        $this->returns->event($complaint, 'slip_exported', $request->user(), 'admin', null);

        $name = 'CT-' . $complaint->reference . '-return-slip.pdf';
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

        return response()->json(['success' => true, 'message' => 'Return approved. Client and seller notified; the pick-up can be scheduled.', 'data' => $c]);
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
}
