<?php

namespace App\Http\Controllers\Api\Delivery;

use App\Events\RefundCompleted;
use App\Http\Controllers\Controller;
use App\Models\RefundDeliveryTask;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * FILE: app/Http/Controllers/Api/Delivery/RefundDeliveryController.php  ← REPLACE
 *
 * FIX applied to formatTask():
 *
 *   ROOT CAUSE: The items list was built from ALL order items:
 *     $order->items->map(...)
 *
 *   This showed every item in the order to the delivery agent, even those
 *   the customer did NOT complain about. If a complaint was filed for only
 *   the Basketball in a Basketball + JBL Headphone order, the agent would
 *   see (and collect) both items — which is wrong.
 *
 *   THE FIX: Filter order items to only those whose ID appears in
 *   $complaint->order_item_ids (the JSON array stored on the complaint).
 *
 *   Backward compatibility:
 *   - If order_item_ids is NULL (legacy complaint filed before this feature),
 *     fall back to showing ALL items (original behavior — no regression).
 *   - If order_item_ids is an empty array [], also fall back to all items.
 *
 *   No schema changes, no new relations, no migration needed.
 *   All other methods are 100% unchanged.
 */
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
     * FIX: Items are now filtered to only those the customer complained about.
     *
     * When complaint->order_item_ids is set (non-null, non-empty array),
     * only items whose ID is in that array are returned to the delivery agent.
     * This ensures the agent collects ONLY the specific complained items,
     * not everything in the order.
     *
     * When order_item_ids is NULL or [] (legacy complaints), all order items
     * are returned — backward-compatible with pre-fix complaints.
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

        // ── Items — FIXED: filter to complained items only ─────────────────
        //
        // $complaint->order_item_ids is cast to array by the Complaint model.
        // It contains the specific order_items.id values the customer selected
        // when filing the complaint (e.g. [12] for just the Basketball).
        //
        // NULL or empty → legacy complaint → show all items (backward compat)
        // [12, 15]      → show only those two items to the delivery agent
        //
        $complainedItemIds = $complaint?->order_item_ids; // array|null (cast on model)

        $allItems = $order?->relationLoaded('items') ? $order->items : collect();

        if (!empty($complainedItemIds)) {
            // Filter to only the items the customer complained about
            $filteredItems = $allItems->filter(
                fn($i) => in_array($i->id, $complainedItemIds)
            );
        } else {
            // Legacy complaint or no specific items selected — show everything
            $filteredItems = $allItems;
        }

        $items = $filteredItems->map(fn($i) => [
            'product_name' => $i->product_name,
            'quantity'     => (int) $i->quantity,
        ])->values();

        // ── Build response ─────────────────────────────────────────────────
        $data = [
            'id'           => $task->id,
            'complaint_id' => $task->complaint_id,
            'status'       => $task->status,
            'created_at'   => $task->created_at,

            'customer' => [
                'name'    => $user?->name    ?? 'Unknown',
                'phone'   => $order?->phone,
                'wilaya'  => $order?->wilaya,
                'address' => $order?->address,
            ],

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

        if ($detailed) {
            $data['complaint'] = [
                'type'        => $complaint?->complaint_type,
                'description' => $complaint?->description,
                'image_url'   => $complaint?->image_url,
            ];
        } else {
            $data['complaint_type'] = $complaint?->complaint_type;
        }

        return $data;
    }
}