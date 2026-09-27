<?php

namespace App\Services;

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductEditLog;
use App\Models\ProductImage;
use App\Models\ProductModerationLog;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Notifications\ProductReviewedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin product editor: reads a product as one editable document and saves the
 * whole document back atomically (fields, attributes, variants, every image set).
 *
 * Images come in three scopes, exactly as the storefront reads them
 * (Api\ProductController::show):
 *   gallery      — no variant_id, no color_option_id
 *   color group  — color_option_id rows; one logical image (= one file path) may
 *                  have a row per color of its group
 *   variant      — variant_id rows
 * A logical image is addressed by the lowest row id among the rows sharing its path.
 */
class AdminProductEditor
{
    public const GALLERY_MAX          = 8;   // same cap as the seller form
    public const SET_MAX              = 5;   // per color group / per variant
    public const MAX_COLORS_PER_GROUP = 5;

    // ── Read ─────────────────────────────────────────────────────────────────

    public function payload(Product $product): array
    {
        $product->load([
            'seller:id,name,email',
            'category:id,name,slug',
            'subcategory:id,name,slug,category_id',
            'images',
            'attributeValues.attribute',
            'variants.images',
            'editLogs.admin:id,name',
            'moderationLogs.admin:id,name',
        ]);

        $sets    = $this->imageSets($product);
        $orders  = DB::table('order_items')
            ->whereIn('variant_id', $product->variants->pluck('id'))
            ->select('variant_id')->distinct()->pluck('variant_id')->flip();
        $editor  = $product->admin_edited_by ? User::select('id', 'name')->find($product->admin_edited_by) : null;

        return [
            'product' => [
                'id'                => $product->id,
                'name'              => $product->getRawOriginal('name'),
                'slug'              => $product->slug,
                'sku'               => $product->sku,
                'description'       => $product->getRawOriginal('description'),
                'short_description' => $product->getRawOriginal('short_description'),
                'price'             => (string) $product->price,
                'stock'             => (int) $product->stock,
                'category_id'       => $product->category_id,
                'subcategory_id'    => $product->subcategory_id,
                'is_active'         => (bool) $product->is_active,
                'is_approved'       => (bool) $product->is_approved,
                'is_pack'           => (bool) $product->is_pack,
                'featured'          => (bool) $product->featured,
                'seasons'           => array_values((array) ($product->season ?: ['all_seasons'])),
                'free_delivery'     => $product->isFreeDelivery(),
                'admin_note'        => $product->admin_note,
                'admin_edited_at'   => optional($product->admin_edited_at)->toISOString(),
                'admin_edited_by'   => $editor ? ['id' => $editor->id, 'name' => $editor->name] : null,
                'status'            => $product->moderationStatus(),
                'rejection_reason'  => $product->rejection_reason,
                'seller'            => $product->seller ? $product->seller->only(['id', 'name', 'email']) : null,
                'category'          => $product->category ? $product->category->only(['id', 'name', 'slug']) : null,
                'subcategory'       => $product->subcategory ? $product->subcategory->only(['id', 'name', 'slug']) : null,
                'created_at'        => optional($product->created_at)->toISOString(),
                'updated_at'        => optional($product->updated_at)->toISOString(),
                'storefront_url'    => rtrim(config('app.frontend_url'), '/') . '/products/' . $product->slug,
            ],
            'attributes' => (object) $product->attributeValues
                ->filter(fn($v) => $v->attribute)
                ->mapWithKeys(fn($v) => [$v->attribute->slug => $v->attribute->decodeValue($v->value)])
                ->all(),
            'variants' => $product->variants->map(fn(ProductVariant $v) => [
                'id'             => $v->id,
                'option_ids'     => $v->attributeOptions->pluck('id')->values(),
                'label'          => $v->label,
                'stock'          => (int) $v->stock,
                'price_override' => $v->price_override !== null ? (string) $v->price_override : '',
                'sku'            => $v->sku ?? '',
                'is_active'      => (bool) $v->is_active,
                'has_orders'     => isset($orders[$v->id]),
            ])->values(),
            'images' => $sets,
            'history' => $product->editLogs->take(50)->map(fn($l) => [
                'id'         => $l->id,
                'admin'      => $l->admin ? $l->admin->only(['id', 'name']) : null,
                'summary'    => $l->summary,
                'changes'    => $l->changes,
                'created_at' => optional($l->created_at)->toISOString(),
            ])->values(),
            'moderation' => $product->moderationLogs->take(20)->map(fn($l) => [
                'id'          => $l->id,
                'action'      => $l->action,
                'admin'       => $l->admin ? $l->admin->only(['id', 'name']) : null,
                'reasons'     => $l->reason_labels,
                'note'        => $l->note,
                'created_at'  => optional($l->created_at)->toISOString(),
            ])->values(),
            'limits' => [
                'gallery_max'          => self::GALLERY_MAX,
                'set_max'              => self::SET_MAX,
                'max_colors_per_group' => self::MAX_COLORS_PER_GROUP,
                'max_file_kb'          => 5120,
            ],
            'seasons' => Product::SEASONS,
        ];
    }

