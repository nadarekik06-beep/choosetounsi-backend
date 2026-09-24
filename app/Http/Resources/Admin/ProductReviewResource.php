<?php

namespace App\Http\Resources\Admin;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductModerationLog;
use App\Models\ProductVariant;
use App\Models\SellerSubscription;
use App\Services\CommissionService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Full moderation payload for GET /api/admin/products/{id}/review.
 *
 * Expects the product with the relations loaded by
 * AdminProductController::review(), plus a $context array holding the
 * aggregate data computed there (seller stats, performance, queue, SKU dupes).
 *
 * @property Product $resource
 */
class ProductReviewResource extends JsonResource
{
    public const DEFAULT_LOW_STOCK_THRESHOLD = 5;
    public const MIN_IMAGES                  = 3;
    public const MIN_DESCRIPTION_CHARS       = 50;

    /** Dynamic attributes surfaced in the summary / shipping sections. */
    private const SUMMARY_ATTRS  = ['brand', 'condition'];
    private const SHIPPING_ATTRS = ['weight', 'dimensions', 'length', 'width', 'height', 'delivery_time'];

    private array $context;

    public function __construct(Product $product, array $context = [])
    {
        parent::__construct($product);
        $this->context = $context;
    }

    public function toArray($request): array
    {
        $p         = $this->resource;
        $threshold = (int) ($p->low_stock_threshold ?: self::DEFAULT_LOW_STOCK_THRESHOLD);

        $images   = $this->images($p);
        $variants = $this->variants($p, $threshold);
        $specs    = $this->specifications($p);
        $inventory = $this->inventory($p, $variants, $threshold);
        $pricing  = $this->pricing($p, $variants);
        $status   = $p->moderationStatus();

        $specBySlug = collect($specs)->keyBy('slug');

        return [
            'id'                  => $p->id,
            'name'                => $p->name,
            'slug'                => $p->slug,
            'sku'                 => $p->sku,
            'status'              => $status,
            'is_approved'         => $p->is_approved,
            'is_active'           => $p->is_active,
            'featured'            => $p->featured,
            'is_pack'             => (bool) $p->is_pack,
            'is_platform_product' => (bool) $p->is_platform_product,
            'is_sponsored'        => (bool) $p->is_sponsored,
            'hidden_reason'       => $p->hidden_reason,
            'rejection_reason'    => $p->rejection_reason,
            'seasons'             => collect((array) $p->season)
                ->map(fn($s) => ['value' => $s, 'label' => Product::SEASONS[$s] ?? $s])->values(),

            'created_at'           => optional($p->created_at)->toISOString(),
            'updated_at'           => optional($p->updated_at)->toISOString(),
            'submitted_at'         => $this->submittedAt($p),
            'changes_requested_at' => optional($p->changes_requested_at)->toISOString(),
            'deleted_at'           => optional($p->deleted_at)->toISOString(),

            'storefront_url' => $status === 'approved'
                ? rtrim(config('app.frontend_url'), '/') . '/products/' . $p->slug
                : null,

            'category'      => $p->category ? $p->category->only(['id', 'name', 'slug']) : null,
            'subcategory'   => $p->subcategory ? $p->subcategory->only(['id', 'name', 'slug']) : null,
            'category_path' => array_values(array_filter([
                optional($p->category)->name,
                optional($p->subcategory)->name,
            ])),
            'brand'     => optional($specBySlug->get('brand'))['value'] ?? null,
            'condition' => optional($specBySlug->get('condition'))['value'] ?? null,

            'description'       => $p->description,
            'short_description' => $p->short_description,

            'pricing'        => $pricing,
            'media'          => $images,
            'variants'       => $variants,
            'inventory'      => $inventory,
            'specifications' => array_values(array_filter(
                $specs,
                fn($s) => !in_array($s['slug'], array_merge(self::SUMMARY_ATTRS, self::SHIPPING_ATTRS), true)
            )),
            'shipping'       => $this->shipping($p, $specBySlug),
            'seller'         => $this->seller($p),
            'performance'    => $this->context['performance'] ?? null,
            'history'        => $this->history($p),
            'checks'         => $this->checks($p, $images, $variants, $inventory),
            'queue'          => $this->context['queue'] ?? null,
            'moderation_reasons' => collect(ProductModerationLog::REASONS)
                ->map(fn($label, $code) => ['code' => $code, 'label' => $label])->values(),
        ];
    }

