<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Order;
use App\Models\User;
use App\Notifications\ComplaintCreatedNotification;
use App\Notifications\ComplaintStatusChangedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Client Complaint Controller
 *
 * Routes (all under auth:sanctum middleware):
 *   GET    /api/client/complaints           → index()
 *   POST   /api/client/complaints           → store()
 *   GET    /api/client/complaints/{id}      → show()
 *   GET    /api/client/complaints/eligible-orders → eligibleOrders()
 */
class ComplaintController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────
    // GET /api/client/complaints/eligible-orders
    // Returns orders that the user CAN file a complaint on.
    // Eligibility: status=delivered, within COMPLAINT_WINDOW_DAYS,
    //              no existing complaint already filed.
    // ─────────────────────────────────────────────────────────────────────

    public function eligibleOrders(Request $request)
    {
        $user      = $request->user();
        $windowDays = Complaint::COMPLAINT_WINDOW_DAYS;

        // IDs of orders already complained about (prevent duplicates)
        $alreadyComplained = Complaint::where('user_id', $user->id)
            ->pluck('order_id')
            ->toArray();

        // Fetch delivered orders within the complaint window
        $orders = Order::where('user_id', $user->id)
            ->where('status', 'delivered')
            ->where('updated_at', '>=', now()->subDays($windowDays))
            ->whereNotIn('id', $alreadyComplained)
            ->with([
                'items:id,order_id,product_name,quantity,unit_price',
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn($o) => [
                'id'           => $o->id,
                'order_number' => $o->order_number,
                'delivered_at' => $o->updated_at->format('d M Y'),
                'days_left'    => $windowDays - (int) $o->updated_at->diffInDays(now()),
                'total_amount' => (float) $o->total_amount,
                'items'        => $o->items->map(fn($i) => [
                    'product_name' => $i->product_name,
                    'quantity'     => $i->quantity,
                ]),
            ]);

        return response()->json([
            'success'      => true,
            'window_days'  => $windowDays,
            'data'         => $orders,
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
            ->with(['order:id,order_number,total_amount,status,created_at'])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $complaint]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/client/complaints
    // ─────────────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $user = $request->user();

        // ── 1. Validate input ────────────────────────────────────────────
        $validated = $request->validate([
            'order_id'       => 'required|integer|exists:orders,id',
            'complaint_type' => 'required|string|in:wrong_product,wrong_size,wrong_color,damaged_product,other',
            'other_reason'   => 'required_if:complaint_type,other|nullable|string|max:255',
            'description'    => 'required|string|min:20|max:2000',
            'image'          => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120', // 5 MB
        ]);

        // ── 2. Ownership check ───────────────────────────────────────────
        $order = Order::where('id', $validated['order_id'])
            ->where('user_id', $user->id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found or does not belong to you.',
            ], 404);
        }

        // ── 3. Eligibility: order must be delivered ──────────────────────
        if ($order->status !== 'delivered') {
            return response()->json([
                'success' => false,
                'message' => 'You can only file a complaint on a delivered order.',
            ], 422);
        }

        // ── 4. Time window check (14 days after delivery) ────────────────
        $deliveredAt  = $order->updated_at; // best proxy since no delivered_at column
        $daysElapsed  = (int) $deliveredAt->diffInDays(now());
        if ($daysElapsed > Complaint::COMPLAINT_WINDOW_DAYS) {
            return response()->json([
                'success' => false,
                'message' => 'The complaint window of ' . Complaint::COMPLAINT_WINDOW_DAYS . ' days has passed for this order.',
            ], 422);
        }

        // ── 5. Duplicate check ───────────────────────────────────────────
        $exists = Complaint::where('user_id', $user->id)
            ->where('order_id', $order->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'You have already filed a complaint for this order.',
            ], 422);
        }

        // ── 6. Resolve seller_id ─────────────────────────────────────────
        // Find the seller from the first order item's product
        $sellerCol = DB::select("SHOW COLUMNS FROM products LIKE 'seller_id'");
        $col       = count($sellerCol) ? 'seller_id' : 'user_id';

        $sellerId = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('order_items.order_id', $order->id)
            ->whereNull('products.deleted_at')
            ->value("products.{$col}");

        // ── 7. Store image ───────────────────────────────────────────────
        $imagePath = null;
        if ($request->hasFile('image') && $request->file('image')->isValid()) {
            $imagePath = $request->file('image')->store('complaints', 'public');
        }

        // ── 8. Create complaint ──────────────────────────────────────────
        $complaint = Complaint::create([
            'user_id'        => $user->id,
            'order_id'       => $order->id,
            'seller_id'      => $sellerId,
            'complaint_type' => $validated['complaint_type'],
            'other_reason'   => $validated['other_reason'] ?? null,
            'description'    => $validated['description'],
            'image_path'     => $imagePath,
            'status'         => Complaint::STATUS_PENDING,
        ]);

        // ── 9. Notifications ─────────────────────────────────────────────
        try {
            // Notify seller
            if ($sellerId) {
                $seller = \App\Models\User::find($sellerId);
                if ($seller) {
                    $seller->notify(new ComplaintCreatedNotification($complaint, $user));
                }
            }

            // Notify all admins
            $admins = \App\Models\User::where('role', 'admin')
                ->where('is_active', true)
                ->get();
            Notification::send($admins, new ComplaintCreatedNotification($complaint, $user));

        } catch (\Throwable $e) {
            Log::error('[Complaint] Notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Your complaint has been submitted. We will review it shortly.',
            'data'    => $complaint->load('order:id,order_number'),
        ], 201);
    }
}