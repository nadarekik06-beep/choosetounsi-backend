<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with(['user:id,name,email']);

        if ($s = $request->query('status'))    $query->where('status', $s);
        if ($s = $request->query('search'))    $query->whereHas('user', fn($q) => $q->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%"));
        if ($d = $request->query('date_from')) $query->whereDate('created_at', '>=', $d);
        if ($d = $request->query('date_to'))   $query->whereDate('created_at', '<=', $d);

        $orders = $query->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 15));

        return response()->json(['success' => true, 'data' => $orders]);
    }

    public function show($id)
    {
        $order = Order::with([
            'user:id,name,email',
            'items',
            'items.product:id,name,slug',
            'items.product.primaryImage',
            // Load variant with images so we can show the right image per item
            'items.variant:id,product_id,sku',
            'items.variant.images',
            'items.variant.attributeOptions.attribute:id,slug,name,type',
        ])->findOrFail($id);

        // Append resolved image URL to each item
        $order->items->each(function ($item) {
            $item->resolved_image_url = $this->resolveItemImage($item);

            // Attach variant option map for display
            if ($item->variant && $item->variant->relationLoaded('attributeOptions')) {
                $item->variant_options = $item->variant->attributeOptions
                    ->mapWithKeys(fn($o) => [
                        $o->attribute->slug => [
                            'value'     => $o->value,
                            'color_hex' => $o->color_hex,
                        ],
                    ]);
            } else {
                $item->variant_options = [];
            }
        });

        return response()->json(['success' => true, 'data' => $order]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|string|in:pending,processing,completed,cancelled,delivered,refunded']);
        $order = Order::findOrFail($id);
        $order->update(['status' => $request->status]);
        return response()->json(['success' => true, 'message' => 'Status updated.', 'data' => $order]);
    }

    // ── Private ────────────────────────────────────────────────────────────

    private function resolveItemImage($item): ?string
    {
        // 1. Stored snapshot (set at checkout)
        if (!empty($item->image_url)) {
            return str_starts_with($item->image_url, 'http') ? $item->image_url : url($item->image_url);
        }
        // 2. Variant-linked image
        if ($item->variant && $item->variant->images->isNotEmpty()) {
            return Storage::url($item->variant->images->first()->image_path);
        }
        // 3. Product primary image
        if ($item->product && $item->product->primaryImage) {
            return Storage::url($item->product->primaryImage->image_path);
        }
        return null;
    }
}