    // ── Media ────────────────────────────────────────────────────────────────

    private function images(Product $p): array
    {
        $url = fn(ProductImage $img) => Storage::disk('public')->url($img->image_path);

        // Color images shared by several colors are stored once per color id with
        // the same file — dedupe by path for the flat gallery.
        $all = $p->images->sortBy([['is_primary', 'desc'], ['order', 'asc'], ['id', 'asc']])->values();

        $gallery = $all->unique('image_path')->values()->map(fn(ProductImage $img) => [
            'id'              => $img->id,
            'url'             => $url($img),
            'is_primary'      => $img->is_primary,
            'order'           => $img->order,
            'variant_id'      => $img->variant_id,
            'color_option_id' => $img->color_option_id,
            'scope'           => $img->variant_id ? 'variant' : ($img->color_option_id ? 'color' : 'general'),
        ]);

        // Map every image row (not deduped) to its gallery id so colors sharing
        // a file still point at an image in the gallery.
        $galleryIdByPath = $gallery->pluck('id', 'url');

        $colors = $all->whereNotNull('color_option_id')
            ->groupBy('color_option_id')
            ->map(function (Collection $imgs, $optionId) use ($url, $galleryIdByPath) {
                $opt = $imgs->first()->colorOption;
                return [
                    'option_id' => (int) $optionId,
                    'name'      => optional($opt)->value ?? ('Color #' . $optionId),
                    'hex'       => optional($opt)->color_hex,
                    'image_ids' => $imgs->map(fn($i) => $galleryIdByPath[$url($i)] ?? $i->id)->unique()->values(),
                ];
            })->values();

        return [
            'images'        => $gallery,
            'count'         => $gallery->count(),
            'general_count' => $gallery->where('scope', 'general')->count(),
            'primary_id'    => optional($gallery->firstWhere('is_primary', true) ?? $gallery->first())['id'] ?? null,
            'colors'        => $colors,
        ];
    }

    // ── Variants ─────────────────────────────────────────────────────────────

    private function variants(Product $p, int $threshold): array
    {
        $colorImages = $p->images->whereNotNull('color_option_id')->groupBy('color_option_id');
        $galleryIdByPath = $p->images->sortBy([['is_primary', 'desc'], ['order', 'asc'], ['id', 'asc']])
            ->unique('image_path')->pluck('id', 'image_path');

        return $p->variants->map(function (ProductVariant $v) use ($p, $threshold, $colorImages, $galleryIdByPath) {
            $options = $v->attributeOptions
                ->sortBy(fn($o) => (optional($o->attribute)->slug === 'color' ? '0' : '1') . optional($o->attribute)->slug)
                ->values();

            // Variant-specific images first, otherwise the images of its color group.
            // Multi-color groups (e.g. Black+Red) may be stored under any of their
            // color ids (see SellerProductController::saveColorImages), so try each.
            $imgs = $v->images;
            if ($imgs->isEmpty()) {
                $colorIds = $options->filter(fn($o) => optional($o->attribute)->slug === 'color')->pluck('id')->sort();
                $hit      = $colorIds->first(fn($id) => isset($colorImages[$id]));
                $imgs     = $hit ? $colorImages[$hit] : collect();
            }

            $price = $v->price_override !== null ? (float) $v->price_override : (float) $p->price;

            return [
                'id'              => $v->id,
                'label'           => $v->label,
                'sku'             => $v->sku,
                'price'           => round($price, 3),
                'price_override'  => $v->price_override !== null ? (float) $v->price_override : null,
                'stock'           => (int) $v->stock,
                'is_active'       => $v->is_active,
                'availability'    => $this->availability((int) $v->stock, $threshold, $v->is_active),
                'color_option_id' => $v->color_option_id,
                'options'         => $options->map(fn($o) => [
                    'id'             => $o->id,
                    'attribute'      => optional($o->attribute)->name,
                    'attribute_slug' => optional($o->attribute)->slug,
                    'value'          => $o->value,
                    'color_hex'      => $o->color_hex,
                ])->values(),
                'image_ids'  => $imgs->map(fn($i) => $galleryIdByPath[$i->image_path] ?? $i->id)->unique()->values(),
                'image_urls' => $imgs->unique('image_path')->map(fn($i) => Storage::disk('public')->url($i->image_path))->values(),
            ];
        })->values()->all();
    }