    /** Gallery + color group images (see ProductImages — there are no per-size images). */
    public function imageSets(Product $product): array
    {
        return ProductImages::sets($product);
    }

    // ── Write ────────────────────────────────────────────────────────────────

    /**
     * Validates and saves the whole editor document. Returns the edit log (null when
     * nothing changed). Throws ValidationException with field-keyed messages.
     *
     * @param array<string, UploadedFile> $uploads keyed by the manifest's upload keys
     */
    public function save(Product $product, array $data, array $uploads, User $admin): ?ProductEditLog
    {
        $this->validate($product, $data, $uploads);

        $before = $this->snapshot($product->fresh());

        // Files go to disk before the transaction; removed again if it fails.
        $stored = ProductImages::storeUploads($data['images'], $uploads);

        $orphanPaths = [];
        try {
            DB::transaction(function () use ($product, $data, $stored, &$orphanPaths) {
                $this->saveFields($product, $data);
                $this->saveAttributes($product, $data['attributes'] ?? []);
                $this->saveVariants($product, $data['variants'] ?? []);
                $orphanPaths = ProductImages::apply($product, $data['images'], $stored);

                // Stock / active state follow the variants, as in the seller flow
                $product->refresh();
                $variants = $product->variants()->get();
                if ($variants->isNotEmpty()) {
                    $product->stock = (int) $variants->sum('stock');
                    if (!$variants->contains('is_active', true)) $product->is_active = false;
                    $product->save();
                }
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) Storage::disk('public')->delete($path);
            throw $e;
        }

        // Only delete files nothing points at anymore
        ProductImages::deleteUnusedFiles($orphanPaths);

        $after   = $this->snapshot($product->fresh());
        $changes = $this->diff($before, $after, $data);
        if (empty($changes)) return null;

        $product->forceFill(['admin_edited_at' => now(), 'admin_edited_by' => $admin->id])->saveQuietly();

        return ProductEditLog::create([
            'product_id' => $product->id,
            'admin_id'   => $admin->id,
            'summary'    => Str::limit($this->summarize($changes), 497),
            'changes'    => $changes,
        ]);
    }

    // ── Validation ───────────────────────────────────────────────────────────

    private function validate(Product $product, array $data, array $uploads): void
    {
        $validator = validator($data, [
            'name'              => 'required|string|max:255',
            'slug'              => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'sku'               => 'nullable|string|max:100',
            'short_description' => 'nullable|string|max:500',
            'description'       => 'nullable|string|max:20000',
            'price'             => 'required|numeric|gt:0|max:9999999',
            'stock'             => 'required|integer|min:0|max:1000000',
            'category_id'       => 'required|integer|exists:categories,id',
            'subcategory_id'    => 'nullable|integer|exists:subcategories,id',
            'is_active'         => 'required|boolean',
            'is_pack'           => 'required|boolean',
            'free_delivery'     => 'required|boolean',
            'seasons'           => 'required|array|min:1',
            'seasons.*'         => 'string|in:' . implode(',', array_keys(Product::SEASONS)),
            'admin_note'        => 'nullable|string|max:5000',
            'attributes'        => 'present|array',
            'variants'          => 'present|array|max:200',
            'variants.*.id'             => 'nullable|integer',
            'variants.*.key'            => 'required|string|max:40|distinct',
            'variants.*.option_ids'     => 'required|array|min:1',
            'variants.*.option_ids.*'   => 'integer',
            'variants.*.stock'          => 'required|integer|min:0|max:1000000',
            'variants.*.price_override' => 'nullable|numeric|gt:0|max:9999999',
            'variants.*.sku'            => 'nullable|string|max:100',
            'variants.*.is_active'      => 'required|boolean',
            'images'                    => 'required|array',
            'images.gallery'            => 'present|array|max:' . self::GALLERY_MAX,
            'images.color_groups'       => 'present|array',
            'images.color_groups.*.color_option_ids'   => 'required|array|min:1|max:' . self::MAX_COLORS_PER_GROUP,
            'images.color_groups.*.color_option_ids.*' => 'integer',
            'images.color_groups.*.items'              => 'present|array|max:' . self::SET_MAX,
            'approve'                   => 'sometimes|boolean',
        ], [
            'slug.regex'      => 'Use lowercase letters, numbers and single hyphens only.',
            'price.gt'        => 'Price must be greater than 0.',
            'seasons.required'=> 'Select at least one season.',
            'seasons.min'     => 'Select at least one season.',
            'images.gallery.max' => 'The gallery can hold at most ' . self::GALLERY_MAX . ' images.',
            'images.color_groups.*.items.max' => 'A color group can hold at most ' . self::SET_MAX . ' images.',
            'variants.*.price_override.gt'    => 'Variant price must be greater than 0.',
        ], [
            'variants.*.stock' => 'variant stock',
            'variants.*.sku'   => 'variant SKU',
        ]);

        $validator->after(function ($v) use ($product, $data, $uploads) {
            if ($v->errors()->isNotEmpty()) return;
            $this->validateCatalog($v, $data);
            $this->validateVariants($v, $data);
            ProductImages::validate($v, $product, $data['images'], $uploads,
                ProductImages::groupKeysFor(array_column($data['variants'], 'option_ids')));

            if (!empty($data['slug']) && Product::withTrashed()->where('slug', $data['slug'])->where('id', '!=', $product->id)->exists()) {
                $v->errors()->add('slug', 'This slug is already used by another product.');
            }
            $hasImage = !empty($data['images']['gallery'])
                || array_filter(array_column($data['images']['color_groups'], 'items'));
            if (!empty($data['approve']) && !$hasImage) {
                $v->errors()->add('images.gallery', 'Add at least one image before approving.');
            }
        });

        $validator->validate();
    }

    private function validateCatalog($v, array $data): void
    {
        if (empty($data['subcategory_id'])) return;

        $sub = Subcategory::find($data['subcategory_id']);
        if (!$sub || (int) $sub->category_id !== (int) $data['category_id']) {
            $v->errors()->add('subcategory_id', 'This subcategory does not belong to the selected category.');
            return;
        }

        foreach ($this->infoAttributes($sub) as $attr) {
            if (!$attr->pivot->is_required) continue;
            if ($this->isEmptyValue($data['attributes'][$attr->slug] ?? null)) {
                $v->errors()->add("attributes.{$attr->slug}", "{$attr->name} is required.");
            }
        }
    }

    private function validateVariants($v, array $data): void
    {
        $rows = $data['variants'];
        if (empty($rows)) return;

        $axes = !empty($data['subcategory_id'])
            ? $this->variantAxes(Subcategory::find($data['subcategory_id']))
            : collect();

        // option id → attribute
        $optionAttr = [];
        if ($axes->isNotEmpty()) {
            foreach ($axes as $axis) foreach ($axis->options as $o) $optionAttr[$o->id] = $axis;
        } else {
            $ids = collect($rows)->pluck('option_ids')->flatten()->unique()->all();
            AttributeOption::with('attribute')->whereIn('id', $ids)->get()
                ->each(function ($o) use (&$optionAttr) { $optionAttr[$o->id] = $o->attribute; });
        }

        $combos = [];
        foreach ($rows as $i => $row) {
            $ids = array_map('intval', $row['option_ids']);
            $byAxis = [];
            foreach ($ids as $oid) {
                if (!isset($optionAttr[$oid])) {
                    $v->errors()->add("variants.$i.option_ids", 'Contains an option that is not valid for this subcategory.');
                    continue 2;
                }
                $byAxis[$optionAttr[$oid]->slug][] = $oid;
            }
            foreach ($axes as $axis) {
                $picked = $byAxis[$axis->slug] ?? [];
                $isColor = $this->isColorAxis($axis);
                if (!$picked) {
                    $v->errors()->add("variants.$i.option_ids", "Choose a {$axis->name}.");
                } elseif (!$isColor && count($picked) > 1) {
                    $v->errors()->add("variants.$i.option_ids", "Only one {$axis->name} per variant.");
                } elseif ($isColor && count($picked) > self::MAX_COLORS_PER_GROUP) {
                    $v->errors()->add("variants.$i.option_ids", 'At most ' . self::MAX_COLORS_PER_GROUP . ' colors per variant.');
                }
            }
            sort($ids);
            $combo = implode('-', $ids);
            if (isset($combos[$combo])) {
                $v->errors()->add("variants.$i.option_ids", 'Duplicate of another variant (same options).');
            }
            $combos[$combo] = true;
        }

        $existing = collect($rows)->pluck('id')->filter();
        if ($existing->isNotEmpty() && $existing->unique()->count() !== $existing->count()) {
            $v->errors()->add('variants', 'The same variant was sent twice.');
        }
    }

    // ── Save steps ───────────────────────────────────────────────────────────

    private function saveFields(Product $product, array $data): void
    {
        $slugBase = trim((string) ($data['slug'] ?? '')) ?: $data['name'];

        $product->fill([
            'name'              => trim($data['name']),
            'slug'              => $this->uniqueSlug($slugBase, $product->id),
            'sku'               => trim((string) ($data['sku'] ?? '')) ?: null,
            'description'       => ($data['description'] ?? null) !== '' ? ($data['description'] ?? null) : null,
            'short_description' => ($data['short_description'] ?? null) !== '' ? ($data['short_description'] ?? null) : null,
            'price'             => round((float) $data['price'], 3),
            'stock'             => (int) $data['stock'],
            'category_id'       => (int) $data['category_id'],
            'subcategory_id'    => !empty($data['subcategory_id']) ? (int) $data['subcategory_id'] : null,
            'is_active'         => (bool) $data['is_active'],
            'is_pack'           => (bool) $data['is_pack'],
            'season'            => array_values(array_unique($data['seasons'])),
            // Same mapping as the seller form: free → 0, otherwise platform default (null)
            'delivery_fee'      => $data['free_delivery'] ? 0 : null,
            'admin_note'        => trim((string) ($data['admin_note'] ?? '')) ?: null,
        ]);
        $product->save();
    }

    private function saveAttributes(Product $product, array $values): void
    {
        $sub       = $product->subcategory_id ? Subcategory::find($product->subcategory_id) : null;
        $axisIds   = $sub ? $this->variantAxes($sub)->pluck('id')->all() : [];
        $attrs     = Attribute::whereIn('slug', array_keys($values))->get()->keyBy('slug');
        $keptIds   = [];

        foreach ($values as $slug => $value) {
            $attr = $attrs[$slug] ?? null;
            if (!$attr || $this->isEmptyValue($value)) continue;

            if (in_array($attr->type, ['select', 'multiselect', 'color'], true)) {
                $raw = json_encode(array_values(array_map('intval', (array) $value)));
            } elseif ($attr->type === 'boolean') {
                $raw = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            } else {
                $raw = (string) $value;
            }

            ProductAttributeValue::updateOrCreate(
                ['product_id' => $product->id, 'attribute_id' => $attr->id],
                ['value' => $raw]
            );
            $keptIds[] = $attr->id;
        }

        // The form sends every info attribute of the subcategory: anything else is stale
        ProductAttributeValue::where('product_id', $product->id)
            ->whereNotIn('attribute_id', array_merge($keptIds, $axisIds))
            ->delete();
    }

    private function saveVariants(Product $product, array $rows): void
    {
        $existing = $product->variants()->get()->keyBy('id');
        $keyMap   = [];

        foreach ($rows as $row) {
            $variant = !empty($row['id']) ? $existing->get((int) $row['id']) : null;
            $attrs = [
                'stock'          => (int) $row['stock'],
                'price_override' => isset($row['price_override']) && $row['price_override'] !== '' && $row['price_override'] !== null
                    ? round((float) $row['price_override'], 3) : null,
                'sku'            => trim((string) ($row['sku'] ?? '')) ?: null,
                'is_active'      => (bool) $row['is_active'],
            ];

            if ($variant) {
                $variant->update($attrs);
            } else {
                $variant = ProductVariant::create($attrs + ['product_id' => $product->id]);
            }
            $variant->attributeOptions()->sync(array_values(array_unique(array_map('intval', $row['option_ids']))));
            $keyMap[(string) $row['key']] = $variant->id;
        }

        $removed = $existing->keys()->diff(array_values($keyMap))->values();
        if ($removed->isNotEmpty()) {
            // Images aren't touched here: the image manifest decides what stays
            // (legacy per-variant rows just lose their variant_id via the FK).
            // A cart line without its variant can't be checked out — drop it
            Cart::whereIn('variant_id', $removed)->delete();
            ProductVariant::whereIn('id', $removed)->delete();
        }
    }

    // ── Approval ─────────────────────────────────────────────────────────────

    /** Approves the product (same state change as the list's Approve button) and notifies the seller. */
    public function approve(Product $product, User $admin): void
    {
        $from = $product->moderationStatus();

        $product->update(['is_approved' => true, 'rejection_reason' => null, 'changes_requested_at' => null]);
        $product = $product->fresh('seller');
        $product->syncActiveStatusFromVariants();

        $adjusted = $this->adjustedSinceSubmission($product);

        ProductModerationLog::record($product, 'approved', [
            'admin_id'    => $admin->id,
            'from_status' => $from,
            'to_status'   => $product->moderationStatus(),
            'note'        => $adjusted ? 'Approved with admin adjustments' : null,
        ]);

        if ($product->seller) {
            try {
                $product->seller->notify(new ProductReviewedNotification(
                    'approved', $product->id, $product->getRawOriginal('name'), null, [], [], $adjusted
                ));
            } catch (\Throwable $e) {
                Log::warning('[AdminProductEditor::approve] Notification failed: ' . $e->getMessage());
            }
        }
    }

    /** True when an admin edited the product after the seller last submitted it. */
    public function adjustedSinceSubmission(Product $product): bool
    {
        if (!$product->admin_edited_at) return false;

        $submitted = ProductModerationLog::where('product_id', $product->id)
            ->whereIn('action', ['submitted', 'resubmitted'])
            ->max('created_at');

        return !$submitted || $product->admin_edited_at->gte($submitted);
    }

    // ── Change log ───────────────────────────────────────────────────────────

    private function snapshot(Product $product): array
    {
        $product->load(['images', 'attributeValues.attribute', 'variants.images']);

        return [
            'fields' => [
                'name'              => $product->getRawOriginal('name'),
                'slug'              => $product->slug,
                'sku'               => $product->sku,
                'short_description' => $product->getRawOriginal('short_description'),
                'description'       => $product->getRawOriginal('description'),
                'price'             => (string) $product->price,
                'stock'             => (int) $product->stock,
                'category_id'       => $product->category_id,
                'subcategory_id'    => $product->subcategory_id,
                'is_active'         => (bool) $product->is_active,
                'is_pack'           => (bool) $product->is_pack,
                'seasons'           => implode(', ', (array) $product->season),
                'free_delivery'     => $product->isFreeDelivery(),
                'admin_note'        => $product->admin_note,
            ],
            'attributes' => $product->attributeValues->filter(fn($v) => $v->attribute)
                ->mapWithKeys(fn($v) => [$v->attribute->name => $this->readableValue($v->attribute, $v->value)])->sortKeys()->all(),
            'variants' => $product->variants->mapWithKeys(fn($v) => [$v->id => [
                'label'          => $v->label,
                'stock'          => (int) $v->stock,
                'price_override' => $v->price_override !== null ? (string) $v->price_override : null,
                'sku'            => $v->sku,
                'is_active'      => (bool) $v->is_active,
            ]])->all(),
            'images' => collect($this->imageSets($product))->only(['gallery', 'color_groups'])
                ->map(fn($s) => json_decode(json_encode($s), true))->all(),
        ];
    }

    private function diff(array $before, array $after, array $data): array
    {
        $changes = [];
        $long    = ['description', 'short_description', 'admin_note'];

        foreach ($after['fields'] as $k => $new) {
            $old = $before['fields'][$k] ?? null;
            if ((string) json_encode($old) === (string) json_encode($new)) continue;
            $changes['fields'][$k] = in_array($k, $long, true)
                ? ['from' => Str::limit((string) $old, 120), 'to' => Str::limit((string) $new, 120)]
                : ['from' => $old, 'to' => $new];
        }

        $attrKeys = array_unique(array_merge(array_keys($before['attributes']), array_keys($after['attributes'])));
        foreach ($attrKeys as $slug) {
            $o = $before['attributes'][$slug] ?? null;
            $n = $after['attributes'][$slug] ?? null;
            if ($o !== $n) $changes['attributes'][$slug] = ['from' => $o, 'to' => $n];
        }

        foreach ($after['variants'] as $id => $v) {
            if (!isset($before['variants'][$id])) { $changes['variants']['added'][] = $v['label']; continue; }
            foreach ($v as $f => $val) {
                if ($before['variants'][$id][$f] !== $val) {
                    $changes['variants']['updated'][$v['label']][$f] = ['from' => $before['variants'][$id][$f], 'to' => $val];
                }
            }
        }
        foreach ($before['variants'] as $id => $v) {
            if (!isset($after['variants'][$id])) $changes['variants']['removed'][] = $v['label'];
        }

        $paths = function (array $sets) {
            $out = ['gallery' => array_column($sets['gallery'], 'path')];
            foreach ($sets['color_groups'] as $g) $out['color ' . $g['key']] = array_column($g['images'], 'path');
            return $out;
        };
        $b = $paths($before['images']);
        $a = $paths($after['images']);
        $replaced = collect($data['images']['gallery'])
            ->merge(collect($data['images']['color_groups'])->pluck('items')->flatten(1))
            ->filter(fn($i) => !empty($i['replaces']))->count();

        $allB = collect($b)->flatten()->unique();
        $allA = collect($a)->flatten()->unique();
        $img  = array_filter([
            'added'     => max(0, $allA->diff($allB)->count() - $replaced),
            'removed'   => max(0, $allB->diff($allA)->count() - $replaced),
            'replaced'  => $replaced,
            'reordered' => collect($a)->filter(function ($list, $scope) use ($b) {
                $old = array_values(array_intersect($b[$scope] ?? [], $list));
                $new = array_values(array_intersect($list, $b[$scope] ?? []));
                return $old !== $new;
            })->keys()->values()->all(),
        ]);
        if ($img) $changes['images'] = $img;

        return $changes;
    }

    private function summarize(array $changes): string
    {
        $parts = [];
        if (!empty($changes['fields'])) {
            $parts[] = 'Updated ' . implode(', ', array_map(fn($k) => str_replace('_', ' ', $k), array_keys($changes['fields'])));
        }
        if (!empty($changes['attributes'])) {
            $parts[] = count($changes['attributes']) . ' attribute(s) changed';
        }
        $v = $changes['variants'] ?? [];
        if (!empty($v['added']))   $parts[] = count($v['added']) . ' variant(s) added';
        if (!empty($v['updated'])) $parts[] = count($v['updated']) . ' variant(s) edited';
        if (!empty($v['removed'])) $parts[] = count($v['removed']) . ' variant(s) removed';
        $i = $changes['images'] ?? [];
        foreach (['added', 'removed', 'replaced'] as $k) {
            if (!empty($i[$k])) $parts[] = "{$i[$k]} image(s) {$k}";
        }
        if (!empty($i['reordered'])) $parts[] = 'images reordered';

        return implode(' · ', $parts);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Option names instead of ids, so the edit log reads "Cotton → Fleece". */
    private function readableValue(Attribute $attr, ?string $raw): ?string
    {
        if (!in_array($attr->type, ['select', 'multiselect', 'color'], true)) {
            return $attr->type === 'boolean' ? ($raw ? 'Yes' : 'No') : $raw;
        }
        $ids = array_map('intval', (array) $attr->decodeValue($raw));
        return AttributeOption::whereIn('id', $ids)->pluck('value')->implode(', ') ?: null;
    }

    private function isColorAxis($attr): bool
    {
        return $attr->slug === 'color' || $attr->type === 'color';
    }

    private function variantAxes(?Subcategory $sub): Collection
    {
        if (!$sub) return collect();
        return $sub->attributes()->wherePivot('is_variant', true)->with('options')->get()
            ->filter(fn($a) => $a->options->isNotEmpty())->values();
    }

    private function infoAttributes(Subcategory $sub): Collection
    {
        return $sub->attributes()->wherePivot('is_variant', false)->get();
    }

    private function isEmptyValue($value): bool
    {
        return $value === null || $value === '' || (is_array($value) && count($value) === 0);
    }

    private function uniqueSlug(string $base, int $excludeId): string
    {
        $slug = Str::slug($base) ?: 'product';
        $original = $slug;
        $n = 2;
        while (Product::withTrashed()->where('slug', $slug)->where('id', '!=', $excludeId)->exists()) {
            $slug = $original . '-' . $n++;
        }
        return $slug;
    }
}
