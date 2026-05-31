<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Order;
use App\Models\OrderItem;
use App\Notifications\ComplaintCreatedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * FILE: app/Http/Controllers/Api/Client/ComplaintController.php  ← REPLACE
 *
 * Changes from previous version:
 *
 *   eligibleOrders():
 *     - Window changed from 14 days to 48 HOURS (Complaint::COMPLAINT_WINDOW_HOURS)
 *     - Accepts orders where orders.status = 'delivered' OR at least one
 *       seller_order.status = 'delivered' (multi-seller fix, preserved)
 *     - Items scoped to delivered seller_orders only (preserved)
 *
 *   store():
 *     - Validates and stores resolution_type ('exchange' or 'return_refund')
 *     - All other validation unchanged
 */
class ComplaintController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────
    // GET /api/client/complaints/eligible-orders
    // ─────────────────────────────────────────────────────────────────────

    public function eligibleOrders(Request $request)
    {
        $user        = $request->user();
        $windowHours = Complaint::COMPLAINT_WINDOW_HOURS; // 48h

        $alreadyComplained = Complaint::where('user_id', $user->id)
            ->pluck('order_id')
            ->toArray();

        $orders = Order::where('user_id', $user->id)
            ->whereNotIn('id', $alreadyComplained)
            ->where(function ($q) use ($windowHours) {
                // Branch A: parent order fully delivered, within 48h
                $q->where(function ($a) use ($windowHours) {
                    $a->where('status', 'delivered')
                      ->where('updated_at', '>=', now()->subHours($windowHours));
                })
                // Branch B: multi-seller — at least one sub-order delivered, within 48h
                ->orWhere(function ($b) use ($windowHours) {
                    $b->whereHas('sellerOrders', fn($so) => $so->where('status', 'delivered'))
                      ->where('created_at', '>=', now()->subHours($windowHours));
                });
            })
            ->with([
                'sellerOrders',
                'items:id,order_id,seller_order_id,product_id,product_name,quantity,unit_price',
                'items.product:id',
                'items.product.primaryImage:id,product_id,image_path',
            ])
            ->orderByDesc('updated_at')
            ->get();

        $result = $orders->map(function ($order) use ($windowHours) {
            $deliveredSellerOrderIds = $order->sellerOrders
                ->where('status', 'delivered')
                ->pluck('id')
                ->toArray();

            // Only show items from delivered sub-orders
            $eligibleItems = $order->items->filter(function ($item) use ($deliveredSellerOrderIds) {
                if (is_null($item->seller_order_id)) return true; // legacy
                return in_array($item->seller_order_id, $deliveredSellerOrderIds);
            });

            // Hours remaining in the 48h window (use updated_at as delivery timestamp proxy)
            $hoursElapsed = (int) $order->updated_at->diffInHours(now());
            $hoursLeft    = max(0, $windowHours - $hoursElapsed);

            return [
                'id'           => $order->id,
                'order_number' => $order->order_number,
                'delivered_at' => $order->updated_at->format('d M Y · H:i'),
                'hours_left'   => $hoursLeft,
                // Keep days_left for backward compat with frontend display
                'days_left'    => max(0, (int) ceil($hoursLeft / 24)),
                'total_amount' => (float) $order->total_amount,
                'items'        => $eligibleItems->map(fn($i) => [
                    'id'           => $i->id,
                    'product_name' => $i->product_name,
                    'quantity'     => $i->quantity,
                    'unit_price'   => (float) $i->unit_price,
                    'image_url'    => $i->product?->primaryImage?->image_path
                        ? Storage::url($i->product->primaryImage->image_path)
                        : null,
                ])->values(),
            ];
        });

        return response()->json([
            'success'       => true,
            'window_hours'  => $windowHours,
            'window_days'   => (int) ceil($windowHours / 24), // backward compat
            'data'          => $result->values(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/client/complaints
    // ─────────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $complaints = Complaint::where('user_id', $request->user()->id)
            ->with(['order:id,order_number,total_amount,status'])
            ->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 10));

        return response()->json(['success' => true, 'data' => $complaints]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/client/complaints/{id}
    // ─────────────────────────────────────────────────────────────────────

    public function show(Request $request, $id)
    {
        $complaint = Complaint::where('user_id', $request->user()->id)
            ->with([
                'order:id,order_number,total_amount,status,created_at',
                'complainedItems',
            ])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $complaint]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/client/complaints
    // ─────────────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $user = $request->user();

        // ── 1. Validate ──────────────────────────────────────────────────
        $validated = $request->validate([
            'order_id'        => 'required|integer|exists:orders,id',
            'complaint_type'  => 'required|string|in:wrong_product,wrong_size,wrong_color,damaged_product,other',
            'resolution_type' => 'required|string|in:exchange,return_refund',  // ← NEW
            'other_reason'    => 'required_if:complaint_type,other|nullable|string|max:255',
            'description'     => 'required|string|min:20|max:2000',
            'image'           => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'item_ids'        => 'nullable|array|min:1',
            'item_ids.*'      => 'integer|exists:order_items,id',
        ]);

        // ── 2. Ownership ─────────────────────────────────────────────────
        $order = Order::where('id', $validated['order_id'])
            ->where('user_id', $user->id)
            ->with('sellerOrders')
            ->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }

        // ── 3. Eligibility — at least one seller_order delivered ──────────
        $hasDeliveredSellerOrder = $order->sellerOrders
            ->where('status', 'delivered')
            ->isNotEmpty();

        if ($order->status !== 'delivered' && !$hasDeliveredSellerOrder) {
            return response()->json([
                'success' => false,
                'message' => 'You can only file a complaint on a delivered order.',
            ], 422);
        }

        // ── 4. 48h window check ──────────────────────────────────────────
        $hoursElapsed = (int) $order->updated_at->diffInHours(now());
        if ($hoursElapsed > Complaint::COMPLAINT_WINDOW_HOURS) {
            return response()->json([
                'success' => false,
                'message' => 'The 48-hour complaint window has passed for this order.',
            ], 422);
        }

        // ── 5. Duplicate check ───────────────────────────────────────────
        if (Complaint::where('user_id', $user->id)->where('order_id', $order->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'You have already filed a complaint for this order.',
            ], 422);
        }

        // ── 6. Validate item_ids belong to this order ────────────────────
        $validatedItemIds = null;
        if (!empty($validated['item_ids'])) {
            $orderItemIds = OrderItem::where('order_id', $order->id)->pluck('id')->toArray();
            $invalid = array_diff($validated['item_ids'], $orderItemIds);
            if (!empty($invalid)) {
                return response()->json([
                    'success' => false,
                    'message' => 'One or more selected items do not belong to this order.',
                    'errors'  => ['item_ids' => ['Invalid item selection.']],
                ], 422);
            }
            $validatedItemIds = array_values($validated['item_ids']);
        }

        // ── 7. Resolve seller_id ─────────────────────────────────────────
        $sellerCol = DB::select("SHOW COLUMNS FROM products LIKE 'seller_id'");
        $col       = count($sellerCol) ? 'seller_id' : 'user_id';

        $sellerQuery = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('order_items.order_id', $order->id)
            ->whereNull('products.deleted_at');

        if (!empty($validatedItemIds)) {
            $sellerQuery->whereIn('order_items.id', $validatedItemIds);
        }

        $sellerId = $sellerQuery->value("products.{$col}");

        // ── 8. Store image ───────────────────────────────────────────────
        $imagePath = null;
        if ($request->hasFile('image') && $request->file('image')->isValid()) {
            $imagePath = $request->file('image')->store('complaints', 'public');
        }

        // ── 9. Create complaint ──────────────────────────────────────────
        $complaint = Complaint::create([
            'user_id'         => $user->id,
            'order_id'        => $order->id,
            'order_item_ids'  => $validatedItemIds,
            'seller_id'       => $sellerId,
            'complaint_type'  => $validated['complaint_type'],
            'resolution_type' => $validated['resolution_type'],   // ← NEW
            'other_reason'    => $validated['other_reason'] ?? null,
            'description'     => $validated['description'],
            'image_path'      => $imagePath,
            'status'          => Complaint::STATUS_PENDING,
        ]);

        // ── 10. Notifications ────────────────────────────────────────────
        try {
            if ($sellerId) {
                $seller = \App\Models\User::find($sellerId);
                if ($seller) {
                    $seller->notify(new ComplaintCreatedNotification($complaint, $user));
                }
            }
            $admins = \App\Models\User::where('role', 'admin')->where('is_active', true)->get();
            Notification::send($admins, new ComplaintCreatedNotification($complaint, $user));
        } catch (\Throwable $e) {
            Log::error('[Complaint] Notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Your complaint has been submitted.',
            'data'    => $complaint->load('order:id,order_number', 'complainedItems'),
        ], 201);
    }
}