    private function availability(int $stock, int $threshold, bool $active = true): string
    {
        if (!$active)            return 'inactive';
        if ($stock <= 0)         return 'out_of_stock';
        if ($stock <= $threshold) return 'low_stock';
        return 'in_stock';
    }

    private function inventory(Product $p, array $variants, int $threshold): array
    {
        $v          = collect($variants);
        $hasVariants = $v->isNotEmpty();
        $totalStock = $hasVariants ? (int) $v->sum('stock') : (int) $p->stock;

        return [
            'has_variants'          => $hasVariants,
            'total_stock'           => $totalStock,
            'variant_count'         => $v->count(),
            'active_variant_count'  => $v->where('is_active', true)->count(),
            'out_of_stock_variants' => $v->where('availability', 'out_of_stock')->count(),
            'low_stock_variants'    => $v->where('availability', 'low_stock')->count(),
            'low_stock_threshold'   => $threshold,
            'availability'          => $this->availability($totalStock, $threshold),
        ];
    }

    // ── Pricing ──────────────────────────────────────────────────────────────

    private function pricing(Product $p, array $variants): array
    {
        $base   = (float) $p->price;
        $prices = collect($variants)->pluck('price');
        $min    = $prices->isNotEmpty() ? (float) $prices->min() : $base;
        $max    = $prices->isNotEmpty() ? (float) $prices->max() : $base;

        $now = now();
        $promotions = $p->promotions
            ->filter(fn($promo) => $promo->status !== 'expired' && $promo->ends_at > $now)
            ->sortBy([
                fn($a, $b) => ($a->type === 'flash_sale' ? 0 : 1) <=> ($b->type === 'flash_sale' ? 0 : 1),
                fn($a, $b) => $b->priority <=> $a->priority,
            ])
            ->values();

        $active = $promotions->first(fn($promo) => $promo->isCurrentlyActive());

        $effective = $base;
        if ($active) {
            $effective = $active->discount_type === 'percentage'
                ? $base * (1 - (float) $active->discount_value / 100)
                : max(0, $base - (float) $active->discount_value);
        }

        $plan       = $this->context['seller_plan'] ?? 'free';
        $commission = app(CommissionService::class);
        // Same resolution as checkout, so seller overrides are reflected
        $calc       = $commission->calculateForSeller($p->seller_id, round($effective, 3));

        $formatPromo = fn($promo) => [
            'id'             => $promo->id,
            'name'           => $promo->name,
            'type'           => $promo->type,
            'status'         => $promo->status,
            'discount_type'  => $promo->discount_type,
            'discount_value' => (float) $promo->discount_value,
            'starts_at'      => optional($promo->starts_at)->toISOString(),
            'ends_at'        => optional($promo->ends_at)->toISOString(),
            'is_active_now'  => $promo->isCurrentlyActive(),
            'flash_stock_remaining' => $promo->flashStockRemaining(),
        ];

        return [
            'base_price'      => round($base, 3),
            'min_price'       => round($min, 3),
            'max_price'       => round($max, 3),
            'effective_price' => round($effective, 3),
            'discount_amount' => round($base - $effective, 3),
            'active_promotion' => $active ? $formatPromo($active) : null,
            'promotions'      => $promotions->map($formatPromo)->values(),
            'coupons'         => $p->coupons->where('is_active', true)->map(fn($c) => [
                'id'             => $c->id,
                'code'           => $c->code,
                'label'          => $c->discount_label,
                'min_order_amount' => $c->min_order_amount !== null ? (float) $c->min_order_amount : null,
            ])->values(),
            'delivery' => [
                'fee'        => $p->getEffectiveDeliveryFee(),
                'is_free'    => $p->isFreeDelivery(),
                'is_default' => $p->delivery_fee === null,
            ],
            'commission' => [
                'plan'             => $plan,
                'plan_name'        => \App\Models\SubscriptionPlan::forSlug($plan)->name,
                'on_price'         => $calc['unit_price'],
                'base_rate'        => $calc['base_rate'],
                'plan_reduction'   => $calc['plan_reduction'],
                'rate'             => $calc['commission_percentage'],
                'source'           => $calc['commission_source'],
                'commission_amount'=> $calc['commission_amount'],
                'seller_payout'    => $calc['seller_amount'],
                // Payout at the extremes of the variant price range
                'payout_range'     => $min !== $max ? [
                    'min' => $commission->calculateForSeller($p->seller_id, $min)['seller_amount'],
                    'max' => $commission->calculateForSeller($p->seller_id, $max)['seller_amount'],
                ] : null,
            ],
        ];
    }

