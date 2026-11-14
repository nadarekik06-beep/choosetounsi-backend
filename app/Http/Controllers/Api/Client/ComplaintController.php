<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\Order;
use App\Models\OrderItem;
use App\Http\Resources\Buyer\BuyerComplaintResource;
use App\Services\Returns\ReturnPolicy;
use App\Services\Returns\ReturnPresenter;
use App\Services\Returns\ReturnService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Client after-sale requests ("réclamations"): RETURN (refund) or EXCHANGE (another
 * variant of the same product, delivered by the courier who takes the old item back).
 *
 *   GET   /api/client/complaints/eligible-orders   delivered orders still returnable
 *   GET   /api/client/complaints[?order_id=]       my returns
 *   GET   /api/client/complaints/{id}              one return + its timeline
 *   POST  /api/client/complaints                   request a return (whole order or items × qty)
 *   PATCH /api/client/complaints/{id}/escalate     contest the shop's refusal
 *   PATCH /api/client/complaints/{id}/cancel       withdraw a request the shop hasn't answered
 *
 * Who "my" means is in ownedOrders() / ownedComplaints() / client(): the signed-in
 * account here; GuestComplaintController overrides them for a guest's one order,
 * so both paths share every rule below (window, quantities, exchange, photos, fees).
 */
class ComplaintController extends Controller
{
    public function __construct(private ReturnService $returns) {}

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/client/complaints/eligible-orders
    // ─────────────────────────────────────────────────────────────────────

