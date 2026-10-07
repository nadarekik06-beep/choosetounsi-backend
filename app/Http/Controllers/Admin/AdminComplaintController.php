<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Notifications\ComplaintStatusChangedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * FILE: app/Http/Controllers/Admin/AdminComplaintController.php  ← REPLACE
 *
 * Change from previous version:
 *   - index() / show() return complained_items: the lines the customer
 *     flagged, each as bought (Complaint::withItemSnapshots).
 *
 * All other methods (stats, index, approve, reject, confirmRejection,
 * overrideToApproved) are unchanged.
 */
class AdminComplaintController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────
    // GET /api/admin/complaints/stats
    // ─────────────────────────────────────────────────────────────────────

    public function stats()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'total'           => Complaint::count(),
                'pending'         => Complaint::pending()->count(),
                'reviewing'       => Complaint::reviewing()->count(),
                'approved'        => Complaint::approved()->count(),
                'seller_rejected' => Complaint::sellerRejected()->count(),
                'rejected'        => Complaint::rejected()->count(),
                'needs_admin'     => Complaint::sellerRejected()->count(),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/admin/complaints
    // ─────────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $query = Complaint::with([
            'user:id,name,email',
            'seller:id,name,email',
            'order:id,order_number,total_amount,status',
        ]);

        if ($request->filled('status'))    $query->where('status', $request->status);
        if ($request->filled('seller_id')) $query->where('seller_id', $request->seller_id);
        if ($request->filled('from_date')) $query->whereDate('created_at', '>=', $request->from_date);
        if ($request->filled('to_date'))   $query->whereDate('created_at', '<=', $request->to_date);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->whereHas('user',  fn($u) => $u->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"))
                  ->orWhereHas('order', fn($o) => $o->where('order_number', 'like', "%{$s}%"));
            });
        }

        $complaints = $query->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 15));

        Complaint::withItemSnapshots($complaints);

        return response()->json(['success' => true, 'data' => $complaints]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/admin/complaints/{id}
    // ─────────────────────────────────────────────────────────────────────

    public function show($id)
    {
        $complaint = Complaint::with([
            'user:id,name,email',
            'seller:id,name,email',
            'order:id,order_number,total_amount,status,created_at,wilaya,address,phone',
        ])->findOrFail($id);

        // complained_items: the lines the buyer picked, as bought
        return response()->json(['success' => true, 'data' => Complaint::withItemSnapshots($complaint)]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PATCH /api/admin/complaints/{id}/approve
    // ─────────────────────────────────────────────────────────────────────

    public function approve($id)
    {
        $complaint = Complaint::with('user')->findOrFail($id);

        if ($complaint->isResolved()) {
            return response()->json([
                'success' => false,
                'message' => 'This complaint has already been resolved.',
            ], 422);
        }

        $complaint->approve();

        try {
            app(\App\Services\Notifications\BuyerNotifier::class)->send($complaint->user, new ComplaintStatusChangedNotification($complaint->fresh()));
        } catch (\Throwable $e) {
            Log::error('[AdminComplaint] Approve notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Complaint approved. The client has been notified.',
            'data'    => $complaint->fresh(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PATCH /api/admin/complaints/{id}/reject
    // ─────────────────────────────────────────────────────────────────────

    public function reject(Request $request, $id)
    {
        $request->validate([
            'rejection_reason' => 'required|string|min:10|max:1000',
        ]);

        $complaint = Complaint::with('user')->findOrFail($id);

        if ($complaint->isResolved()) {
            return response()->json([
                'success' => false,
                'message' => 'This complaint has already been resolved.',
            ], 422);
        }

        $complaint->reject($request->rejection_reason);

        try {
            app(\App\Services\Notifications\BuyerNotifier::class)->send($complaint->user, new ComplaintStatusChangedNotification($complaint->fresh()));
        } catch (\Throwable $e) {
            Log::error('[AdminComplaint] Reject notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Complaint rejected. The client has been notified.',
            'data'    => $complaint->fresh(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PATCH /api/admin/complaints/{id}/confirm-rejection
    // ─────────────────────────────────────────────────────────────────────

    public function confirmRejection($id)
    {
        $complaint = Complaint::with('user')->findOrFail($id);

        if (!$complaint->isSellerRejectedPendingAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'This action is only available for complaints awaiting admin decision on seller rejection.',
            ], 422);
        }

        $complaint->confirmRejection();

        try {
            app(\App\Services\Notifications\BuyerNotifier::class)->send($complaint->user, new ComplaintStatusChangedNotification($complaint->fresh()));
        } catch (\Throwable $e) {
            Log::error('[AdminComplaint] ConfirmRejection notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Seller\'s rejection confirmed. Client has been notified.',
            'data'    => $complaint->fresh(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PATCH /api/admin/complaints/{id}/override-approve
    // ─────────────────────────────────────────────────────────────────────

    public function overrideToApproved($id)
    {
        $complaint = Complaint::with('user')->findOrFail($id);

        if (!$complaint->isSellerRejectedPendingAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'This action is only available for complaints awaiting admin decision on seller rejection.',
            ], 422);
        }

        $complaint->overrideToApproved();

        try {
            app(\App\Services\Notifications\BuyerNotifier::class)->send($complaint->user, new ComplaintStatusChangedNotification($complaint->fresh()));
        } catch (\Throwable $e) {
            Log::error('[AdminComplaint] OverrideApprove notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Seller\'s rejection overridden. Complaint approved. Client has been notified.',
            'data'    => $complaint->fresh(),
        ]);
    }
}