    // ── Specifications ───────────────────────────────────────────────────────

    private function specifications(Product $p): array
    {
        return $p->attributeValues
            ->filter(fn($av) => $av->attribute)
            ->sortBy(fn($av) => $av->attribute->order ?? 0)
            ->map(function ($av) {
                $attr    = $av->attribute;
                $decoded = $attr->decodeValue($av->value);
                $hexes   = [];

                if (in_array($attr->type, ['select', 'multiselect', 'color'], true)) {
                    $ids  = array_map('intval', (array) $decoded);
                    $opts = $attr->options->whereIn('id', $ids);
                    $display = $opts->pluck('value')->implode(', ');
                    $hexes   = $opts->pluck('color_hex')->filter()->values()->all();
                    // Unknown ids (option since deleted) → show the raw value
                    if ($display === '' && !empty($decoded)) $display = implode(', ', (array) $decoded);
                } elseif ($attr->type === 'boolean') {
                    $display = $decoded ? 'Yes' : 'No';
                } else {
                    $display = (string) $decoded;
                }

                return [
                    'slug'       => $attr->slug,
                    'name'       => $attr->name,
                    'type'       => $attr->type,
                    'value'      => $display,
                    'color_hexes'=> $hexes,
                ];
            })
            ->filter(fn($s) => $s['value'] !== '')
            ->values()
            ->all();
    }

    private function shipping(Product $p, Collection $specBySlug): array
    {
        $fields = collect(self::SHIPPING_ATTRS)
            ->filter(fn($slug) => $specBySlug->has($slug))
            ->map(fn($slug) => ['label' => $specBySlug[$slug]['name'], 'value' => $specBySlug[$slug]['value']])
            ->values();

        return [
            'delivery_fee'        => $p->getEffectiveDeliveryFee(),
            'is_free_delivery'    => $p->isFreeDelivery(),
            'uses_platform_default' => $p->delivery_fee === null,
            'fields'              => $fields,
        ];
    }

    // ── Seller ───────────────────────────────────────────────────────────────

    private function seller(Product $p): ?array
    {
        $s = $p->seller;
        if (!$s) return null;

        $app   = $s->sellerApplication;
        $sub   = $this->context['subscription'] ?? null;
        $plan  = $this->context['seller_plan'] ?? 'free';
        $stats = $this->context['seller_stats'] ?? [];

        return [
            'id'              => $s->id,
            'name'            => $s->name,
            'email'           => $s->email,
            'store_name'      => optional($app)->business_name ?: $s->name,
            'avatar'          => $app && $app->profile_picture
                ? Storage::disk('public')->url($app->profile_picture)
                : null,
            'location'        => $app ? trim(implode(', ', array_filter([$app->city, $app->wilaya])), ', ') ?: null : null,
            'plan'            => $plan,
            'plan_name'       => \App\Models\SubscriptionPlan::forSlug($plan)->name,
            'plan_color'      => \App\Models\SubscriptionPlan::forSlug($plan)->badge_color,
            'plan_tier_key'   => \App\Models\SubscriptionPlan::forSlug($plan)->tierKey(),
            'subscription_status' => optional($sub)->status,
            'is_active'       => (bool) $s->is_active,
            'is_approved'     => (bool) $s->is_approved,
            'joined_at'       => optional($s->created_at)->toISOString(),
            'account_age_days'=> $s->created_at ? (int) $s->created_at->diffInDays(now()) : null,
            'stats'           => $stats,
            'storefront_url'  => rtrim(config('app.frontend_url'), '/') . '/sellers/' . $s->id,
        ];
    }

    // ── History ──────────────────────────────────────────────────────────────

