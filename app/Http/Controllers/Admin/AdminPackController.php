<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pack;
use App\Models\PackItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * AdminPackController
 *
 * Handles admin-side pack management:
 *   GET    /api/admin/packs/stats     → aggregate stats
 *   GET    /api/admin/packs           → paginated list with filters
 *   GET    /api/admin/packs/{id}      → single pack with full items
 *   PATCH  /api/admin/packs/{id}/approve → approve pack (make live)
 *   PATCH  /api/admin/packs/{id}/reject  → reject pack (deactivate + flag)
 *   DELETE /api/admin/packs/{id}         → hard delete
 *
 * Commission note: packs use pack_price as the unit — commission is
 * calculated on the WHOLE pack_price, not per item inside the pack.
 * This is consistent with CommissionService::calculate($pack->pack_price, $plan).
 */
class AdminPackController extends Controller
{
    // ── Stats ──────────────────────────────────────────────────────────────────

    public function stats()
    {
        return response()->json(['success' => true, 'data' => [
            'total'    => Pack::count(),
            'approved' => Pack::where('is_approved', true)->count(),
            'pending'  => Pack::where('is_approved', false)->count(),
            'active'   => Pack::where('is_active', true)->count(),
            'inactive' => Pack::where('is_active', false)->count(),
        ]]);
    }

    // ── Index ──────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $query = Pack::with([
            'seller:id,name,email',
            'items' => fn($q) => $q->with('product:id,name,price'),
        ]);

        // ── Filters ────────────────────────────────────────────────────────
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhereHas('seller', fn($q2) =>
                      $q2->where('name', 'like', "%{$s}%")
                         ->orWhere('email', 'like', "%{$s}%")
                  );
            });
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'approved' => $query->where('is_approved', true),
                'pending'  => $query->where('is_approved', false),
                'active'   => $query->where('is_active', true),
                'inactive' => $query->where('is_active', false),
                default    => null,
            };
        }

        if ($request->filled('seller_id')) {
            $query->where('seller_id', $request->seller_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $packs = $query->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 15));

        $packs->getCollection()->transform(fn($pack) => $this->formatPack($pack));

        return response()->json(['success' => true, 'data' => $packs]);
    }

    // ── Show ───────────────────────────────────────────────────────────────────

    public function show(int $id)
    {
        $pack = Pack::with([
            'seller:id,name,email,plan,created_at',
            'items' => fn($q) => $q->orderBy('order')->with([
                'product:id,name,slug,price,is_active,is_approved',
                'product.primaryImage',
                'product.variants' => fn($q) => $q
                    ->where('is_active', true)
                    ->with(['attributeOptions.attribute:id,slug,name,type']),
            ]),
        ])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->formatPack($pack, true)]);
    }

    // ── Approve ────────────────────────────────────────────────────────────────

    public function approve(int $id)
    {
        $pack = Pack::with('seller:id,name,email')->findOrFail($id);

        if ($pack->is_approved) {
            return response()->json([
                'success' => false,
                'message' => 'Pack is already approved.',
            ], 422);
        }

        $pack->update([
            'is_approved' => true,
            'is_active'   => true,
        ]);

        // Notify seller
        try {
            $pack->seller?->notify(new \App\Notifications\PackApprovedNotification($pack));
        } catch (\Throwable $e) {
            Log::warning('[AdminPack::approve] Notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => "Pack \"{$pack->name}\" approved and is now live.",
            'data'    => $this->formatPack($pack),
        ]);
    }

    // ── Reject ─────────────────────────────────────────────────────────────────

    public function reject(Request $request, int $id)
    {
        $pack = Pack::with('seller:id,name,email')->findOrFail($id);

        $pack->update([
            'is_approved' => false,
            'is_active'   => false,
        ]);

        // Optionally store rejection reason in a JSON column or log
        $reason = $request->input('reason', 'Does not meet marketplace standards.');

        try {
            $pack->seller?->notify(
                new \App\Notifications\PackRejectedNotification($pack, $reason)
            );
        } catch (\Throwable $e) {
            Log::warning('[AdminPack::reject] Notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => "Pack \"{$pack->name}\" has been rejected.",
            'data'    => $this->formatPack($pack),
        ]);
    }

    // ── Toggle active (without changing approval) ──────────────────────────────

    public function toggle(int $id)
    {
        $pack = Pack::findOrFail($id);
        $pack->update(['is_active' => !$pack->is_active]);

        return response()->json([
            'success' => true,
            'message' => $pack->is_active ? 'Pack activated.' : 'Pack deactivated.',
            'data'    => $this->formatPack($pack),
        ]);
    }

    // ── Destroy ────────────────────────────────────────────────────────────────

    public function destroy(int $id)
    {
        $pack = Pack::findOrFail($id);
        $name = $pack->name;

        // Boot() handles image cleanup
        $pack->delete();

        return response()->json([
            'success' => true,
            'message' => "Pack \"{$name}\" permanently deleted.",
        ]);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function formatPack(Pack $pack, bool $withItems = false): array
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
            'savings'           => $pack->savings,
            'is_active'         => $pack->is_active,
            'is_approved'       => $pack->is_approved,
            'views'             => $pack->views,
            'created_at'        => $pack->created_at,
            'updated_at'        => $pack->updated_at,
            'seller'            => $pack->relationLoaded('seller') ? [
                'id'    => $pack->seller->id,
                'name'  => $pack->seller->name,
                'email' => $pack->seller->email,
                'plan'  => $pack->seller->plan ?? 'free',
            ] : null,
            'items_count'       => $pack->relationLoaded('items')
                ? $pack->items->count()
                : $pack->items()->count(),
        ];

        if ($withItems) {
            $items = $pack->relationLoaded('items')
                ? $pack->items
                : $pack->items()->with([
                    'product:id,name,slug,price,is_active,is_approved',
                    'product.primaryImage',
                    'product.variants' => fn($q) => $q
                        ->where('is_active', true)
                        ->with(['attributeOptions.attribute:id,slug,name,type']),
                ])->orderBy('order')->get();

            $data['items'] = $items->map(function ($item) {
                $allVariants    = $item->product?->variants ?? collect();
                $allowedIds     = $item->allowed_variant_ids;

                $availableVariants = $allVariants
                    ->when(!empty($allowedIds), fn($c) => $c->whereIn('id', $allowedIds))
                    ->map(fn($v) => [
                        'id'             => $v->id,
                        'label'          => $v->label,
                        'stock'          => $v->stock,
                        'price_override' => $v->price_override,
                    ])->values();

                return [
                    'id'                  => $item->id,
                    'product_id'          => $item->product_id,
                    'allowed_variant_ids' => $item->allowed_variant_ids,
                    'quantity'            => $item->quantity,
                    'unit_price_snapshot' => (float) $item->unit_price_snapshot,
                    'order'               => $item->order,
                    'available_variants'  => $availableVariants,
                    'product'             => $item->product ? [
                        'id'                => $item->product->id,
                        'name'              => $item->product->name,
                        'slug'              => $item->product->slug,
                        'price'             => (float) $item->product->price,
                        'is_active'         => $item->product->is_active,
                        'is_approved'       => $item->product->is_approved,
                        'primary_image_url' => $item->product->primary_image_url,
                        'has_variants'      => $allVariants->isNotEmpty(),
                    ] : null,
                ];
            })->values();
        }

        return $data;
    }
}