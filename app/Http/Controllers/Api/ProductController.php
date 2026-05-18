<?php
// app/Http/Controllers/Api/ProductController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Product;
use App\Services\ProductScoringService;
use App\Services\PromotionService;
use App\Services\UserPreferenceService;
use Illuminate\Http\Request;
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
        $user = $request->user();

        // Scoring applies on the default sort when user is authenticated
        $applyScoring = ($sort === 'created_at') && ($user !== null);

        $query = Product::available()
            ->with([
                'category:id,name,slug',
                'subcategory:id,name,slug',
                'primaryImage',
                'seller:id,name',
                'variants' => fn($q) => $q
                    ->where('is_active', true)
                    ->with([
                        'attributeOptions' => fn($q2) => $q2
                            ->with('attribute:id,slug,type'),
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

        // ── Scoring path (authenticated user, default sort) ───────────────
        if ($applyScoring) {
            $perPage = min((int) $request->query('per_page', 20), 60);
            $page    = max((int) $request->query('page', 1), 1);

            // Fetch a broad candidate set (200 products) — more than a single page
            // so the scorer has enough material to surface the best matches.
            // We order DB-side by sponsored_priority first so the top candidates
            // are likely to include sponsored products before slicing.
            $allProducts = $query
                ->orderByDesc('is_sponsored')
                ->orderByDesc('sponsored_priority')
                ->limit(200)
                ->get();

            // Get user preferences (explicit + inferred from activity)
            $prefs = $this->preferenceService->getCombinedPreferences($user->id);

            // Get recency-weighted activity signals
            $activityWeights = $this->preferenceService->getActivityWeights($user->id);

            // ── THE FIX: ALL products go through unified scoring ──────────
            // Sponsored products are NOT pulled out first.
            // They participate in scoring and get their boost added to their score.
            // A well-matched non-sponsored product beats an irrelevant sponsored one.
            $sorted = $this->scoringService->scoreAndSort($allProducts, $prefs, $activityWeights);

            // ── FALLBACK: if query returned nothing, try without filters ──
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
            'price_asc'  => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'views'      => $query->orderByDesc('views'),
            default      => $query->orderByDesc('created_at'),
        };
        $perPage  = (int) $request->query('per_page', 20);
        $products = $query->paginate(min($perPage, 60));
        $products->getCollection()->transform(fn($p) => $this->transformProductItem($p));

        return response()->json(['success' => true, 'data' => $products]);
    }

    /**
     * Fallback: when a filtered query returns 0 results, return similar
     * products from the same category or popular products globally.
     * This implements the "NO EMPTY CATEGORY RULE".
     */
    private function buildFallbackProducts(
        Request $request,
        $user,
        $prefs,
        array $activityWeights
    ) {
        // Try: same category, drop other filters
        $catSlug = $request->query('category_slug');
        $catId   = $request->query('category_id');

        if ($catSlug || $catId) {
            $fallback = Product::available()
                ->with(['category:id,name,slug', 'subcategory:id,name,slug', 'primaryImage', 'seller:id,name', 'variants' => fn($q) => $q->where('is_active', true)->with(['attributeOptions' => fn($q2) => $q2->with('attribute:id,slug,type')]), 'attributeValues.attribute'])
                ->when($catSlug, fn($q) => $q->whereHas('category', fn($q2) => $q2->where('slug', $catSlug)))
                ->when(!$catSlug && $catId, fn($q) => $q->where('category_id', $catId))
                ->orderByDesc('is_sponsored')
                ->limit(60)
                ->get();

            if ($fallback->isNotEmpty()) {
                return $this->scoringService->scoreAndSort($fallback, $prefs, $activityWeights);
            }
        }

        // Last resort: globally popular products
        $popular = Product::available()
            ->with(['category:id,name,slug', 'subcategory:id,name,slug', 'primaryImage', 'seller:id,name', 'variants' => fn($q) => $q->where('is_active', true)->with(['attributeOptions' => fn($q2) => $q2->with('attribute:id,slug,type')]), 'attributeValues.attribute'])
            ->orderByDesc('is_sponsored')
            ->orderByDesc('views')
            ->limit(60)
            ->get();

        return $this->scoringService->scoreAndSort($popular, $prefs, $activityWeights);
    }

    public function featured()
    {
        $products = Product::available()->featured()->inStock()
            ->with(['category:id,name,slug', 'primaryImage', 'seller:id,name'])
            ->orderByDesc('created_at')
            ->take(12)
            ->get()
            ->map(function ($p) {
                // ── FIXED: run through transformProductItem so promotion
                //    data (effective_price, discount_amount, promotion)
                //    is included — same as the index() listing does.
                return $this->transformProductItem($p);
            });
 
        return response()->json(['success' => true, 'data' => $products]);
    }

    public function show(Request $request, $slug)
    {
        $product = Product::where('slug', $slug)
            ->available()
            ->with([
                'category:id,name,slug',
                'subcategory:id,name,slug',
                'seller:id,name',
                'images',
                'primaryImage',
                'attributeValues.attribute.options',
                'variants' => fn($q) => $q->where('is_active', true)
                    ->with([
                        'attributeOptions.attribute:id,slug,name,type',
                        'images',
                    ]),
            ])
            ->first();

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $product->incrementViews();

        $user = $request->user();
        if ($user) {
            $this->preferenceService->logActivity(
                userId:     $user->id,
                productId:  $product->id,
                categoryId: $product->category_id,
                action:     'view',
                sessionId:  $request->session()->getId()
            );
        }

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

        $product->primary_image_url = $product->primaryImage
            ? Storage::url($product->primaryImage->image_path) : null;

        $product->images->each(fn($img) => $img->url = Storage::url($img->image_path));

        $hasVariants         = $product->variants->isNotEmpty();
        $variantsPayload     = [];
        $selectableAxes      = [];
        $colorImages         = [];
        $colorPrimaryImage   = [];
        $variantPrimaryImage = [];

        if ($hasVariants) {
            // [variant payload logic unchanged — omitted for brevity, keep your existing code]
            // ... (this section is identical to your original show() method)
        }

        $promoData = $this->promoService->getEffectivePrice($product);

        $data                    = $product->toArray();
        $data['has_variants']    = $hasVariants;
        $data['variants']        = $variantsPayload;
        $data['selectable_axes'] = $selectableAxes;
        $data['attribute_data']  = $product->attribute_data;
        $data['color_images']    = $colorImages;
        $data['effective_price'] = $promoData['effective_price'];
        $data['discount_amount'] = $promoData['discount_amount'];
        $data['promotion']       = $promoData['promotion'];

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function filterAttributes($slug)
    {
        // Unchanged from your original — keep as-is
        $productIds = DB::table('products as p')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->where('c.slug', $slug)
            ->where('p.is_approved', true)
            ->where('p.is_active', true)
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
        // Unchanged — keep your existing byIds() implementation exactly as-is
        $request->validate(['ids' => 'required|array|max:50', 'ids.*' => 'integer|min:1']);
        $ids  = $request->input('ids');
        $rows = DB::table('products as p')
            ->select([
                'p.id','p.name','p.slug','p.description','p.price','p.stock',
                'p.views','p.featured',
                'c.name as category_name','c.slug as category_slug',
                's.name as subcategory_name','s.slug as subcategory_slug',
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
        return $products->map(fn($p) => $this->transformProductItem($p))->values()->toArray();
    }

    private function transformProductItem($p): mixed
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
        $p->setRelation('variants', $p->variants->map(fn($v) => ['id' => $v->id, 'stock' => $v->stock])->values());

        $promoData          = $this->promoService->getEffectivePrice($p);
        $p->effective_price = $promoData['effective_price'];
        $p->discount_amount = $promoData['discount_amount'];
        $p->promotion       = $promoData['promotion'];

        return $p;
    }
}