    private function submittedAt(Product $p): ?string
    {
        $last = $p->moderationLogs->first(fn($l) => in_array($l->action, ['submitted', 'resubmitted'], true));
        return optional($last ? $last->created_at : $p->created_at)->toISOString();
    }

    private function history(Product $p): array
    {
        $events = $p->moderationLogs->map(fn(ProductModerationLog $l) => [
            'id'          => $l->id,
            'action'      => $l->action,
            'from_status' => $l->from_status,
            'to_status'   => $l->to_status,
            'reasons'     => $l->reason_labels,
            'note'        => $l->note,
            'actor'       => $l->admin ? ['id' => $l->admin->id, 'name' => $l->admin->name, 'role' => 'admin']
                                       : ['id' => $p->seller_id, 'name' => optional($p->seller)->name, 'role' => 'seller'],
            'created_at'  => optional($l->created_at)->toISOString(),
        ])->values();

        // Products created before moderation logging existed have no "submitted"
        // entry — synthesize it from created_at so the timeline has a start.
        if (!$p->moderationLogs->contains('action', 'submitted')) {
            $events->push([
                'id'          => null,
                'action'      => 'submitted',
                'from_status' => null,
                'to_status'   => 'pending',
                'reasons'     => [],
                'note'        => null,
                'actor'       => ['id' => $p->seller_id, 'name' => optional($p->seller)->name, 'role' => 'seller'],
                'created_at'  => optional($p->created_at)->toISOString(),
                'synthetic'   => true,
            ]);
        }

        return $events->all();
    }

    // ── Quality checklist ────────────────────────────────────────────────────

    private function checks(Product $p, array $media, array $variants, array $inventory): array
    {
        $v           = collect($variants);
        $descLength  = mb_strlen(trim(strip_tags((string) $p->description)));
        $noImgVariants = $v->filter(fn($row) => count($row['image_ids']) === 0);
        $zeroPriceVariants = $v->filter(fn($row) => $row['price'] <= 0);
        $dupes       = $this->context['duplicate_skus'] ?? [];

        $checks = [
            [
                'key'    => 'min_images',
                'label'  => 'At least ' . self::MIN_IMAGES . ' images',
                'ok'     => $media['count'] >= self::MIN_IMAGES,
                'detail' => $media['count'] . ' image' . ($media['count'] === 1 ? '' : 's'),
            ],
            [
                'key'    => 'variant_images',
                'label'  => 'Every variant has images',
                'ok'     => $noImgVariants->isEmpty(),
                'detail' => $v->isEmpty()
                    ? 'No variants'
                    : ($noImgVariants->isEmpty()
                        ? 'All ' . $v->count() . ' variants covered'
                        : $noImgVariants->count() . ' variant(s) without images: ' . $noImgVariants->pluck('label')->take(5)->implode(', ')),
                'na'     => $v->isEmpty(),
            ],
            [
                'key'    => 'description',
                'label'  => 'Description length (≥ ' . self::MIN_DESCRIPTION_CHARS . ' chars)',
                'ok'     => $descLength >= self::MIN_DESCRIPTION_CHARS,
                'detail' => $descLength . ' characters',
            ],
            [
                'key'    => 'price',
                'label'  => 'Price greater than 0',
                'ok'     => (float) $p->price > 0 && $zeroPriceVariants->isEmpty(),
                'detail' => $zeroPriceVariants->isNotEmpty()
                    ? $zeroPriceVariants->count() . ' variant(s) priced at 0'
                    : number_format((float) $p->price, 3) . ' DT',
            ],
            [
                'key'    => 'stock',
                'label'  => 'Stock available',
                'ok'     => $inventory['total_stock'] > 0,
                'detail' => $inventory['total_stock'] . ' units',
            ],
            [
                'key'    => 'category',
                'label'  => 'Category set',
                'ok'     => (bool) $p->category_id,
                'detail' => implode(' › ', array_filter([optional($p->category)->name, optional($p->subcategory)->name])) ?: 'Missing',
            ],
            [
                'key'    => 'unique_sku',
                'label'  => 'No duplicate SKU',
                'ok'     => empty($dupes),
                'detail' => empty($dupes) ? 'All SKUs unique' : 'Duplicated: ' . implode(', ', array_slice($dupes, 0, 5)),
            ],
        ];

        return $checks;
    }
}
