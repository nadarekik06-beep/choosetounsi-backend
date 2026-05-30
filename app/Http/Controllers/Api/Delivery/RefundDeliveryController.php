<?php

namespace App\Http\Controllers\Api\Delivery;

use App\Events\RefundCompleted;
use App\Http\Controllers\Controller;
use App\Models\RefundDeliveryTask;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RefundDeliveryController extends Controller
{
    // ── Delivery Admin Endpoints ───────────────────────────────────────────

    public function stats(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'total'           => RefundDeliveryTask::count(),
                'pending'         => RefundDeliveryTask::pending()->count(),
                'assigned'        => RefundDeliveryTask::assigned()->count(),
                'picked_up'       => RefundDeliveryTask::pickedUp()->count(),
                'completed_today' => RefundDeliveryTask::completed()
                    ->whereDate('completed_at', today())
                    ->count(),
            ],
        ]);
    }

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = RefundDeliveryTask::with([
            'deliveryGuy:id,name,email',
            'complaint.order.user:id,name,email',
            'complaint.order.items:id,order_id,product_name,quantity',
            'complaint',
            'seller.sellerApplication',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $tasks = $query->latest()
            ->paginate((int) $request->query('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $tasks->through(fn($t) => $this->formatTask($t)),
        ]);
    }

    public function show(int $id): \Illuminate\Http\JsonResponse
    {
        $task = RefundDeliveryTask::with([
            'deliveryGuy:id,name,email',
            'complaint.order.user:id,name,email',
            'complaint.order.items:id,order_id,product_name,quantity,unit_price',
            'complaint',
            'seller.sellerApplication',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $this->formatTask($task, detailed: true),
        ]);
    }

    public function assign(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'delivery_guy_id' => 'required|integer|exists:users,id',
            'notes'           => 'nullable|string|max:500',
        ]);

        $task = RefundDeliveryTask::findOrFail($id);

        if (!$task->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending refund tasks can be assigned.',
            ], 422);
        }

        $deliveryGuy = User::where('id', $request->delivery_guy_id)
            ->where('role', 'delivery_guy')
            ->where('is_active', true)
            ->first();

        if (!$deliveryGuy) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid delivery guy or account is inactive.',
            ], 422);
        }

        try {
            $task->assignTo(
                deliveryGuyId: $deliveryGuy->id,
                assignedById:  auth()->id(),
                notes:         $request->notes,
            );

            return response()->json([
                'success' => true,
                'message' => "Refund task assigned to {$deliveryGuy->name}.",
                'data'    => $this->formatTask(
                    $task->fresh([
                        'deliveryGuy',
                        'complaint.order.user',
                        'complaint.order.items',
                        'complaint',
                        'seller.sellerApplication',
                    ])
                ),
            ]);

        } catch (\Throwable $e) {
            Log::error('[RefundDelivery::assign] ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to assign task.'], 500);
        }
    }

    // ── Delivery Guy Endpoints ─────────────────────────────────────────────

    public function myRefunds(Request $request): \Illuminate\Http\JsonResponse
    {
        $tasks = RefundDeliveryTask::forDeliveryGuy(auth()->id())
            ->with([
                'complaint.order.user:id,name,email',
                'complaint.order.items:id,order_id,product_name,quantity',
                'complaint',
                'seller.sellerApplication',
            ])
            ->whereIn('status', [
                RefundDeliveryTask::STATUS_ASSIGNED,
                RefundDeliveryTask::STATUS_PICKED_UP,
            ])
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => $tasks->through(fn($t) => $this->formatTask($t)),
        ]);
    }

    public function updateGuyStatus(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'status' => 'required|in:picked_up,completed',
        ]);

        $task = RefundDeliveryTask::where('id', $id)
            ->where('delivery_guy_id', auth()->id())
            ->firstOrFail();

        $newStatus = $request->status;

        if (!$task->canTransitionTo($newStatus)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot transition from \"{$task->status}\" to \"{$newStatus}\".",
            ], 422);
        }

        try {
            if ($newStatus === RefundDeliveryTask::STATUS_PICKED_UP) {
                $task->markPickedUp();
            } elseif ($newStatus === RefundDeliveryTask::STATUS_COMPLETED) {
                $task->markCompleted();
                RefundCompleted::dispatch($task->fresh());
            }

            return response()->json([
                'success' => true,
                'message' => "Refund task status updated to \"{$newStatus}\".",
                'data'    => $this->formatTask(
                    $task->fresh([
                        'complaint.order.user',
                        'complaint.order.items',
                        'complaint',
                        'seller.sellerApplication',
                    ])
                ),
            ]);

        } catch (\Throwable $e) {
            Log::error('[RefundDelivery::updateGuyStatus] ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to update status.'], 500);
        }
    }

    // ── Private Helpers ────────────────────────────────────────────────────

    /**
     * Format a RefundDeliveryTask for API response.
     *
     * All data is resolved via relations — no snapshot columns exist anymore.
     * Required eager loads:
     *   complaint.order.user
     *   complaint.order.items
     *   complaint
     *   seller.sellerApplication
     *   deliveryGuy (optional, for admin views)
     */
    private function formatTask(RefundDeliveryTask $task, bool $detailed = false): array
    {
        // ── Customer info ──────────────────────────────────────────────────
        $complaint = $task->complaint;
        $order     = $complaint?->order;
        $user      = $order?->user;

        // ── Seller info ────────────────────────────────────────────────────
        $sellerUser = $task->seller;
        $sellerApp  = $sellerUser?->sellerApplication;

        // ── Items ──────────────────────────────────────────────────────────
        $items = $order?->relationLoaded('items')
            ? $order->items->map(fn($i) => [
                'product_name' => $i->product_name,
                'quantity'     => (int) $i->quantity,
              ])->values()
            : [];

        $data = [
            'id'           => $task->id,
            'complaint_id' => $task->complaint_id,
            'status'       => $task->status,
            'created_at'   => $task->created_at,

            // ── Customer (pickup location — agent goes TO collect item) ────
            'customer' => [
                'name'    => $user?->name    ?? 'Unknown',
                'phone'   => $order?->phone,
                'wilaya'  => $order?->wilaya,
                'address' => $order?->address,
            ],

            // ── Seller (return location — agent brings item BACK here) ─────
            'seller' => [
                'name'          => $sellerUser?->name          ?? 'Unknown',
                'business_name' => $sellerApp?->business_name,
                'phone'         => $sellerApp?->phone_number,
                'wilaya'        => $sellerApp?->wilaya,
                'city'          => $sellerApp?->city,
            ],

            'items' => $items,

            'delivery_guy' => $task->relationLoaded('deliveryGuy') && $task->deliveryGuy
                ? [
                    'id'    => $task->deliveryGuy->id,
                    'name'  => $task->deliveryGuy->name,
                    'email' => $task->deliveryGuy->email,
                ]
                : null,

            'assigned_at'  => $task->assigned_at,
            'picked_up_at' => $task->picked_up_at,
            'completed_at' => $task->completed_at,
            'notes'        => $task->notes,
        ];

        // ── Complaint context ──────────────────────────────────────────────
        if ($detailed) {
            $data['complaint'] = [
                'type'        => $complaint?->complaint_type,
                'description' => $complaint?->description,
                'image_url'   => $complaint?->image_url, // uses getImageUrlAttribute()
            ];
        } else {
            $data['complaint_type'] = $complaint?->complaint_type;
        }

        return $data;
    }
}