    public function eligibleOrders(Request $request)
    {
        $windowHours = Complaint::COMPLAINT_WINDOW_HOURS;
        $policy = app(ReturnPolicy::class);

        $orders = $this->eligibleQuery($this->ownedOrders($request))
            ->with([
                'sellerOrders',
                // Each line as bought: its snapshot, else its own variant's image
                'items.product' => fn($q) => $q->withTrashed()->with(['images', 'variants.attributeOptions.attribute']),
                'items.variant.attributeOptions.attribute',
            ])
            ->orderByDesc('updated_at')
            ->get();

        $result = $orders->map(function (Order $order) use ($windowHours, $policy) {
            $items = $this->returnableLines($order)->map(fn(array $l) => [
                // What the buyer saw (delivery included on current orders), never the seller's base price
                'unit_price' => $l['line']->clientUnitPrice(),
                'total'      => round($l['line']->clientUnitPrice() * $l['line']->ordered_quantity, 3),
            ] + $l['line']->purchaseSnapshot() + [
                'returnable_quantity' => $l['returnable'],
                'paid_unit_price'     => $l['paid_unit_price'],
                'parcel_size'         => \App\Support\ParcelSize::normalize($l['line']->product?->parcel_size),
            ] + $this->exchangeOptions($l['line']) + [
                // Policy v2 (additive): the form renders these, it decides nothing
                'exchange_impossible' => $policy->exchangeImpossible($l['line']),
                'returns_mode'        => $policy->returnsMode($l['line']->product),
            ])->values();

            $hoursLeft = max(0, $windowHours - (int) $order->updated_at->diffInHours(now()));
            $deliveredAt = $order->updated_at;

            // Policy v2: each parcel has its own window, from its real delivered date
            if ($policy->enabled()) {
                $open = $order->sellerOrders->where('status', 'delivered')->filter(fn($so) => $policy->windowOpen($so));
                $end  = $open->map(fn($so) => $policy->windowEndsAt($so))->max();
                $hoursLeft   = $end ? max(0, (int) ceil(now()->diffInMinutes($end, false) / 60)) : 0;
                $deliveredAt = $open->map(fn($so) => $policy->deliveredAt($so))->max() ?? $deliveredAt;
            }

            return [
                'id'             => $order->id,
                'order_number'   => $order->order_number,
                'payment_method' => $order->payment_method ?? 'cod',
                'delivered_at'   => $deliveredAt->format('d M Y · H:i'),
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
            // One fee per shop parcel, at the largest size of the chosen lines
            'after_sale_fees'     => $this->returns->afterSaleFees(),
            'seller_fault_types'  => Complaint::SELLER_FAULT_TYPES,
            'complaint_types'     => array_keys(Complaint::COMPLAINT_TYPES),
            // Older storefront builds: the size S return fee
            'return_shipping_fee' => $this->returns->afterSaleFees()['S']['return'],
            'data'                => $result,
            // Exchange-first policy (v2): off until the admin switches it on
            'policy'              => [
                'v2'         => $policy->enabled(),
                'promise'    => __('returns_policy.promise'),
                'reasons'    => ReturnPolicy::REASONS,
                'min_photos' => $policy->enabled() ? ReturnPolicy::MIN_PHOTOS : 1,
                'max_photos' => $policy->enabled() ? ReturnPolicy::MAX_PHOTOS : 5,
            ],
            'closed_orders'       => $policy->enabled() ? $this->closedOrders($request, $policy, $result->pluck('id')->all()) : [],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/client/complaints
    // ─────────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $complaints = $this->ownedComplaints($request)
            ->when($request->filled('order_id'), fn($q) => $q->where('order_id', (int) $request->order_id))
            ->with(['order:id,order_number,total_amount,status,return_status,payment_method', 'events'])
            ->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 10));

        Complaint::withItemSnapshots($complaints);
        $complaints->getCollection()->transform(fn($c) => (new BuyerComplaintResource(ReturnPresenter::forClient($c)))->resolve($request));

        return response()->json(['success' => true, 'data' => $complaints]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/client/complaints/{id}
    // ─────────────────────────────────────────────────────────────────────

    public function show(Request $request, $id)
    {
        $complaint = $this->ownedComplaints($request)
            ->with(['order:id,order_number,total_amount,status,return_status,payment_method,created_at', 'events'])
            ->findOrFail($id);

        Complaint::withItemSnapshots($complaint);

        return response()->json(['success' => true, 'data' => (new BuyerComplaintResource(ReturnPresenter::forClient($complaint)))->resolve($request)]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/client/complaints/preview
    // What the chosen units would refund (the form shows it before filing).
    // ─────────────────────────────────────────────────────────────────────

    public function preview(Request $request)
    {
        $validated = $request->validate([
            'order_id'              => 'required|integer|exists:orders,id',
            'complaint_type'        => 'required|string|in:' . implode(',', array_keys(Complaint::COMPLAINT_TYPES)),
            'resolution_type'       => 'nullable|string|in:' . implode(',', Complaint::RESOLUTIONS),
            'return_all'            => 'nullable|boolean',
            'items'                 => 'required_without:return_all|array|min:1',
            'items.*.order_item_id' => 'required_with:items|integer',
            'items.*.quantity'      => 'required_with:items|integer|min:1',
        ]);

        $order = $this->ownedOrders($request)->where('id', $validated['order_id'])->with(['sellerOrders', 'items'])->first();
        if (!$order) {
            return response()->json(['success' => false, 'message' => __('messages.complaint.order_not_found')], 404);
        }
        $returnable = $this->returnableLines($order)->mapWithKeys(fn($l) => [$l['line']->id => $l['returnable']]);
        $quantities = !empty($validated['return_all'])
            ? $returnable->filter()->all()
            : collect($validated['items'] ?? [])
                ->mapWithKeys(fn($i) => [(int) $i['order_item_id'] => min((int) $i['quantity'], (int) ($returnable[(int) $i['order_item_id']] ?? 0))])
                ->filter()->all();

        $data = $this->returns->previewRefund($order, $quantities, $validated['complaint_type'], $validated['resolution_type'] ?? null);

        // Policy v2: what is allowed for this selection and reason, and why (the form only renders it)
        $policy = app(ReturnPolicy::class);
        if ($policy->enabled() && $quantities) {
            $lines = $order->items->whereIn('id', array_keys($quantities))->values();
            $lines->load(['product' => fn($q) => $q->withTrashed()->with(['variants.attributeOptions.attribute']), 'variant']);
            $data['policy'] = $policy->optionsForLines($lines);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/client/complaints   (multipart)
    // ─────────────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $policyOn = app(ReturnPolicy::class)->enabled();
        $imageRules = $policyOn
            ? ['images' => 'required|array|min:' . ReturnPolicy::MIN_PHOTOS . '|max:' . ReturnPolicy::MAX_PHOTOS, 'image' => 'prohibited']
            : ['images' => 'required_without:image|array|min:1|max:5', 'image' => 'required_without:images|nullable|image|mimes:jpeg,png,jpg,webp|max:5120'];
        $validated = $request->validate([
            'order_id'           => 'required|integer|exists:orders,id',
            'complaint_type'     => 'required|string|in:' . implode(',', array_keys(Complaint::COMPLAINT_TYPES)),
            'resolution_type'    => 'nullable|string|in:' . implode(',', Complaint::RESOLUTIONS),
            'other_reason'       => 'required_if:complaint_type,other|nullable|string|max:255',
            'description'        => 'required|string|min:20|max:2000',
            'return_all'         => 'nullable|boolean',
            'items'              => 'required_without_all:return_all,item_ids|array|min:1',
            'items.*.order_item_id' => 'required_with:items|integer',
            'items.*.quantity'   => 'required_with:items|integer|min:1',
            // Exchange: the variant wanted instead (same product; none for products without variants)
            'items.*.replacement_variant_id' => 'nullable|integer',
            'item_ids'           => 'nullable|array|min:1',   // legacy form: whole lines
            'item_ids.*'         => 'integer',
            'images.*'           => 'image|mimes:jpeg,png,jpg,webp|max:5120',
            'item_unused_confirmed' => 'nullable|boolean',
        ] + $imageRules, [
            'images.required_without' => __('messages.complaint.photo_required'),
            'image.required_without'  => __('messages.complaint.photo_required'),
            'images.required'         => __('returns_policy.photos_range'),
            'images.min'              => __('returns_policy.photos_range'),
            'images.max'              => __('returns_policy.photos_range'),
            'items.required_without_all' => __('messages.complaint.nothing_selected'),
        ]);

        $order = $this->ownedOrders($request)->where('id', $validated['order_id'])->with('sellerOrders')->first();
        if (!$order) {
            return response()->json(['success' => false, 'message' => __('messages.complaint.order_not_found')], 404);
        }
        if (!$this->eligibleQuery($this->ownedOrders($request))->whereKey($order->id)->exists()) {
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

        if (($validated['resolution_type'] ?? null) === Complaint::RESOLUTION_EXCHANGE) {
            if (empty($validated['items'])) {
                return response()->json(['success' => false, 'message' => __('messages.complaint.exchange_choose_items')], 422);
            }
            $validated['replacements'] = collect($validated['items'])
                ->mapWithKeys(fn($i) => [(int) $i['order_item_id'] => isset($i['replacement_variant_id']) ? (int) $i['replacement_variant_id'] : null])
                ->all();
        }

        $images = $request->file('images') ?: array_filter([$request->file('image')]);
        $complaints = $this->returns->create($this->client($request), $order, $quantities, $validated, array_values($images));

        $first = $complaints->first()->load('order:id,order_number');
        Complaint::withItemSnapshots($complaints);

        return response()->json([
            'success' => true,
            'message' => __('messages.complaint.submitted'),
            'data'    => (new BuyerComplaintResource($first))->resolve($request),
            'returns' => $complaints->values()->map(fn($c) => (new BuyerComplaintResource($c))->resolve($request))->all(),   // one per shop
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PATCH /api/client/complaints/{id}/escalate
    // ─────────────────────────────────────────────────────────────────────

    public function escalate(Request $request, $id)
    {
        $request->validate(['note' => 'nullable|string|max:1000']);
        $complaint = $this->ownedComplaints($request)->findOrFail($id);

        $c = $this->returns->escalate($complaint, $this->client($request), $request->note);

        return response()->json(['success' => true, 'message' => __('messages.complaint.escalated'), 'data' => $this->present($c, $request)]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PATCH /api/client/complaints/{id}/cancel
    // ─────────────────────────────────────────────────────────────────────

    public function cancel(Request $request, $id)
    {
        $complaint = $this->ownedComplaints($request)->findOrFail($id);

        $c = $this->returns->clientCancel($complaint, $this->client($request));

        return response()->json(['success' => true, 'message' => __('messages.complaint.cancelled'), 'data' => $this->present($c, $request)]);
    }

    // ── Whose orders / returns ───────────────────────────────────────────

    /** Orders this client may request a return on. */
    protected function ownedOrders(Request $request): Builder
    {
        return Order::where('user_id', $request->user()->id);
    }

    /** Returns this client may see and act on. */
    protected function ownedComplaints(Request $request): Builder
    {
        return Complaint::where('user_id', $request->user()->id);
    }

    /** The account asking (null for a guest). */
    protected function client(Request $request): ?User
    {
        return $request->user();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** The buyer's view of a return (allow-list), as on GET /client/complaints/{id}. */
    private function present(Complaint $c, Request $request): array
    {
        $c->loadMissing('order:id,order_number,total_amount,status,return_status,payment_method,created_at');
        Complaint::withItemSnapshots($c);
        return (new BuyerComplaintResource(ReturnPresenter::forClient($c)))->resolve($request);
    }

    /**
     * What this line can be exchanged for: in-stock variants of the same
     * product at the same price (the current one included, for a defect).
     * Products without variants: the same product again, when in stock.
     *
     * @return array{exchange_options: array<int, array{id:int, label:string, stock:int, current:bool}>, exchange_same_product: bool, exchange_other_price: bool}
     */
    private function exchangeOptions(OrderItem $line): array
    {
        // The rule lives in ReturnPolicy (also used to tell when an exchange is impossible)
        return app(ReturnPolicy::class)->exchangeOptions($line);
    }

    /**
     * PATCH /api/client/complaints/{id}/switch-to-refund (guest: same path under /guest)
     * The shop said the exchange is impossible: one click turns the request into a refund.
     */
    public function switchToRefund(Request $request, $id)
    {
        $request->validate(['item_unused_confirmed' => 'nullable|boolean']);
        $complaint = $this->ownedComplaints($request)->findOrFail($id);

        $c = $this->returns->clientSwitchToRefund($complaint, $this->client($request), $request->has('item_unused_confirmed') ? $request->boolean('item_unused_confirmed') : null);

        return response()->json(['success' => true, 'message' => __('messages.complaint.submitted'), 'data' => $this->present($c, $request)]);
    }

    /** Delivered parcels whose 48 h are over (last 30 days): the orders page says why the button is closed. */
    private function closedOrders(Request $request, ReturnPolicy $policy, array $openIds): array
    {
        $orders = $this->ownedOrders($request)->whereNotIn('id', $openIds ?: [0])
            ->whereHas('sellerOrders', fn($so) => $so->where('status', 'delivered'))
            ->with('sellerOrders')->orderByDesc('updated_at')->limit(30)->get();

        return $orders->map(function (Order $o) use ($policy) {
            $parcels = $o->sellerOrders->where('status', 'delivered');
            $deliveredAt = $parcels->map(fn($so) => $policy->deliveredAt($so))->filter()->max();
            if (!$deliveredAt || $deliveredAt->lt(now()->subDays(30))) {
                return null;
            }
            return [
                'id'               => $o->id,
                'order_number'     => $o->order_number,
                'delivered_at'     => $deliveredAt->toIso8601String(),
                'window_ended_at'  => $deliveredAt->copy()->addHours(Complaint::COMPLAINT_WINDOW_HOURS)->toIso8601String(),
                'closed_reason'    => __('returns_policy.window_closed', ['date' => $deliveredAt->format('d/m/Y')]),
            ];
        })->filter()->values()->all();
    }

    /** Delivered (whole order, or at least one sub-order) within the return window. */
    private function eligibleQuery(Builder $orders)
    {
        $hours = Complaint::COMPLAINT_WINDOW_HOURS;

        // Policy v2: the 48 h count from each parcel's real delivered date (Intigo), not from the order's last update
        if (app(ReturnPolicy::class)->enabled()) {
            $cut = now()->subHours($hours);
            return $orders->whereHas('sellerOrders', fn($so) => $so->where('status', 'delivered')
                ->whereRaw('COALESCE(delivery_confirmed_at, updated_at) >= ?', [$cut]));
        }

        return $orders
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
        $policy    = app(ReturnPolicy::class);
        $delivered = $order->sellerOrders->where('status', 'delivered')
            ->filter(fn($so) => !$policy->enabled() || $policy->windowOpen($so))
            ->pluck('id')->all();
        $reserved  = $this->returns->reservedQuantities($order->id);

        return $order->items
            ->filter(fn($i) => is_null($i->seller_order_id) || in_array($i->seller_order_id, $delivered))
            ->map(function (OrderItem $line) use ($reserved) {
                $qty = (int) $line->quantity;
                $net = $line->net_total !== null ? (float) $line->net_total : (float) $line->total - (float) $line->discount_amount;
                $net += $line->includedFeeTotal();   // as displayed; the exact refund comes from POST …/complaints/preview
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
