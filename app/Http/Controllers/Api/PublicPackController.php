<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pack;
use Illuminate\Http\Request;

class PublicPackController extends Controller
{
    /**
     * GET /api/packs
     * Public listing — no auth required.
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->input('per_page', 12), 48);
        $sort    = $request->input('sort', 'created_at');

        $packs = Pack::where('is_active', true)
            ->where('is_approved', true)
            ->with([
                'seller:id,name',
                'items' => fn($q) => $q->orderBy('order')->with([
                    'product:id,name,slug,price',
                    'product.primaryImage',
                ]),
            ])
            ->when($sort === 'savings_desc', fn($q) =>
                $q->orderByRaw('(original_price - pack_price) DESC')
            )
            ->when($sort === 'price_asc',  fn($q) => $q->orderBy('pack_price'))
            ->when($sort === 'price_desc', fn($q) => $q->orderByDesc('pack_price'))
            ->when($sort === 'created_at' || !in_array($sort, ['savings_desc','price_asc','price_desc']),
                fn($q) => $q->orderByDesc('created_at')
            )
            ->paginate($perPage);

        $packs->getCollection()->transform(fn($pack) => $this->formatPackCard($pack));

        return response()->json(['success' => true, 'data' => $packs]);
    }

    /**
     * GET /api/packs/{slug}
     * Public detail — no auth required.
     */
    public function show(Request $request, string $slug)
    {
        $pack = Pack::where('slug', $slug)
            ->where('is_active', true)
            ->where('is_approved', true)
            ->with([
                'seller:id,name',
                'items' => fn($q) => $q->orderBy('order')->with([
                    'product:id,name,slug,price,stock',
                    'product.primaryImage',
                    'product.variants' => fn($q) => $q
                        ->where('is_active', true)
                        ->with(['attributeOptions.attribute:id,slug,name,type']),
                ]),
            ])
            ->first();

        if (!$pack) {
            return response()->json(['success' => false, 'message' => 'Pack not found.'], 404);
        }

        // Increment views
        $pack->increment('views');

        return response()->json(['success' => true, 'data' => $this->formatPackDetail($pack)]);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Minimal format for the listing cards (no variant details).
     */
    private function formatPackCard(Pack $pack): array
    {
        return [
            'id'                => $pack->id,
            'name'              => $pack->name,
            'slug'              => $pack->slug,
            'short_description' => $pack->short_description,
            'image_url'         => $pack->image_url,   // accessor on Pack model
            'pack_price'        => (float) $pack->pack_price,
            'original_price'    => (float) $pack->original_price,
            'savings'           => (float) $pack->savings,
            'items_count'       => $pack->items->count(),
            'seller'            => $pack->seller ? [
                'id'   => $pack->seller->id,
                'name' => $pack->seller->name,
            ] : null,
            'items' => $pack->items->map(fn($item) => [
                'id'       => $item->id,
                'quantity' => $item->quantity,
                'product'  => $item->product ? [
                    'name'              => $item->product->name,
                    'primary_image_url' => $item->product->primary_image_url,
                ] : null,
            ])->values(),
        ];
    }

    /**
     * Full format for the detail page (with variant data for selection).
     */
    private function formatPackDetail(Pack $pack): array
    {
        $data = [
            'id'                => $pack->id,
            'name'              => $pack->name,
            'slug'              => $pack->slug,
            'description'       => $pack->description,
            'short_description' => $pack->short_description,
            'image_url'         => $pack->image_url,
            'pack_price'        => (float) $pack->pack_price,
            'original_price'    => (float) $pack->original_price,
            'savings'           => (float) $pack->savings,
            'is_active'         => $pack->is_active,
            'items_count'       => $pack->items->count(),
            'seller'            => $pack->seller ? [
                'id'   => $pack->seller->id,
                'name' => $pack->seller->name,
            ] : null,
        ];

        $data['items'] = $pack->items->map(function ($item) {
            $allVariants = $item->product?->variants ?? collect();
            $allowedIds  = $item->allowed_variant_ids; // cast to array on model

            $availableVariants = $allVariants
                ->when(!empty($allowedIds), fn($c) => $c->whereIn('id', $allowedIds))
                ->map(fn($v) => [
                    'id'             => $v->id,
                    'label'          => $v->label,
                    'stock'          => $v->stock,
                    'price_override' => $v->price_override,
                    'option_map'     => $v->option_map,
                ])
                ->values();

            return [
                'id'                  => $item->id,
                'product_id'          => $item->product_id,
                'quantity'            => $item->quantity,
                'allowed_variant_ids' => $item->allowed_variant_ids,
                'available_variants'  => $availableVariants,
                'product'             => $item->product ? [
                    'id'                => $item->product->id,
                    'name'              => $item->product->name,
                    'slug'              => $item->product->slug,
                    'price'             => (float) $item->product->price,
                    'primary_image_url' => $item->product->primary_image_url,
                    'has_variants'      => $allVariants->isNotEmpty(),
                ] : null,
            ];
        })->values();

        return $data;
    }
}