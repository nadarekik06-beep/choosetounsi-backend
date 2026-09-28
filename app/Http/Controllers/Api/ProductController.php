<?php
// app/Http/Controllers/Api/ProductController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Product;
use App\Services\ProductScoringService;
use App\Services\PromotionService;
use App\Services\UserPreferenceService;
use App\Services\Recommendation\InteractionTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function __construct(
        private ProductScoringService $scoringService,
        private UserPreferenceService $preferenceService,
        private PromotionService      $promoService,
    ) {}

    public function index(Request $request)
    {
        $sort = $request->query('sort', 'created_at');
        // Public route (no auth:sanctum): the default guard can't see Bearer tokens.
        $user = $request->user() ?? $request->user('sanctum');

        // Scoring applies on the default sort when user is authenticated
        $applyScoring = ($sort === 'created_at') && ($user !== null);

        $query = Product::available()
            ->with([
                'category:id,name,name_fr,name_ar,slug',
                'subcategory:id,name,name_fr,name_ar,slug',
                'primaryImage',
                'seller:id,name',
                'variants' => fn($q) => $q
    ->where('is_active', true)
    ->with([
        'attributeOptions' => fn($q2) => $q2
            ->with('attribute:id,slug,type'),
        'images',           // ← CORRECT: sibling of attributeOptions, not nested inside it
    ]),
            ]);

        // attributeValues needed for brand + gender scoring
        if ($applyScoring) {
            $query->with(['attributeValues.attribute']);
        }

        // ── Filters ──────────────────────────────────────────────────────────
        if ($search = $request->query('search')) {
            $query->where(fn($q) =>
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('short_description', 'like', "%{$search}%")
            );
        }
        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', $categoryId);
        } elseif ($catSlug = $request->query('category_slug')) {
            $query->whereHas('category', fn($q) => $q->where('slug', $catSlug));
        }
        if ($subId = $request->query('subcategory_id')) {
            $query->where('subcategory_id', $subId);
        } elseif ($subSlug = $request->query('subcategory_slug')) {
            $query->whereHas('subcategory', fn($q) => $q->where('slug', $subSlug));
        }
        if ($sellerId = $request->query('seller_id')) {
            $query->where('seller_id', $sellerId);
        }
        if ($priceMin = $request->query('price_min')) {
            $query->where('price', '>=', (float) $priceMin);
        }
        if ($priceMax = $request->query('price_max')) {
            $query->where('price', '<=', (float) $priceMax);
        }
        if (filter_var($request->query('in_stock'), FILTER_VALIDATE_BOOLEAN)) {
            $query->where('stock', '>', 0);
        }
        if ($attrs = $request->query('attrs')) {
            foreach ($attrs as $slug => $values) {
                $query->hasAttribute($slug, (array) $values);
            }
        }
        if ($request->filled('is_pack')) {
            $query->where('is_pack', (int) $request->query('is_pack'));
        }
        if ($request->filled('is_platform_product')) {
            $query->where('is_platform_product', (bool) $request->boolean('is_platform_product'));
        }
        if ($minRating = $request->query('min_rating')) {
            $query->withAvg(
                ['reviews as avg_rating' => fn ($q) => $q->where('status', 'approved')],
                'rating'
            )->having('avg_rating', '>=', (float) $minRating);
        }
        if ($request->boolean('free_delivery')) {
            // 0 = explicitly free; null means "platform default", NOT free — same
            // rule Product::isFreeDelivery() already documents.
            $query->where('delivery_fee', 0);
        }
        if ($request->boolean('has_coupon')) {
            $query->whereHas('coupons', fn ($q) => $q
                ->where('is_active', true)
                ->where(fn ($q2) => $q2->whereNull('usage_limit')->orWhereColumn('usage_count', '<', 'usage_limit'))
            );
        }

        // ── Scoring path (authenticated user, default sort) ───────────────
        if ($applyScoring) {
            $perPage = min((int) $request->query('per_page', 20), 60);
            $page    = max((int) $request->query('page', 1), 1);

            $allProducts = $query
                ->orderByDesc('is_sponsored')
                ->orderByDesc('sponsored_priority')
                ->limit(200)
                ->get();

            $prefs           = $this->preferenceService->getCombinedPreferences($user->id);
            $activityWeights = $this->preferenceService->getActivityWeights($user->id);

            $sorted = $this->scoringService->scoreAndSort($allProducts, $prefs, $activityWeights);

            if ($sorted->isEmpty()) {
                $sorted = $this->buildFallbackProducts($request, $user, $prefs, $activityWeights);
            }

            $total  = $sorted->count();
            $offset = ($page - 1) * $perPage;

            return response()->json(['success' => true, 'data' => [
                'current_page' => $page,
                'data'         => $this->transformProductCollection(
                    $sorted->slice($offset, $perPage)->values()
                ),
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => (int) ceil($total / $perPage),
                'from'         => $offset + 1,
                'to'           => min($offset + $perPage, $total),
            ]]);
        }

        // ── Non-scoring path (guest, or explicit sort) ────────────────────
        $query->orderByDesc('is_sponsored')->orderByDesc('sponsored_priority');
        match ($sort) {
            'price_asc'    => $query->orderBy('price'),
            'price_desc'   => $query->orderByDesc('price'),
            'views'        => $query->orderByDesc('views'),
            // Real sales ranking — sums order_items.quantity for completed/delivered
            // orders only, same convention as SellerAnalyticsController. No fabricated data.
            'best_selling' => $query->withSum(['orderItems as units_sold' => fn ($q) => $q
                ->whereHas('order', fn ($oq) => $oq->whereIn('status', ['completed', 'delivered']))
            ], 'quantity')->orderByDesc('units_sold'),
            default        => $query->orderByDesc('created_at'),
        };
        $perPage  = (int) $request->query('per_page', 20);
        $products = $query->paginate(min($perPage, 60));
        $products->getCollection()->transform(fn($p) => $this->transformProductItem($p));

        return response()->json(['success' => true, 'data' => $products]);
    }

    /**
     * Fallback: when a filtered query returns 0 results, return similar
     * products from the same category or popular products globally.
     */
    private function buildFallbackProducts(
        Request $request,
        $user,
        $prefs,
        array $activityWeights
    ) {
        $catSlug = $request->query('category_slug');
        $catId   = $request->query('category_id');

        if ($catSlug || $catId) {
            $fallback = Product::available()
                ->with(['category:id,name,name_fr,name_ar,slug', 'subcategory:id,name,name_fr,name_ar,slug', 'primaryImage', 'seller:id,name', 'variants' => fn($q) => $q->where('is_active', true)->with(['attributeOptions' => fn($q2) => $q2->with('attribute:id,slug,type')]), 'attributeValues.attribute'])
                ->when($catSlug, fn($q) => $q->whereHas('category', fn($q2) => $q2->where('slug', $catSlug)))
                ->when(!$catSlug && $catId, fn($q) => $q->where('category_id', $catId))
                ->orderByDesc('is_sponsored')
                ->limit(60)
                ->get();

            if ($fallback->isNotEmpty()) {
                return $this->scoringService->scoreAndSort($fallback, $prefs, $activityWeights);
            }
        }

        $popular = Product::available()
            ->with(['category:id,name,name_fr,name_ar,slug', 'subcategory:id,name,name_fr,name_ar,slug', 'primaryImage', 'seller:id,name', 'variants' => fn($q) => $q->where('is_active', true)->with(['attributeOptions' => fn($q2) => $q2->with('attribute:id,slug,type')]), 'attributeValues.attribute'])
            ->orderByDesc('is_sponsored')
            ->orderByDesc('views')
            ->limit(60)
            ->get();

        return $this->scoringService->scoreAndSort($popular, $prefs, $activityWeights);
    }

    public function featured()
    {
        $products = Product::available()->featured()->inStock()
            ->with(['category:id,name,name_fr,name_ar,slug', 'primaryImage', 'seller:id,name'])
            ->orderByDesc('created_at')
            ->take(12)
            ->get()
            ->map(function ($p) {
                return $this->transformProductItem($p);
            });

        return response()->json(['success' => true, 'data' => $products]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  show() — FULLY RESTORED
    //  The entire variant-building block was missing (replaced by a comment).
    //  This is the complete, correct implementation.
    // ═══════════════════════════════════════════════════════════════════════

    public function show(Request $request, $slug)
    {
        $product = Product::where('slug', $slug)
            ->available()
            ->with([
                'category:id,name,name_fr,name_ar,slug',
                'subcategory:id,name,name_fr,name_ar,slug',
                'seller:id,name,avatar',
                'images',
                'primaryImage',
                'attributeValues.attribute.options',
                'variants' => fn($q) => $q->where('is_active', true)
                    ->with([
                        'attributeOptions.attribute:id,slug,name,name_fr,name_ar,type',
                        'images',
                    ]),
            ])
            ->first();

        if (!$product) {
            return response()->json(['success' => false, 'message' => __('messages.not_found.product')], 404);
        }

        if ($product->seller) {
            $branding = $product->seller->storefrontBranding();
            $product->seller->business_name = $branding['business_name'];
            $product->seller->avatar        = $branding['avatar'];
        }

        // Count a visitor once per 30 min so refreshes/SSR prefetches don't inflate views.
        // The personalization "view" signal is sent by the storefront via POST /api/track.
        [$viewerId, $viewerSession] = InteractionTracker::actorFromRequest($request);
        $viewer = $viewerId ? "u{$viewerId}" : ($viewerSession ?: sha1($request->ip() . '|' . $request->userAgent()));
        if (Cache::add("product:viewed:{$product->id}:{$viewer}", 1, 1800)) {
            $product->incrementViews();
        }

        // ── Attribute data (non-variant informational attributes) ──────────
        $product->attribute_data = $product->attributeValues->map(function ($pav) {
            $attr  = $pav->attribute;
            $value = $attr->decodeValue($pav->value);
            $label = $value;
            if (in_array($attr->type, ['select', 'multiselect', 'color'])) {
                $ids   = (array) $value;
                $label = $attr->options->whereIn('id', $ids)->pluck('value')->join(', ');
            }
            return [
                'slug'  => $attr->slug,
                'name'  => $attr->name,
                'type'  => $attr->type,
                'value' => $value,
                'label' => $label,
            ];
        })->keyBy('slug');

        // ── Primary image URL ──────────────────────────────────────────────
        $product->primary_image_url = $product->primaryImage
            ? Storage::url($product->primaryImage->image_path) : null;

        $product->images->each(fn($img) => $img->url = Storage::url($img->image_path));

        // ── Variant system ─────────────────────────────────────────────────
        $hasVariants         = $product->variants->isNotEmpty();
        $variantsPayload     = [];
        $selectableAxes      = [];
        $colorImages         = [];          // string key → url[]
        $colorPrimaryImage   = [];          // string key → first url

        if ($hasVariants) {

            // Gallery + color group images (ProductImages). There are no per-size
            // images: every size of a color shows that color's set, so picking a
            // size never changes the gallery.
            $sets = \App\Services\ProductImages::sets($product);
            $productImageUrls = array_map(fn($i) => Storage::url($i['path']), $sets['gallery']);

            foreach ($sets['color_groups'] as $g) {
                $urls = array_map(fn($i) => Storage::url($i['path']), $g['images']);
                if (!$urls) continue;
                $colorImages[$g['key']]       = $urls;
                $colorPrimaryImage[$g['key']] = $urls[0];
                // Backward compat: also reachable under each single color id
                foreach ($g['color_option_ids'] as $cid) {
                    $colorImages[(string) $cid]       ??= $urls;
                    $colorPrimaryImage[(string) $cid] ??= $urls[0];
                }
            }

            $variantsPayload = $product->variants->map(function ($v) use (
                $productImageUrls, $colorImages, $product
            ) {
                $colorIds      = \App\Services\ProductImages::colorIdsOf($v);
                $colorGroupKey = $colorIds ? implode('|', $colorIds) : null;

                $variantImageUrls = ($colorGroupKey ? ($colorImages[$colorGroupKey] ?? []) : []) ?: $productImageUrls;
                $primaryImageUrl  = $variantImageUrls[0] ?? null;

                $productBasePrice = (float) $product->price;
                $effectiveBase    = $v->price_override !== null
                    ? (float) $v->price_override
                    : $productBasePrice;

                // With a promotion: discounted from this variant's lowest 30-day price
                $variantPromo = $this->promoService->getEffectivePrice($product, $effectiveBase, $v->id);

                return [
                    'id'              => $v->id,
                    'sku'             => $v->sku,
                    'stock'           => $v->stock,
                    'is_active'       => $v->is_active,
                    // Effective price = variant override OR product base price
                    'price'           => $effectiveBase,
                    'effective_price' => $variantPromo['effective_price'],
                    // Crossed-out price when discounted (equals price otherwise)
                    'original_price'  => $variantPromo['original_price'],
                    'price_override'  => $v->price_override !== null ? (float) $v->price_override : null,
                    'label'           => $v->label,
                    // option_map is the accessor on ProductVariant — handles color group grouping
                    'option_map'      => $v->option_map,
                    // color_group_key: pipe-joined sorted color option IDs, e.g. "104|105"
                    // The frontend uses this to match selectedOptions['color'] against variants
                    'color_group_key' => $colorGroupKey,
                    // color_option_id: primary (lowest) color ID — for backward compat
                    'color_option_id' => $v->color_option_id,
                    // image_urls: ordered list of image URLs for this variant
                    'image_urls'      => $variantImageUrls,
                    'primary_image_url' => $primaryImageUrl,
                ];
            })->values()->toArray();

            // ── Step 5: Build selectable_axes ─────────────────────────────────
            //
            // selectable_axes tells the frontend which attribute axes to show
            // as selectors (color swatches, size buttons, etc.) and what options
            // are available for each axis — deduped across all variants.
            //
            // For the color axis: options are keyed by groupKey (e.g. "104|105")
            // so that multi-color variants appear as a single swatch entry.
            //
            // For non-color axes: options are keyed by attribute_option_id.

            $axesMap = [];   // slug → axis definition

            foreach ($product->variants as $v) {
                foreach ($v->attributeOptions as $opt) {
                    $attr = $opt->attribute;
                    $slug = $attr->slug;

                    if (!isset($axesMap[$slug])) {
                        $axesMap[$slug] = [
                            'slug'    => $slug,
                            'name'    => $attr->name,
                            'type'    => $slug === 'color' ? 'color' : 'select',
                            'options' => [],  // keyed by selectionKey
                        ];
                    }
                }
            }

            // Populate options for each axis from variants
            foreach ($product->variants as $v) {
                // ── Color axis ──────────────────────────────────────────────
                $colorOpts = $v->attributeOptions
                    ->filter(fn($o) => $o->attribute->slug === 'color')
                    ->sortBy('id')
                    ->values();

                if ($colorOpts->isNotEmpty() && isset($axesMap['color'])) {
                    $colorIds  = $colorOpts->pluck('id')->toArray();
                    sort($colorIds);
                    $groupKey  = implode('|', $colorIds);

                    if (!isset($axesMap['color']['options'][$groupKey])) {
                        $primaryOpt = $colorOpts->first();

                        // primary_image: the color group's main image
                        $primaryImage = $colorPrimaryImage[$groupKey] ?? null;

                        $axesMap['color']['options'][$groupKey] = [
                            'id'            => $primaryOpt->id,
                            'group_key'     => $groupKey,
                            'ids'           => $colorIds,
                            'value'         => $colorOpts->pluck('value')->join('+'),
                            'color_hex'     => $primaryOpt->color_hex,
                            'primary_image' => $primaryImage,
                            // swatches: individual color entries for rendering split circles
                            'swatches'      => $colorOpts->map(fn($o) => [
                                'id'        => $o->id,
                                'value'     => $o->value,
                                'color_hex' => $o->color_hex,
                            ])->toArray(),
                        ];
                    }
                }

                // ── Non-color axes ──────────────────────────────────────────
                $nonColorOpts = $v->attributeOptions
                    ->filter(fn($o) => $o->attribute->slug !== 'color');

                foreach ($nonColorOpts as $opt) {
                    $slug = $opt->attribute->slug;
                    if (!isset($axesMap[$slug])) continue;

                    $optionKey = (string) $opt->id;
                    if (!isset($axesMap[$slug]['options'][$optionKey])) {
                        $axesMap[$slug]['options'][$optionKey] = [
                            'id'            => $opt->id,
                            'value'         => $opt->value,
                            'color_hex'     => $opt->color_hex ?? null,
                            'primary_image' => null,
                        ];
                    }
                }
            }

            // Convert options maps to sequential arrays
            foreach ($axesMap as $slug => &$axis) {
                $axis['options'] = array_values($axis['options']);
            }
            unset($axis);

            // Only axes that have at least one option become selectable
            $selectableAxes = array_values(array_filter(
                $axesMap,
                fn($axis) => count($axis['options']) > 0
            ));
        }

        // ── Promotion data ─────────────────────────────────────────────────
        $promoData = $this->promoService->getEffectivePrice($product);

        // ── Assemble response ──────────────────────────────────────────────
        $data                    = $product->toArray();
        $data['has_variants']    = $hasVariants;
        $data['variants']        = $variantsPayload;
        $data['selectable_axes'] = $selectableAxes;
        $data['attribute_data']  = $product->attribute_data;
        $data['color_images']    = $colorImages;
        $data['effective_price'] = $promoData['effective_price'];
        $data['original_price']  = $promoData['original_price'];
        $data['discount_amount'] = $promoData['discount_amount'];
        $data['promotion']       = $promoData['promotion'];

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function filterAttributes(Request $request, $slug)
    {
        $productIds = DB::table('products as p')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->where('c.slug', $slug)
            ->where('p.is_approved', true)
            ->where('p.is_active', true)
            ->when($request->filled('seller_id'), fn ($q) => $q->where('p.seller_id', $request->query('seller_id')))
            ->pluck('p.id');

        if ($productIds->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $nonVariantAttrIds = DB::table('product_attribute_values')
            ->whereIn('product_id', $productIds)->distinct()->pluck('attribute_id');
        $variantAttrIds = DB::table('product_variants as pv')
            ->join('variant_attribute_values as vav', 'vav.variant_id', '=', 'pv.id')
            ->join('attribute_options as ao', 'ao.id', '=', 'vav.attribute_option_id')
            ->whereIn('pv.product_id', $productIds)->distinct()->pluck('ao.attribute_id');

        $allAttrIds = $nonVariantAttrIds->merge($variantAttrIds)->unique()->values();
        if ($allAttrIds->isEmpty()) return response()->json(['success' => true, 'data' => []]);

        $attributes = Attribute::whereIn('id', $allAttrIds)
            ->where('is_filterable', true)
            ->with(['options' => fn($q) => $q->orderBy('order')])
            ->orderBy('order')->get()
            ->map(fn($a) => [
                'id'      => $a->id,
                'slug'    => $a->slug,
                'name'    => $a->name,
                'type'    => $a->type,
                'options' => $a->options->map(fn($o) => [
                    'id'        => $o->id,
                    'value'     => $o->value,
                    'color_hex' => $o->color_hex,
                ]),
            ]);

        return response()->json(['success' => true, 'data' => $attributes]);
    }

    public function byIds(\Illuminate\Http\Request $request)
    {
        $request->validate(['ids' => 'required|array|max:50', 'ids.*' => 'integer|min:1']);
        $ids  = $request->input('ids');
        $rows = DB::table('products as p')
            ->select([
                'p.id','p.name','p.slug','p.description','p.translations','p.price','p.stock',
                'p.views','p.featured',
                'c.name as category_name','c.slug as category_slug',
                'c.name_fr as category_name_fr','c.name_ar as category_name_ar',
                's.name as subcategory_name','s.slug as subcategory_slug',
                's.name_fr as subcategory_name_fr','s.name_ar as subcategory_name_ar',
                'pi.image_path as primary_image',
            ])
            ->leftJoin('categories as c',     'c.id',  '=', 'p.category_id')
            ->leftJoin('subcategories as s',   's.id',  '=', 'p.subcategory_id')
            ->leftJoin('product_images as pi', fn($join) =>
                $join->on('pi.product_id', '=', 'p.id')->where('pi.is_primary', '=', 1)
            )
            ->whereIn('p.id', $ids)
            ->where('p.is_approved', 1)
            ->where('p.is_active', 1)
            ->whereNull('p.deleted_at')
            ->get();

        $indexed = $rows->keyBy('id');
        $ordered = collect($ids)->map(function ($id) use ($indexed) {
            $p = $indexed->get($id);
            if (!$p) return null;
            $p->category_name    = \App\Support\Localization::column($p, 'category_name');
            $p->subcategory_name = \App\Support\Localization::column($p, 'subcategory_name');
            $p = \App\Support\Localization::productRow($p, ['name', 'description']);
            return [
                'id'               => $p->id,
                'name'             => $p->name,
                'slug'             => $p->slug,
                'description'      => $p->description,
                'price'            => (float) $p->price,
                'stock'            => (int) $p->stock,
                'views'            => (int) ($p->views ?? 0),
                'featured'         => (bool) ($p->featured ?? false),
                'category_name'    => $p->category_name,
                'category_slug'    => $p->category_slug,
                'subcategory_name' => $p->subcategory_name,
                'subcategory_slug' => $p->subcategory_slug,
                'primary_image'    => $p->primary_image ? Storage::url($p->primary_image) : null,
            ];
        })->filter()->values();

        return response()->json(['success' => true, 'products' => $ordered, 'count' => $ordered->count()]);
    }

    // ── Private helpers ────────────────────────────────────────────────────

 
private function transformProductCollection($products): array
{
    $productIds = $products->pluck('id')->toArray();
    $colorImagesMap = $this->batchLoadColorImages($productIds);

    return $products->map(fn($p) => $this->transformProductItem($p, $colorImagesMap))
        ->values()
        ->toArray();
}
private function safeSessionId(Request $request): ?string
{
    try {
        return $request->session()->getId();
    } catch (\Throwable $e) {
        return null; // API routes may not have session — that's fine
    }
}

// AFTER — add variant_images collection before stripping:
private function transformProductItem($p, ?\Illuminate\Support\Collection $colorImagesMap = null): mixed
{
    $p->primary_image_url  = $p->primaryImage ? Storage::url($p->primaryImage->image_path) : null;
    $p->is_sponsored       = (bool) $p->is_sponsored;
    $p->sponsored_priority = (int)  $p->sponsored_priority;

    $swatches = []; $seen = [];
    foreach ($p->variants as $variant) {
        foreach ($variant->attributeOptions as $opt) {
            if ($opt->attribute && $opt->attribute->slug === 'color'
                && !in_array($opt->id, $seen, true)
            ) {
                $seen[]     = $opt->id;
                $swatches[] = ['id' => $opt->id, 'value' => $opt->value, 'color_hex' => $opt->color_hex];
            }
        }
    }
    $p->color_swatches = $swatches;

    // Load color-keyed images directly — these have color_option_id set, NOT variant_id
// AFTER (uses pre-loaded map, falls back to single query if map not provided):
$variantImages = [];
$colorImgs = $colorImagesMap
    ? $colorImagesMap->get($p->id, collect())
    : \App\Models\ProductImage::where('product_id', $p->id)
        ->whereNotNull('color_option_id')
        ->select('image_path')
        ->get();
foreach ($colorImgs as $img) {
    $url = Storage::url($img->image_path);
    if (!in_array($url, $variantImages, true)) {
        $variantImages[] = $url;
    }
}
$p->variant_images = $variantImages;
    $p->setRelation('variants', $p->variants->map(fn($v) => ['id' => $v->id, 'stock' => $v->stock])->values());

    $promoData          = $this->promoService->getEffectivePrice($p);
    $p->effective_price = $promoData['effective_price'];
    $p->original_price  = $promoData['original_price'];   // lowest 30-day price when discounted
    $p->discount_amount = $promoData['discount_amount'];
    $p->promotion       = $promoData['promotion'];

    return $p;
}
// Add this NEW private method to ProductController:
private function batchLoadColorImages(array $productIds): \Illuminate\Support\Collection
{
    return \App\Models\ProductImage::whereIn('product_id', $productIds)
        ->whereNotNull('color_option_id')
        ->select('product_id', 'image_path')
        ->get()
        ->groupBy('product_id');
}
}