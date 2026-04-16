<?php
// app/Http/Controllers/Admin/AdminVipRequestController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VipRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminVipRequestController extends Controller
{
    // ═══════════════════════════════════════════════════════════════════════
    // GET /api/admin/vip-requests
    //
    // Query params:
    //   status  — filter by status (pending|in_progress|completed|rejected)
    //   type    — filter by type   (reel|promotion|support)
    //   search  — search by seller name or email
    //   per_page — default 20, max 100
    // ═══════════════════════════════════════════════════════════════════════
    public function index(Request $request): JsonResponse
    {
        $query = VipRequest::with(['user:id,name,email,avatar', 'handler:id,name'])
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($search = $request->query('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $requests = $query->paginate($perPage);

        $data = $requests->getCollection()->map(fn ($r) => $this->formatRequest($r));

        return response()->json([
            'success' => true,
            'data'    => $data,
            'meta'    => [
                'current_page' => $requests->currentPage(),
                'last_page'    => $requests->lastPage(),
                'total'        => $requests->total(),
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GET /api/admin/vip-requests/stats
    // ═══════════════════════════════════════════════════════════════════════
    public function stats(): JsonResponse
    {
        $counts = VipRequest::selectRaw("
            COUNT(*) as total,
            SUM(status = 'pending')     as pending,
            SUM(status = 'in_progress') as in_progress,
            SUM(status = 'completed')   as completed,
            SUM(status = 'rejected')    as rejected,
            SUM(type = 'reel')          as reel,
            SUM(type = 'promotion')     as promotion,
            SUM(type = 'support')       as support
        ")->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'total'       => (int) $counts->total,
                'pending'     => (int) $counts->pending,
                'in_progress' => (int) $counts->in_progress,
                'completed'   => (int) $counts->completed,
                'rejected'    => (int) $counts->rejected,
                'by_type'     => [
                    'reel'      => (int) $counts->reel,
                    'promotion' => (int) $counts->promotion,
                    'support'   => (int) $counts->support,
                ],
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GET /api/admin/vip-requests/{id}
    // ═══════════════════════════════════════════════════════════════════════
    public function show(int $id): JsonResponse
    {
        $vipRequest = VipRequest::with(['user:id,name,email,avatar', 'handler:id,name'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $this->formatRequest($vipRequest),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PATCH /api/admin/vip-requests/{id}/approve
    //
    // Body (optional): { admin_note: string }
    // Sets status → 'in_progress'
    // ═══════════════════════════════════════════════════════════════════════
    public function approve(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'admin_note' => 'nullable|string|max:1000',
        ]);

        $vipRequest = VipRequest::findOrFail($id);

        if (!in_array($vipRequest->status, ['pending', 'in_progress'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending or in-progress requests can be approved.',
            ], 422);
        }

        $vipRequest->update([
            'status'     => 'in_progress',
            'admin_note' => $request->input('admin_note') ?? $vipRequest->admin_note,
            'handled_by' => auth()->id(),
            'handled_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Request marked as in progress.',
            'data'    => $this->formatRequest($vipRequest->fresh(['user', 'handler'])),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PATCH /api/admin/vip-requests/{id}/complete
    //
    // Body (optional): { admin_note: string }
    // Sets status → 'completed'
    // ═══════════════════════════════════════════════════════════════════════
    public function complete(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'admin_note' => 'nullable|string|max:1000',
        ]);

        $vipRequest = VipRequest::findOrFail($id);

        if ($vipRequest->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Only in-progress requests can be marked as completed.',
            ], 422);
        }

        $vipRequest->update([
            'status'     => 'completed',
            'admin_note' => $request->input('admin_note') ?? $vipRequest->admin_note,
            'handled_by' => auth()->id(),
            'handled_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Request marked as completed.',
            'data'    => $this->formatRequest($vipRequest->fresh(['user', 'handler'])),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PATCH /api/admin/vip-requests/{id}/reject
    //
    // Body (required): { admin_note: string }
    // Sets status → 'rejected'
    // ═══════════════════════════════════════════════════════════════════════
    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'admin_note' => 'required|string|min:5|max:1000',
        ]);

        $vipRequest = VipRequest::findOrFail($id);

        if ($vipRequest->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Completed requests cannot be rejected.',
            ], 422);
        }

        $vipRequest->update([
            'status'     => 'rejected',
            'admin_note' => $request->input('admin_note'),
            'handled_by' => auth()->id(),
            'handled_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Request rejected.',
            'data'    => $this->formatRequest($vipRequest->fresh(['user', 'handler'])),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PATCH /api/admin/vip-requests/{id}/note
    //
    // Body (required): { admin_note: string }
    // Updates internal note without changing status
    // ═══════════════════════════════════════════════════════════════════════
    public function addNote(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'admin_note' => 'required|string|min:1|max:1000',
        ]);

        $vipRequest = VipRequest::findOrFail($id);
        $vipRequest->update(['admin_note' => $request->input('admin_note')]);

        return response()->json([
            'success' => true,
            'message' => 'Note saved.',
            'data'    => $this->formatRequest($vipRequest->fresh(['user', 'handler'])),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Private helper: consistent response shape
    // ═══════════════════════════════════════════════════════════════════════
    private function formatRequest(VipRequest $r): array
    {
        return [
            'id'           => $r->id,
            'type'         => $r->type,
            'type_label'   => $r->type_label,
            'status'       => $r->status,
            'status_label' => $r->status_label,
            'message'      => $r->message,
            'admin_note'   => $r->admin_note,
            'created_at'   => $r->created_at->format('Y-m-d\TH:i:s\Z'),
            'handled_at'   => $r->handled_at?->format('Y-m-d\TH:i:s\Z'),
            'seller' => $r->user ? [
                'id'     => $r->user->id,
                'name'   => $r->user->name,
                'email'  => $r->user->email,
                'avatar' => $r->user->avatar,
            ] : null,
            'handler' => $r->handler ? [
                'id'   => $r->handler->id,
                'name' => $r->handler->name,
            ] : null,
        ];
    }
}