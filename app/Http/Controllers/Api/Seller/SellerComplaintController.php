<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Services\Returns\ReturnPresenter;
use App\Services\Returns\ReturnService;
use Illuminate\Http\Request;

/**
 * Returns on the seller's sales (owner only: forSeller scope everywhere).
 *
 *   GET   /api/seller/complaints[/stats|/{id}]
 *   PATCH /api/seller/complaints/{id}/note      note to the client (status unchanged)
 *   PATCH /api/seller/complaints/{id}/approve   accept → the admin validates
 *   PATCH /api/seller/complaints/{id}/reject    refuse with a reason → the client may escalate
 *   PATCH /api/seller/complaints/{id}/receive   parcel back: condition per item (restock)
 */
class SellerComplaintController extends Controller
{
    public function __construct(private ReturnService $returns) {}

    public function stats(Request $request)
    {
        $sellerId = $request->user()->id;
        $counts = Complaint::forSeller($sellerId)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $n = fn(array $s) => (int) collect($s)->sum(fn($k) => $counts[$k] ?? 0);

        return response()->json([
            'success' => true,
            'data' => [
                'total'          => (int) $counts->sum(),
                'by_status'      => $counts,
                'needs_action'   => $n([Complaint::STATUS_REQUESTED, Complaint::STATUS_PICKED_UP]),
                'requested'      => $n([Complaint::STATUS_REQUESTED]),
                'in_progress'    => $n([Complaint::STATUS_SELLER_ACCEPTED, Complaint::STATUS_ESCALATED, Complaint::STATUS_ADMIN_APPROVED, Complaint::STATUS_PICKUP_SCHEDULED, Complaint::STATUS_PICKED_UP, Complaint::STATUS_RETURNED_TO_SELLER]),
                'refunded'       => $n([Complaint::STATUS_REFUNDED]),
                'refused'        => $n([Complaint::STATUS_SELLER_REJECTED, Complaint::STATUS_REJECTED]),
            ],
        ]);
    }

    public function index(Request $request)
    {
        $query = Complaint::forSeller($request->user()->id)
            ->with(['user:id,name,email', 'order:id,order_number,total_amount,status,return_status']);

        if ($request->filled('status'))    $query->whereIn('status', explode(',', $request->status));
        if ($request->filled('from_date')) $query->whereDate('created_at', '>=', $request->from_date);
        if ($request->filled('to_date'))   $query->whereDate('created_at', '<=', $request->to_date);

        $complaints = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 12));
        Complaint::withItemSnapshots($complaints);

        return response()->json(['success' => true, 'data' => $complaints]);
    }

    public function show(Request $request, $id)
    {
        $complaint = Complaint::forSeller($request->user()->id)
            ->with(['user:id,name,email', 'order:id,order_number,total_amount,status,return_status,created_at,wilaya,address,phone', 'refundTask'])
            ->findOrFail($id);

        Complaint::withItemSnapshots($complaint);

        return response()->json(['success' => true, 'data' => ReturnPresenter::forStaff($complaint)]);
    }

    public function addNote(Request $request, $id)
    {
        $request->validate(['seller_note' => 'required|string|min:10|max:1000']);

        $complaint = Complaint::forSeller($request->user()->id)->findOrFail($id);
        if (!$complaint->sellerCanAct()) {
            return response()->json(['success' => false, 'message' => __('seller.complaint.locked')], 422);
        }

        $complaint->update(['seller_note' => $request->seller_note, 'reviewed_at' => now()]);
        $this->returns->event($complaint, 'seller_note', $request->user(), 'seller', $request->seller_note);

        // Client: "the shop answered, your request is being examined"
        app(\App\Services\Notifications\BuyerNotifier::class)->send(
            $complaint->user, new \App\Notifications\Buyer\ComplaintNotification($complaint->fresh(), 'seller_replied')
        );

        return response()->json(['success' => true, 'message' => __('seller.complaint.note_submitted'), 'data' => $complaint->fresh()]);
    }

    public function approve(Request $request, $id)
    {
        $request->validate(['seller_note' => 'nullable|string|max:1000']);
        $complaint = Complaint::forSeller($request->user()->id)->findOrFail($id);

        $c = $this->returns->sellerAccept($complaint, $request->user(), $request->seller_note);

        return response()->json(['success' => true, 'message' => __('seller.complaint.approved'), 'data' => $c]);
    }

    public function reject(Request $request, $id)
    {
        $request->validate([
            'rejection_reason' => 'required|string|min:10|max:1000',
            'seller_note'      => 'nullable|string|max:1000',
        ]);
        $complaint = Complaint::forSeller($request->user()->id)->findOrFail($id);

        $c = $this->returns->sellerReject($complaint, $request->user(), $request->rejection_reason, $request->seller_note);

        return response()->json(['success' => true, 'message' => __('seller.complaint.rejected'), 'data' => $c]);
    }

    public function receive(Request $request, $id)
    {
        $request->validate([
            'conditions'   => 'required|array|min:1',
            'conditions.*' => 'required|in:resaleable,damaged',
            'note'         => 'nullable|string|max:1000',
        ]);
        $complaint = Complaint::forSeller($request->user()->id)->findOrFail($id);

        $c = $this->returns->receive($complaint, $request->user(), 'seller', $request->conditions, $request->note);

        return response()->json(['success' => true, 'message' => __('seller.complaint.received'), 'data' => $c]);
    }
}
