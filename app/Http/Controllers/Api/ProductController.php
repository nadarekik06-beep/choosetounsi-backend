<?php

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
        private PromotionService      $promoService,      // ← PROMO FIX: injected
    ) {}

    public function index(Request $request)
    {
        $sort = $request->query('sort', 'created_at');
        $user = $request->user();

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

        if ($applyScoring) {
            $query->with(['attributeValues.attribute']);
        }

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

        if ($applyScoring) {
            $query->orderByDesc('is_sponsored')->orderByDesc('sponsored_priority');
            $perPage = min((int) $request->query('per_page', 20), 60);
            $page    = max((int) $request->query('page', 1), 1);

            $allProducts  = $query->limit(200)->get();
            $sponsored    = $allProducts->where('is_sponsored', true)->values();
            $nonSponsored = $allProducts->where('is_sponsored', false)->values();
            $prefs        = $this->preferenceService->getCombinedPreferences($user->id);
            $sorted       = $sponsored->concat($this->scoringService->scoreAndSort($nonSponsored, $prefs))->values();

            $total  = $sorted->count();
            $offset = ($page - 1) * $perPage;

            return response()->json(['success' => true, 'data' => [
                'current_page' => $page,
                'data'         => $this->transformProductCollection($sorted->slice($offset, $perPage)->values()),
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => (int) ceil($total / $perPage),
                'from'         => $offset + 1,
                'to'           => min($offset + $perPage, $total),
            ]]);
        } else {
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
    }

    public function featured()
    {
        $products = Product::available()->featured()->inStock()
            ->with(['category:id,name,slug', 'primaryImage'])
            ->orderByDesc('created_at')->take(12)->get()
            ->map(function ($p) {
                $p->primary_image_url = $p->primaryImage
                    ? Storage::url($p->primaryImage->image_path) : null;
                return $p;
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
            $productImageUrls = $product->images
                ->filter(fn($i) => is_null($i->variant_id) && is_null($i->color_option_id))
                ->map(fn($i) => Storage::url($i->image_path))
                ->values()->toArray();

            $variantGroupKeys = [];
            foreach ($product->variants as $v) {
                $colorOpts = $v->attributeOptions
                    ->filter(fn($o) => $o->attribute->slug === 'color')
                    ->sortBy('id')->values();
                if ($colorOpts->isEmpty()) continue;
                $variantGroupKeys[$v->id] = $colorOpts->pluck('id')->implode('|');
            }

            $imagePathColorIds  = [];
            $imagePathIsPrimary = [];
            foreach ($product->images->filter(fn($i) => $i->color_option_id !== null) as $img) {
                $path = $img->image_path;
                if (!isset($imagePathColorIds[$path])) {
                    $imagePathColorIds[$path]  = [];
                    $imagePathIsPrimary[$path] = false;
                }
                $imagePathColorIds[$path][] = $img->color_option_id;
                if ($img->is_primary) {
                    $imagePathIsPrimary[$path] = true;
                }
            }

            $imagePathToGroupKey = [];
            $knownGroupKeys      = array_unique(array_values($variantGroupKeys));

            foreach ($imagePathColorIds as $path => $colorIds) {
                $sortedIds = array_unique($colorIds);
                sort($sortedIds);
                $candidate = implode('|', $sortedIds);

                if (in_array($candidate, $knownGroupKeys, true)) {
                    $imagePathToGroupKey[$path] = $candidate;
                } else {
                    $matched = null;
                    foreach ($knownGroupKeys as $gk) {
                        $gkIds        = array_map('intval', explode('|', $gk));
                        $allContained = true;
                        foreach ($sortedIds as $cid) {
                            if (!in_array($cid, $gkIds, true)) {
                                $allContained = false;
                                break;
                            }
                        }
                        if ($allContained) {
                            $matched = $gk;
                            break;
                        }
                    }
                    $imagePathToGroupKey[$path] = $matched ?? $candidate;
                }
            }

            foreach ($product->images->filter(fn($i) => $i->color_option_id !== null) as $img) {
                $path     = $img->image_path;
                $url      = Storage::url($path);
                $groupKey = $imagePathToGroupKey[$path] ?? null;

                if (!$groupKey) continue;

                if (!in_array($url, $colorImages[$groupKey] ?? [], true)) {
                    $colorImages[$groupKey][] = $url;
                }
                if ($img->is_primary || !isset($colorPrimaryImage[$groupKey])) {
                    $colorPrimaryImage[$groupKey] = $url;
                }

                $strId = (string) $img->color_option_id;
                if (!in_array($url, $colorImages[$strId] ?? [], true)) {
                    $colorImages[$strId][] = $url;
                }
                if ($img->is_primary || !isset($colorPrimaryImage[$strId])) {
                    $colorPrimaryImage[$strId] = $url;
                }
            }

            $variantsPayload = $product->variants->map(function ($v) use (
                $productImageUrls, &$colorImages, &$colorPrimaryImage,
                &$variantPrimaryImage, $variantGroupKeys, $product
            ) {
                $variantImageUrls = $v->images
                    ->map(fn($i) => Storage::url($i->image_path))
                    ->values()->toArray();
                if (!empty($variantImageUrls)) {
                    $variantPrimaryImage[$v->id] = $variantImageUrls[0];
                }

                $colorOpts = $v->attributeOptions
                    ->filter(fn($o) => $o->attribute->slug === 'color')
                    ->sortBy('id')->values();
                $colorOptId = null;
                $groupKey   = null;

                if ($colorOpts->isNotEmpty()) {
                    $colorOptId = $colorOpts->first()->id;
                    $groupKey   = $variantGroupKeys[$v->id] ?? $colorOpts->pluck('id')->implode('|');
                    $strId      = (string) $colorOptId;
                    foreach ($variantImageUrls as $url) {
                        if (!in_array($url, $colorImages[$groupKey] ?? [], true)) $colorImages[$groupKey][] = $url;
                        if (!in_array($url, $colorImages[$strId]   ?? [], true)) $colorImages[$strId][]    = $url;
                    }
                    if ($variantImageUrls) {
                        $colorPrimaryImage[$groupKey] ??= $variantImageUrls[0];
                        $colorPrimaryImage[$strId]    ??= $variantImageUrls[0];
                    }
                }

                $resolvedImages = $variantImageUrls;
                if (empty($resolvedImages) && $groupKey && !empty($colorImages[$groupKey])) {
                    $resolvedImages = array_values(array_unique($colorImages[$groupKey]));
                }
                if (empty($resolvedImages)) $resolvedImages = $productImageUrls;

                // ← PROMO FIX: apply promotion discount to variant base price
                $variantBase       = (float) ($v->price_override ?? $product->price);
                $variantPromoData  = $this->promoService->getEffectivePrice($product, $variantBase);

                return [
                    'id'                => $v->id,
                    'sku'               => $v->sku,
                    'stock'             => $v->stock,
                    'is_active'         => $v->is_active,
                    'price'             => $variantPromoData['effective_price'], // discounted
                    'original_price'    => $variantBase,                         // for strikethrough
                    'price_override'    => $v->price_override,
                    'label'             => $v->label,
                    'option_map'        => $v->option_map,
                    'color_option_id'   => $colorOptId,
                    'color_group_key'   => $groupKey,
                    'image_urls'        => $resolvedImages,
                    'primary_image_url' => $resolvedImages[0] ?? null,
                ];
            })->values();

            $axisMap             = [];
            $colorGroups         = [];
            $registeredOptionIds = [];

            foreach ($product->variants as $variant) {
                $colorOpts    = $variant->attributeOptions
                    ->filter(fn($o) => $o->attribute->slug === 'color')
                    ->sortBy('id')->values();
                $nonColorOpts = $variant->attributeOptions
                    ->filter(fn($o) => $o->attribute->slug !== 'color');

                if ($colorOpts->isNotEmpty() && !isset($axisMap['color'])) {
                    $axisMap['color'] = [
                        'slug'    => 'color',
                        'name'    => $colorOpts->first()->attribute->name,
                        'type'    => 'color',
                        'options' => [],
                    ];
                }

                if ($colorOpts->isNotEmpty()) {
                    $primaryId = $colorOpts->first()->id;
                    $groupKey  = $variantGroupKeys[$variant->id] ?? $colorOpts->pluck('id')->implode('|');

                    if (!isset($colorGroups[$groupKey])) {
                        $resolvedPrimaryImage =
                            $colorPrimaryImage[$groupKey]
                            ?? $variantPrimaryImage[$variant->id]
                            ?? null;

                        $colorGroups[$groupKey] = [
                            'id'            => $primaryId,
                            'group_key'     => $groupKey,
                            'ids'           => $colorOpts->pluck('id')->toArray(),
                            'value'         => $colorOpts->pluck('value')->implode('+'),
                            'color_hex'     => $colorOpts->first()->color_hex,
                            'swatches'      => $colorOpts->map(fn($o) => [
                                'id'        => $o->id,
                                'value'     => $o->value,
                                'color_hex' => $o->color_hex,
                            ])->toArray(),
                            'primary_image' => $resolvedPrimaryImage,
                        ];
                    }
                }

                foreach ($nonColorOpts as $opt) {
                    $axisSlug = $opt->attribute->slug;
                    $optId    = $opt->id;
                    if (!isset($axisMap[$axisSlug])) {
                        $axisMap[$axisSlug] = [
                            'slug'    => $axisSlug,
                            'name'    => $opt->attribute->name,
                            'type'    => $opt->attribute->type,
                            'options' => [],
                        ];
                        $registeredOptionIds[$axisSlug] = [];
                    }
                    if (in_array($optId, $registeredOptionIds[$axisSlug], true)) continue;
                    $axisMap[$axisSlug]['options'][$optId] = [
                        'id'            => $optId,
                        'value'         => $opt->value,
                        'color_hex'     => $opt->color_hex,
                        'primary_image' => null,
                    ];
                    $registeredOptionIds[$axisSlug][] = $optId;
                }
            }

            if (isset($axisMap['color'])) {
                $axisMap['color']['options'] = array_values($colorGroups);
            }
            foreach ($axisMap as $s => &$axis) {
                if ($s !== 'color') $axis['options'] = array_values($axis['options']);
            }
            unset($axis);
            $selectableAxes = array_values($axisMap);
        }

        // ── PROMO FIX: compute promotion data for this product ──────────────
        $promoData = $this->promoService->getEffectivePrice($product);

        $data                    = $product->toArray();
        $data['has_variants']    = $hasVariants;
        $data['variants']        = $variantsPayload;
        $data['selectable_axes'] = $selectableAxes;
        $data['attribute_data']  = $product->attribute_data;
        $data['color_images']    = $colorImages;
        // ── PROMO FIX: append promotion fields so frontend receives them ────
        $data['effective_price'] = $promoData['effective_price'];
        $data['discount_amount'] = $promoData['discount_amount'];
        $data['promotion']       = $promoData['promotion'];

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function filterAttributes($slug)
    {
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

    // ── Private helpers ────────────────────────────────────────────────────────

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

        // ── PROMO FIX: append promotion data to every product card in listings
        $promoData             = $this->promoService->getEffectivePrice($p);
        $p->effective_price    = $promoData['effective_price'];
        $p->discount_amount    = $promoData['discount_amount'];
        $p->promotion          = $promoData['promotion'];

        return $p;
    }
}