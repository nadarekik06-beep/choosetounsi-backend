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
 * Changes from v1:
 *   - Added approve()  → status = APPROVED (direct, no admin needed)
 *   - Added reject()   → status = SELLER_REJECTED_PENDING_ADMIN + notifies admins
 *   - addNote() preserved as-is (still usable for adding notes without decision)
 *
 * Routes needed (add to api.php seller group):
 *   PATCH /api/seller/complaints/{id}/approve
 *   PATCH /api/seller/complaints/{id}/reject
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
                'total'                     => Complaint::forSeller($sellerId)->count(),
                'pending'                   => Complaint::forSeller($sellerId)->pending()->count(),
                'reviewing'                 => Complaint::forSeller($sellerId)->reviewing()->count(),
                'approved'                  => Complaint::forSeller($sellerId)->approved()->count(),
                'seller_rejected'           => Complaint::forSeller($sellerId)->sellerRejected()->count(),
                'rejected'                  => Complaint::forSeller($sellerId)->rejected()->count(),
                'needs_action'              => Complaint::forSeller($sellerId)
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

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $complaints = $query->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 12));

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
                'order.items:id,order_id,product_name,quantity,unit_price,total',
            ])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $complaint]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PATCH /api/seller/complaints/{id}/note
    // Preserved from v1 — seller adds note, transitions to reviewing.
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
                'message' => 'This complaint can no longer be updated by the seller.',
            ], 422);
        }

        try {
            $complaint->markReviewing($request->seller_note);
        } catch (\Throwable $e) {
            Log::error('[SellerComplaint] markReviewing failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to update complaint.'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Note submitted. The complaint is now under review.',
            'data'    => $complaint->fresh(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PATCH /api/seller/complaints/{id}/approve  ← NEW
    // Seller approves → status = APPROVED (direct, admin not needed)
    // Client is notified.
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
                'message' => 'This complaint can no longer be updated by the seller.',
            ], 422);
        }

        try {
            $complaint->sellerApprove($request->seller_note);
        } catch (\Throwable $e) {
            Log::error('[SellerComplaint] approve failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to approve complaint.'], 500);
        }

        // Notify the client
        try {
            $complaint->user->notify(new ComplaintStatusChangedNotification($complaint));
        } catch (\Throwable $e) {
            Log::error('[SellerComplaint] Approve notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Complaint approved. The client has been notified.',
            'data'    => $complaint->fresh(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PATCH /api/seller/complaints/{id}/reject  ← NEW
    // Seller rejects → status = SELLER_REJECTED_PENDING_ADMIN
    // Admin is notified immediately. Client is NOT notified yet.
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
                'message' => 'This complaint can no longer be updated by the seller.',
            ], 422);
        }

        try {
            $complaint->sellerReject(
                $request->seller_note,
                $request->rejection_reason
            );
        } catch (\Throwable $e) {
            Log::error('[SellerComplaint] reject failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to reject complaint.'], 500);
        }

        // Notify all admins — they must validate this rejection
        try {
            $admins = \App\Models\User::where('role', 'admin')
                ->where('is_active', true)
                ->get();
            Notification::send($admins, new SellerRejectedComplaintNotification($complaint, $seller));
        } catch (\Throwable $e) {
            Log::error('[SellerComplaint] Reject admin notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Rejection submitted. Admin has been notified and will make the final decision.',
            'data'    => $complaint->fresh(),
        ]);
    }
}