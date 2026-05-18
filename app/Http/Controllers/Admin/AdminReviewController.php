<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Review, ReviewMedia, ReviewReport, ReviewReply};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Storage, DB, Log};

class AdminReviewController extends Controller
{
    // ── GET /api/admin/reviews ────────────────────────────────────────────────
    public function index(Request $request)
    {
        $query = Review::with(['user:id,name,email', 'product:id,name,slug', 'media', 'reports'])
            ->withCount('reports');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($rating = $request->query('rating')) {
            $query->where('rating', (int) $rating);
        }

        if ($request->query('has_reports') === '1') {
            $query->has('reports');
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $reviews = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $reviews->getCollection()->map(fn($r) => [
                'id'            => $r->id,
                'rating'        => $r->rating,
                'body'          => $r->body,
                'status'        => $r->status,
                'is_verified'   => $r->is_verified_purchase,
                'helpful_count' => $r->helpful_count,
                'reports_count' => $r->reports_count,
                'user'          => ['id' => $r->user?->id, 'name' => $r->user?->name, 'email' => $r->user?->email],
                'product'       => ['id' => $r->product?->id, 'name' => $r->product?->name],
                'media_count'   => $r->allMedia->count(),
                'created_at'    => $r->created_at->format('Y-m-d H:i'),
            ]),
            'meta'    => [
                'current_page' => $reviews->currentPage(),
                'last_page'    => $reviews->lastPage(),
                'total'        => $reviews->total(),
            ],
        ]);
    }

    // ── GET /api/admin/reviews/stats ──────────────────────────────────────────
    public function stats()
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'total'           => Review::count(),
                'approved'        => Review::where('status', 'approved')->count(),
                'pending'         => Review::where('status', 'pending')->count(),
                'flagged'         => Review::where('status', 'flagged')->count(),
                'rejected'        => Review::where('status', 'rejected')->count(),
                'pending_reports' => ReviewReport::where('status', 'pending')->count(),
            ],
        ]);
    }

    // ── PATCH /api/admin/reviews/{id}/approve ─────────────────────────────────
    public function approve(Request $request, int $id)
    {
        Review::findOrFail($id)->update(['status' => 'approved', 'rejection_reason' => null]);
        return response()->json(['success' => true, 'message' => 'Review approved.']);
    }

    // ── PATCH /api/admin/reviews/{id}/reject ──────────────────────────────────
    public function reject(Request $request, int $id)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        Review::findOrFail($id)->update([
            'status'           => 'rejected',
            'rejection_reason' => $data['reason'] ?? null,
        ]);

        return response()->json(['success' => true, 'message' => 'Review rejected.']);
    }

    // ── PATCH /api/admin/reviews/{id}/flag ────────────────────────────────────
    public function flag(Request $request, int $id)
    {
        Review::findOrFail($id)->update(['status' => 'flagged']);
        return response()->json(['success' => true]);
    }

    // ── DELETE /api/admin/reviews/{id} ────────────────────────────────────────
    public function destroy(int $id)
    {
        $review = Review::with('allMedia')->findOrFail($id);

        // Delete stored images
        foreach ($review->allMedia as $media) {
            Storage::disk('public')->delete($media->path);
        }

        $review->delete();
        return response()->json(['success' => true, 'message' => 'Review deleted.']);
    }

    // ── DELETE /api/admin/review-media/{id} ───────────────────────────────────
    /**
     * Remove a specific customer photo without deleting the review.
     */
    public function destroyMedia(int $id)
    {
        $media = ReviewMedia::findOrFail($id);
        Storage::disk('public')->delete($media->path);
        $media->delete();
        return response()->json(['success' => true, 'message' => 'Image removed.']);
    }

    // ── PATCH /api/admin/review-media/{id}/hide ───────────────────────────────
    public function hideMedia(int $id)
    {
        ReviewMedia::findOrFail($id)->update(['is_approved' => false]);
        return response()->json(['success' => true]);
    }

    // ── GET /api/admin/review-reports ─────────────────────────────────────────
    public function reports(Request $request)
    {
        $query = ReviewReport::with([
            'review:id,rating,body,status,product_id',
            'review.product:id,name',
            'reporter:id,name,email',
        ]);

        if ($status = $request->query('status', 'pending')) {
            $query->where('status', $status);
        }

        $reports = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $reports->getCollection(),
            'meta'    => ['current_page' => $reports->currentPage(), 'last_page' => $reports->lastPage(), 'total' => $reports->total()],
        ]);
    }

    // ── PATCH /api/admin/review-reports/{id}/resolve ──────────────────────────
    public function resolveReport(Request $request, int $id)
    {
        $data   = $request->validate(['action' => ['required', 'in:dismiss,flag_review,reject_review']]);
        $report = ReviewReport::findOrFail($id);
        $report->update(['status' => 'reviewed']);

        if ($data['action'] === 'flag_review') {
            $report->review->update(['status' => 'flagged']);
        } elseif ($data['action'] === 'reject_review') {
            $report->review->update(['status' => 'rejected']);
        }

        return response()->json(['success' => true]);
    }

    // ── DELETE /api/admin/review-replies/{id} ─────────────────────────────────
    public function destroyReply(int $id)
    {
        ReviewReply::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Reply deleted.']);
    }
}