<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Returns\ReturnPresenter;
use App\Services\Returns\ReturnService;
use Illuminate\Http\Request;

/**
 * Client returns ("réclamations"): the only resolution is return + refund.
 *
 *   GET   /api/client/complaints/eligible-orders   delivered orders still returnable
 *   GET   /api/client/complaints[?order_id=]       my returns
 *   GET   /api/client/complaints/{id}              one return + its timeline
 *   POST  /api/client/complaints                   request a return (whole order or items × qty)
 *   PATCH /api/client/complaints/{id}/escalate     contest the shop's refusal
 *   PATCH /api/client/complaints/{id}/cancel       withdraw a request the shop hasn't answered
 */
class ComplaintController extends Controller
{
    public function __construct(private ReturnService $returns) {}

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/client/complaints/eligible-orders
    // ─────────────────────────────────────────────────────────────────────

    public function eligibleOrders(Request $request)
    {
        $user        = $request->user();
        $windowHours = Complaint::COMPLAINT_WINDOW_HOURS;

        $orders = $this->eligibleQuery($user->id)
            ->with([
                'sellerOrders',
                // Each line as bought: its snapshot, else its own variant's image
                'items.product' => fn($q) => $q->withTrashed()->with(['images', 'variants.attributeOptions.attribute']),
                'items.variant.attributeOptions.attribute',
            ])
            ->orderByDesc('updated_at')
            ->get();

        $result = $orders->map(function (Order $order) use ($windowHours) {
            $items = $this->returnableLines($order)->map(fn(array $l) => $l['line']->purchaseSnapshot() + [
                'returnable_quantity' => $l['returnable'],
                'paid_unit_price'     => $l['paid_unit_price'],
            ])->values();

            $hoursLeft = max(0, $windowHours - (int) $order->updated_at->diffInHours(now()));

            return [
                'id'             => $order->id,
                'order_number'   => $order->order_number,
                'payment_method' => $order->payment_method ?? 'cod',
                'delivered_at'   => $order->updated_at->format('d M Y · H:i'),
                'hours_left'     => $hoursLeft,
                'days_left'      => max(0, (int) ceil($hoursLeft / 24)),
                'total_amount'   => (float) $order->total_amount,
                // One choice per order line, picked by order_item_id with a quantity
                'items'          => $items,
            ];
        })->filter(fn($o) => count($o['items']) > 0)->values();

        return response()->json([
            'success'             => true,
            'window_hours'        => $windowHours,
            'window_days'         => (int) ceil($windowHours / 24),
            'return_shipping_fee' => $this->returns->returnShippingFee(),
            'data'                => $result,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/client/complaints
    // ─────────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $complaints = Complaint::where('user_id', $request->user()->id)
            ->when($request->filled('order_id'), fn($q) => $q->where('order_id', (int) $request->order_id))
            ->with(['order:id,order_number,total_amount,status,return_status,payment_method', 'events'])
            ->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 10));

        Complaint::withItemSnapshots($complaints);
        $complaints->getCollection()->each(fn($c) => ReturnPresenter::forClient($c));

        return response()->json(['success' => true, 'data' => $complaints]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/client/complaints/{id}
    // ─────────────────────────────────────────────────────────────────────

    public function show(Request $request, $id)
    {
        $complaint = Complaint::where('user_id', $request->user()->id)
            ->with(['order:id,order_number,total_amount,status,return_status,payment_method,created_at', 'events'])
            ->findOrFail($id);

        Complaint::withItemSnapshots($complaint);

        return response()->json(['success' => true, 'data' => ReturnPresenter::forClient($complaint)]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/client/complaints   (multipart)
    // ─────────────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'order_id'           => 'required|integer|exists:orders,id',
            'complaint_type'     => 'required|string|in:' . implode(',', array_keys(Complaint::COMPLAINT_TYPES)),
            // Exchange is gone: a client who wants another item reorders.
            'resolution_type'    => 'nullable|string|in:return_refund',
            'other_reason'       => 'required_if:complaint_type,other|nullable|string|max:255',
            'description'        => 'required|string|min:20|max:2000',
            'return_all'         => 'nullable|boolean',
            'items'              => 'required_without_all:return_all,item_ids|array|min:1',
            'items.*.order_item_id' => 'required_with:items|integer',
            'items.*.quantity'   => 'required_with:items|integer|min:1',
            'item_ids'           => 'nullable|array|min:1',   // legacy form: whole lines
            'item_ids.*'         => 'integer',
            'images'             => 'required_without:image|array|min:1|max:5',
            'images.*'           => 'image|mimes:jpeg,png,jpg,webp|max:5120',
            'image'              => 'required_without:images|nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ], [
            'images.required_without' => __('messages.complaint.photo_required'),
            'image.required_without'  => __('messages.complaint.photo_required'),
            'items.required_without_all' => __('messages.complaint.nothing_selected'),
        ]);

        $order = Order::where('id', $validated['order_id'])->where('user_id', $user->id)->with('sellerOrders')->first();
        if (!$order) {
            return response()->json(['success' => false, 'message' => __('messages.complaint.order_not_found')], 404);
        }
        if (!$this->eligibleQuery($user->id)->whereKey($order->id)->exists()) {
            $delivered = $order->status === 'delivered' || $order->sellerOrders->contains('status', 'delivered');
            return response()->json([
                'success' => false,
                'message' => $delivered ? __('messages.complaint.window_passed') : __('messages.complaint.only_delivered'),
            ], 422);
        }

        // What the client may return: lines of delivered sub-orders, minus live returns
        $returnable = $this->returnableLines($order->load('items'))->mapWithKeys(fn($l) => [$l['line']->id => $l['returnable']]);

        if (!empty($validated['return_all'])) {
            $quantities = $returnable->filter()->all();
        } elseif (!empty($validated['items'])) {
            $quantities = collect($validated['items'])->mapWithKeys(fn($i) => [(int) $i['order_item_id'] => (int) $i['quantity']])->all();
        } else {
            $quantities = collect($validated['item_ids'])->mapWithKeys(fn($id) => [(int) $id => (int) ($returnable[(int) $id] ?? 0)])->all();
        }

        if (!$quantities) {
            return response()->json(['success' => false, 'message' => __('messages.complaint.nothing_selected')], 422);
        }
        foreach ($quantities as $lineId => $qty) {
            if (!$returnable->has($lineId)) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.complaint.items_not_in_order'),
                    'errors'  => ['items' => [__('messages.complaint.items_not_in_order')]],
                ], 422);
            }
            if ($qty < 1 || $qty > $returnable[$lineId]) {
                $name = OrderItem::find($lineId)?->product_name;
                return response()->json([
                    'success' => false,
                    'message' => __('messages.complaint.quantity_too_high', ['item' => $name]),
                    'errors'  => ['items' => [__('messages.complaint.quantity_too_high', ['item' => $name])]],
                ], 422);
            }
        }

        $images = $request->file('images') ?: array_filter([$request->file('image')]);
        $complaints = $this->returns->create($user, $order, $quantities, $validated, array_values($images));

        $first = $complaints->first()->load('order:id,order_number');
        Complaint::withItemSnapshots($complaints);

        return response()->json([
            'success' => true,
            'message' => __('messages.complaint.submitted'),
            'data'    => $first,
            'returns' => $complaints->values(),   // one per shop
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PATCH /api/client/complaints/{id}/escalate
    // ─────────────────────────────────────────────────────────────────────

    public function escalate(Request $request, $id)
    {
        $request->validate(['note' => 'nullable|string|max:1000']);
        $complaint = Complaint::where('user_id', $request->user()->id)->findOrFail($id);

        $c = $this->returns->escalate($complaint, $request->user(), $request->note);

        return response()->json(['success' => true, 'message' => __('messages.complaint.escalated'), 'data' => $c]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PATCH /api/client/complaints/{id}/cancel
    // ─────────────────────────────────────────────────────────────────────

    public function cancel(Request $request, $id)
    {
        $complaint = Complaint::where('user_id', $request->user()->id)->findOrFail($id);

        $c = $this->returns->clientCancel($complaint, $request->user());

        return response()->json(['success' => true, 'message' => __('messages.complaint.cancelled'), 'data' => $c]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Delivered (whole order, or at least one sub-order) within the return window. */
    private function eligibleQuery(int $userId)
    {
        $hours = Complaint::COMPLAINT_WINDOW_HOURS;

        return Order::where('user_id', $userId)
            ->where(function ($q) use ($hours) {
                $q->where(fn($a) => $a->where('status', 'delivered')->where('updated_at', '>=', now()->subHours($hours)))
                  ->orWhere(fn($b) => $b->whereHas('sellerOrders', fn($so) => $so->where('status', 'delivered'))
                                        ->where('updated_at', '>=', now()->subHours($hours)));
            });
    }

    /**
     * Lines of delivered sub-orders with units left to return
     * (quantity kept − units already in a live return).
     *
     * @return \Illuminate\Support\Collection<int, array{line: OrderItem, returnable: int, paid_unit_price: float}>
     */
    private function returnableLines(Order $order)
    {
        $delivered = $order->sellerOrders->where('status', 'delivered')->pluck('id')->all();
        $reserved  = $this->returns->reservedQuantities($order->id);

        return $order->items
            ->filter(fn($i) => is_null($i->seller_order_id) || in_array($i->seller_order_id, $delivered))
            ->map(function (OrderItem $line) use ($reserved) {
                $qty = (int) $line->quantity;
                $net = $line->net_total !== null ? (float) $line->net_total : (float) $line->total - (float) $line->discount_amount;
                return [
                    'line'            => $line,
                    'returnable'      => max(0, $qty - (int) ($reserved[$line->id] ?? 0)),
                    'paid_unit_price' => $qty > 0 ? round($net / $qty, 3) : 0.0,
                ];
            })
            ->filter(fn($l) => $l['returnable'] > 0)
            ->values();
    }
}
