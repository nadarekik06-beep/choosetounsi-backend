<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Seller Complaint Controller
 *
 * Sellers can only see complaints related to THEIR products.
 * They can add a seller note and mark as reviewing.
 * Final approve/reject is admin-only.
 *
 * Routes (under auth:sanctum middleware, prefix /api/seller):
 *   GET   /api/seller/complaints            → index()
 *   GET   /api/seller/complaints/stats      → stats()
 *   GET   /api/seller/complaints/{id}       → show()
 *   PATCH /api/seller/complaints/{id}/note  → addNote()
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
                'total'     => Complaint::forSeller($sellerId)->count(),
                'pending'   => Complaint::forSeller($sellerId)->pending()->count(),
                'reviewing' => Complaint::forSeller($sellerId)->reviewing()->count(),
                'approved'  => Complaint::forSeller($sellerId)->approved()->count(),
                'rejected'  => Complaint::forSeller($sellerId)->rejected()->count(),
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
    // Seller adds a note and transitions status → reviewing.
    // ─────────────────────────────────────────────────────────────────────

    public function addNote(Request $request, $id)
    {
        $request->validate([
            'seller_note' => 'required|string|min:10|max:1000',
        ]);

        $complaint = Complaint::forSeller($request->user()->id)
            ->findOrFail($id);

        // Only act on pending complaints
        if (!$complaint->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'This complaint has already been reviewed.',
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
            'message' => 'Your note has been submitted. The complaint is now under admin review.',
            'data'    => $complaint->fresh(),
        ]);
    }
}