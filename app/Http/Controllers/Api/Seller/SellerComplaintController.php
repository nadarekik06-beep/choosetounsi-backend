<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Notifications\ComplaintStatusChangedNotification;
use App\Notifications\SellerRejectedComplaintNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * FILE: app/Http/Controllers/Api/Seller/SellerComplaintController.php  ← REPLACE
 *
 * Change from previous version:
 *   - index() / show() return complained_items: the lines the customer
 *     flagged, each as bought (Complaint::withItemSnapshots).
 *
 * All other methods (stats, index, addNote, approve, reject) are unchanged.
 */
class SellerComplaintController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────
    // GET /api/seller/complaints/stats
    // ─────────────────────────────────────────────────────────────────────

    public function stats(Request $request)
    {
        $sellerId = $request->user()->id;

        return response()->json([
            'success' => true,
            'data' => [
                'total'           => Complaint::forSeller($sellerId)->count(),
                'pending'         => Complaint::forSeller($sellerId)->pending()->count(),
                'reviewing'       => Complaint::forSeller($sellerId)->reviewing()->count(),
                'approved'        => Complaint::forSeller($sellerId)->approved()->count(),
                'seller_rejected' => Complaint::forSeller($sellerId)->sellerRejected()->count(),
                'rejected'        => Complaint::forSeller($sellerId)->rejected()->count(),
                'needs_action'    => Complaint::forSeller($sellerId)
                    ->whereIn('status', [Complaint::STATUS_PENDING, Complaint::STATUS_REVIEWING])
                    ->count(),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/seller/complaints
    // ─────────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $sellerId = $request->user()->id;
        $query    = Complaint::forSeller($sellerId)
            ->with([
                'user:id,name,email',
                'order:id,order_number,total_amount,status',
            ]);

        if ($request->filled('status'))    $query->where('status', $request->status);
        if ($request->filled('from_date')) $query->whereDate('created_at', '>=', $request->from_date);
        if ($request->filled('to_date'))   $query->whereDate('created_at', '<=', $request->to_date);

        $complaints = $query->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 12));

        Complaint::withItemSnapshots($complaints);

        return response()->json(['success' => true, 'data' => $complaints]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/seller/complaints/{id}
    // ─────────────────────────────────────────────────────────────────────

    public function show(Request $request, $id)
    {
        $complaint = Complaint::forSeller($request->user()->id)
            ->with([
                'user:id,name,email',
                'order:id,order_number,total_amount,status,created_at,wilaya,address,phone',
            ])
            ->findOrFail($id);

        // complained_items: the lines the buyer picked, as bought
        return response()->json(['success' => true, 'data' => Complaint::withItemSnapshots($complaint)]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PATCH /api/seller/complaints/{id}/note
    // ─────────────────────────────────────────────────────────────────────

    public function addNote(Request $request, $id)
    {
        $request->validate([
            'seller_note' => 'required|string|min:10|max:1000',
        ]);

        $complaint = Complaint::forSeller($request->user()->id)->findOrFail($id);

        if (!$complaint->sellerCanAct()) {
            return response()->json([
                'success' => false,
                'message' => __('seller.complaint.locked'),
            ], 422);
        }

        try {
            $complaint->markReviewing($request->seller_note);
        } catch (\Throwable $e) {
            Log::error('[SellerComplaint] markReviewing failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => __('seller.complaint.update_failed')], 500);
        }

        // Buyer: "the shop answered, your complaint is being examined"
        app(\App\Services\Notifications\BuyerNotifier::class)->send(
            $complaint->user, new \App\Notifications\Buyer\ComplaintNotification($complaint->fresh(), 'seller_replied')
        );

        return response()->json([
            'success' => true,
            'message' => __('seller.complaint.note_submitted'),
            'data'    => $complaint->fresh(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PATCH /api/seller/complaints/{id}/approve
    // ─────────────────────────────────────────────────────────────────────

    public function approve(Request $request, $id)
    {
        $request->validate([
            'seller_note' => 'nullable|string|max:1000',
        ]);

        $complaint = Complaint::forSeller($request->user()->id)
            ->with('user')
            ->findOrFail($id);

        if (!$complaint->sellerCanAct()) {
            return response()->json([
                'success' => false,
                'message' => __('seller.complaint.locked'),
            ], 422);
        }

        try {
            $complaint->sellerApprove($request->seller_note);
        } catch (\Throwable $e) {
            Log::error('[SellerComplaint] approve failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => __('seller.complaint.approve_failed')], 500);
        }

        try {
            app(\App\Services\Notifications\BuyerNotifier::class)->send($complaint->user, new ComplaintStatusChangedNotification($complaint->fresh()));
        } catch (\Throwable $e) {
            Log::error('[SellerComplaint] Approve notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => __('seller.complaint.approved'),
            'data'    => $complaint->fresh(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PATCH /api/seller/complaints/{id}/reject
    // ─────────────────────────────────────────────────────────────────────

    public function reject(Request $request, $id)
    {
        $request->validate([
            'seller_note'      => 'required|string|min:10|max:1000',
            'rejection_reason' => 'required|string|min:10|max:1000',
        ]);

        $seller    = $request->user();
        $complaint = Complaint::forSeller($seller->id)->findOrFail($id);

        if (!$complaint->sellerCanAct()) {
            return response()->json([
                'success' => false,
                'message' => __('seller.complaint.locked'),
            ], 422);
        }

        try {
            $complaint->sellerReject($request->seller_note, $request->rejection_reason);
        } catch (\Throwable $e) {
            Log::error('[SellerComplaint] reject failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => __('seller.complaint.reject_failed')], 500);
        }

        // Buyer: the shop contested it, the admin decides
        app(\App\Services\Notifications\BuyerNotifier::class)->send(
            $complaint->user, new \App\Notifications\Buyer\ComplaintNotification($complaint->fresh(), 'escalated')
        );

        try {
            $admins = \App\Models\User::where('role', 'admin')->where('is_active', true)->get();
            Notification::send($admins, new SellerRejectedComplaintNotification($complaint, $seller));
        } catch (\Throwable $e) {
            Log::error('[SellerComplaint] Reject admin notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => __('seller.complaint.rejected'),
            'data'    => $complaint->fresh(),
        ]);
    }
}