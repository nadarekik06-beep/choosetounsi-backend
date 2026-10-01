<?php

namespace App\Services;

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductChangeItem;
use App\Models\ProductChangeSet;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Notifications\ProductChangedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Sellers edit their products directly; this service keeps the trace.
 *
 *   $before = $tracker->snapshot($product);
 *   …apply the seller's edit…
 *   $tracker->record($product, $before, $seller);
 *
 * One save = one ProductChangeSet (+ one item per changed field) and at most one
 * admin notification. Stock-only saves are logged but not notified. Sensitive
 * changes (name, category, images, price moves beyond ±50%) are flagged.
 */
class ProductChangeTracker
{
    public const SENSITIVE_PRICE_RATIO = 0.5;

    /** Product columns tracked field by field: field => [group, label, revertible]. */
    private const FIELDS = [
        'name'              => ['text',     'Name'],
        'slug'              => ['text',     'URL slug'],
        'sku'               => ['text',     'SKU'],
        'short_description' => ['text',     'Short description'],
        'description'       => ['text',     'Description'],
        'price'             => ['price',    'Price'],
        'stock'             => ['stock',    'Stock'],
        'category_id'       => ['category', 'Category'],
        'subcategory_id'    => ['category', 'Subcategory'],
        'is_active'         => ['settings', 'Active'],
        'is_pack'           => ['settings', 'Sold as pack'],
        'season'            => ['settings', 'Seasons'],
        'delivery_fee'      => ['settings', 'Delivery fee'],
    ];

    private const VARIANT_FIELDS = [
        'stock'          => ['stock',    'stock'],
        'price_override' => ['price',    'price'],
        'sku'            => ['variants', 'SKU'],
        'is_active'      => ['variants', 'active'],
    ];

    // ── Snapshot / diff ──────────────────────────────────────────────────────

    public function snapshot(Product $product): array
    {
        $product = Product::withTrashed()->with(['images', 'attributeValues.attribute', 'variants.images'])->find($product->id);

        $fields = [];
        foreach (array_keys(self::FIELDS) as $f) {
            $v = $f === 'name' || $f === 'description' || $f === 'short_description'
                ? $product->getRawOriginal($f)
                : $product->getAttributes()[$f] ?? null;
            $fields[$f] = $this->normalize($f, $v);
        }

        $sets = ProductImages::sets($product);
        $images = ['gallery' => array_column($sets['gallery'], 'path')];
        foreach ($sets['color_groups'] as $g) $images['color ' . $g['key']] = array_column($g['images'], 'path');

        return [
            'fields'     => $fields,
            'attributes' => $product->attributeValues->filter(fn($v) => $v->attribute)
                ->mapWithKeys(fn($v) => [$v->attribute->slug => $v->value])->all(),
            'variants'   => $product->variants->mapWithKeys(fn(ProductVariant $v) => [$v->id => [
                'label'          => $v->label,
                'stock'          => (int) $v->stock,
                'price_override' => $v->price_override !== null ? round((float) $v->price_override, 3) : null,
                'sku'            => $v->sku,
                'is_active'      => (bool) $v->is_active,
            ]])->all(),
            'images'     => $images,
        ];
    }

    /** Logs the difference between $before and the product's current state. */
    public function record(Product $product, array $before, ?User $seller, string $source = 'seller_edit', bool $notify = true): ?ProductChangeSet
    {
        try {
            $after = $this->snapshot($product);
            $items = $this->diff($before, $after);
            if (!$items) return null;

            $reasons   = array_values(array_unique(array_merge(...array_map(fn($i) => $i['reasons'], $items))));
            $groups    = array_values(array_unique(array_column($items, 'group')));
            $stockOnly = $groups === ['stock'];

            $set = DB::transaction(function () use ($product, $seller, $source, $items, $reasons, $groups, $stockOnly) {
                $set = ProductChangeSet::create([
                    'product_id'        => $product->id,
                    'seller_id'         => $seller?->id ?? $product->seller_id,
                    'source'            => $source,
                    'summary'           => Str::limit($this->summarize($items), 497),
                    'groups'            => $groups,
                    'is_sensitive'      => !empty($reasons),
                    'sensitive_reasons' => $reasons ?: null,
                    'stock_only'        => $stockOnly,
                ]);
                foreach ($items as $i) {
                    $set->items()->create([
                        'field'        => $i['field'],
                        'group'        => $i['group'],
                        'label'        => Str::limit($i['label'], 147),
                        'old_value'    => $i['old'],
                        'new_value'    => $i['new'],
                        'is_sensitive' => !empty($i['reasons']),
                        'revertible'   => $i['revertible'],
                    ]);
                }
                return $set;
            });

            if ($notify && !$stockOnly) $this->notifyAdmins($set, $product, $seller);

            return $set;
        } catch (\Throwable $e) {
            // The seller's edit is already saved — the trace must never break it
            Log::error('[ProductChangeTracker] ' . $e->getMessage(), ['product_id' => $product->id]);
            return null;
        }
    }

    public function diff(array $b, array $a): array
    {
        $items = [];

        foreach (self::FIELDS as $f => [$group, $label]) {
            $old = $b['fields'][$f] ?? null;
            $new = $a['fields'][$f] ?? null;
            if ($old === $new) continue;

            $reasons = [];
            if ($f === 'name') $reasons[] = 'name_changed';
            if ($f === 'category_id' || $f === 'subcategory_id') $reasons[] = 'category_changed';
            if ($f === 'price' && $this->bigMove($old, $new)) $reasons[] = 'price_jump';

            $items[] = compact('group', 'label') + ['field' => $f, 'old' => $old, 'new' => $new, 'reasons' => $reasons, 'revertible' => true];
        }

        foreach ($a['attributes'] + $b['attributes'] as $slug => $_) {
            $old = $b['attributes'][$slug] ?? null;
            $new = $a['attributes'][$slug] ?? null;
            if ($old === $new) continue;
            $items[] = ['field' => "attribute.$slug", 'group' => 'attributes', 'label' => $this->attributeName($slug),
                        'old' => $old, 'new' => $new, 'reasons' => [], 'revertible' => true];
        }

        $basePriceOld = (float) ($b['fields']['price'] ?? 0);
        $basePriceNew = (float) ($a['fields']['price'] ?? 0);
        foreach ($a['variants'] as $id => $v) {
            $old = $b['variants'][$id] ?? null;
            if (!$old) {
                $items[] = ['field' => "variant.$id.added", 'group' => 'variants', 'label' => "Variant added: {$v['label']}",
                            'old' => null, 'new' => $v, 'reasons' => [], 'revertible' => false];
                continue;
            }
            foreach (self::VARIANT_FIELDS as $f => [$group, $what]) {
                if ($old[$f] === $v[$f]) continue;
                $reasons = [];
                if ($f === 'price_override'
                    && $this->bigMove($old[$f] ?? $basePriceOld, $v[$f] ?? $basePriceNew)) {
                    $reasons[] = 'price_jump';
                }
                $items[] = ['field' => "variant.$id.$f", 'group' => $group, 'label' => "{$v['label']} · $what",
                            'old' => $old[$f], 'new' => $v[$f], 'reasons' => $reasons, 'revertible' => true];
            }
        }
        foreach ($b['variants'] as $id => $v) {
            if (!isset($a['variants'][$id])) {
                $items[] = ['field' => "variant.$id.removed", 'group' => 'variants', 'label' => "Variant removed: {$v['label']}",
                            'old' => $v, 'new' => null, 'reasons' => [], 'revertible' => false];
            }
        }

        foreach ($a['images'] + $b['images'] as $scope => $_) {
            $old = $b['images'][$scope] ?? [];
            $new = $a['images'][$scope] ?? [];
            if ($old === $new) continue;
            $added   = count(array_diff($new, $old));
            $removed = count(array_diff($old, $new));
            $label = $this->scopeLabel($scope) . ' images'
                . ($added ? " · $added added" : '') . ($removed ? " · $removed removed" : '')
                . (!$added && !$removed ? ' · reordered' : '');
            $items[] = ['field' => "images.$scope", 'group' => 'images', 'label' => $label,
                        'old' => $old, 'new' => $new, 'reasons' => ['images_changed'], 'revertible' => false];
        }

        return $items;
    }

    // ── Revert ───────────────────────────────────────────────────────────────

    /**
     * Restores the old value of the given items (all revertible ones when null).
     * Refuses items whose current value no longer matches what the seller set,
     * unless $force — a later edit would otherwise be silently undone.
     *
     * @return array{reverted: int[], conflicts: array<int, string>, skipped: int[]}
     */
    public function revert(ProductChangeSet $set, User $admin, ?array $itemIds = null, bool $force = false): array
    {
        $product = Product::withTrashed()->findOrFail($set->product_id);
        $items   = $set->items()->whereNull('reverted_at')
            ->when($itemIds, fn($q) => $q->whereIn('id', $itemIds))->get();

        $current = $this->snapshot($product);
        $result  = ['reverted' => [], 'conflicts' => [], 'skipped' => []];
        $todo    = [];

        foreach ($items as $item) {
            if (!$item->revertible) { $result['skipped'][] = $item->id; continue; }
            $now = $this->currentValue($current, $item->field);
            if ($now === '__missing__') { $result['conflicts'][$item->id] = 'The variant no longer exists.'; continue; }
            if (!$force && !$this->same($now, $item->new_value)) {
                $result['conflicts'][$item->id] = 'Changed again since (now: ' . $this->display($item->field, $now) . ').';
                continue;
            }
            $todo[] = $item;
        }

        if ($todo) {
            DB::transaction(function () use ($todo, $product) {
                foreach ($todo as $item) $this->apply($product, $item->field, $item->old_value);
                ProductChangeItem::whereIn('id', collect($todo)->pluck('id'))->update(['reverted_at' => now()]);
            });
            $result['reverted'] = collect($todo)->pluck('id')->all();

            // Keep stock/active consistent with the variants, as seller saves do
            $product->refresh();
            if ($product->variants()->exists()) {
                $product->update(['stock' => (int) $product->variants()->sum('stock')]);
                $product->syncActiveStatusFromVariants();
            }

            $this->record($product, $current, $admin, 'admin_revert', false);
        }

        if (!$set->items()->where('revertible', true)->whereNull('reverted_at')->exists()) {
            $set->update(['reverted_at' => now(), 'reverted_by' => $admin->id]);
        }

        return $result;
    }

    private function currentValue(array $snap, string $field)
    {
        if (isset(self::FIELDS[$field])) return $snap['fields'][$field] ?? null;
        if (str_starts_with($field, 'attribute.')) return $snap['attributes'][substr($field, 10)] ?? null;
        if (preg_match('/^variant\.(\d+)\.(\w+)$/', $field, $m)) {
            return isset($snap['variants'][$m[1]]) ? $snap['variants'][$m[1]][$m[2]] : '__missing__';
        }
        return '__missing__';
    }

    private function apply(Product $product, string $field, $value): void
    {
        if (isset(self::FIELDS[$field])) {
            $product->forceFill([$field => $field === 'season' ? json_decode($value ?? '[]', true) : $value])->save();
            return;
        }
        if (str_starts_with($field, 'attribute.')) {
            $attr = Attribute::where('slug', substr($field, 10))->first();
            if (!$attr) return;
            $value === null
                ? ProductAttributeValue::where('product_id', $product->id)->where('attribute_id', $attr->id)->delete()
                : ProductAttributeValue::updateOrCreate(['product_id' => $product->id, 'attribute_id' => $attr->id], ['value' => $value]);
            return;
        }
        if (preg_match('/^variant\.(\d+)\.(\w+)$/', $field, $m)) {
            ProductVariant::where('product_id', $product->id)->findOrFail($m[1])->update([$m[2] => $value]);
        }
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    /** Human-readable value for the admin UI (names instead of ids). */
    public function display(string $field, $value): string
    {
        if ($value === null || $value === '' || $value === []) return '—';
        if ($field === 'category_id')    return Category::find($value)?->name ?? "#$value";
        if ($field === 'subcategory_id') return Subcategory::find($value)?->name ?? "#$value";
        if ($field === 'delivery_fee')   return (float) $value === 0.0 ? 'Free delivery' : number_format((float) $value, 3) . ' TND';
        if ($field === 'price' || str_ends_with($field, '.price_override')) return number_format((float) $value, 3) . ' TND';
        if ($field === 'season')         return implode(', ', (array) json_decode($value, true));
        if (is_bool($value))             return $value ? 'Yes' : 'No';
        if (str_starts_with($field, 'attribute.')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return AttributeOption::whereIn('id', array_map('intval', $decoded))->pluck('value')->implode(', ') ?: '—';
            }
            return in_array($value, ['0', '1'], true) ? ($value === '1' ? 'Yes' : 'No') : (string) $value;
        }
        if (is_array($value)) {
            return isset($value['label'])
                ? "{$value['label']} (stock {$value['stock']})"
                : count($value) . ' image' . (count($value) === 1 ? '' : 's');
        }
        return (string) $value;
    }

    private function summarize(array $items): string
    {
        $parts = [];
        $images = 0;
        foreach ($items as $i) {
            if ($i['group'] === 'images') {
                $images += max(1, count(array_diff($i['new'] ?? [], $i['old'] ?? [])) + count(array_diff($i['old'] ?? [], $i['new'] ?? [])));
                continue;
            }
            $key = match ($i['group']) {
                'text'       => strtolower(self::FIELDS[$i['field']][1] ?? $i['field']),
                'price'      => 'price',
                'stock'      => 'stock',
                'category'   => 'category',
                'attributes' => 'details',
                'variants'   => 'variants',
                default      => strtolower(self::FIELDS[$i['field']][1] ?? 'settings'),
            };
            $parts[$key] = true;
        }
        $out = array_keys($parts);
        if ($images) $out[] = $images . ' image' . ($images === 1 ? '' : 's');
        return implode(', ', $out);
    }

    private function notifyAdmins(ProductChangeSet $set, Product $product, ?User $seller): void
    {
        try {
            $admins = User::where('role', 'admin')->where('is_active', true)->get();
            if ($admins->isEmpty()) return;
            Notification::send($admins, new ProductChangedNotification(
                $set->id, $product->id, $product->getRawOriginal('name'), $seller?->name ?? 'Seller',
                $set->summary, $set->is_sensitive, $set->sensitive_reasons ?? []
            ));
            $set->update(['notified' => true]);
        } catch (\Throwable $e) {
            Log::warning('[ProductChangeTracker] notification failed: ' . $e->getMessage());
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function normalize(string $field, $v)
    {
        if ($v === null) return null;
        return match ($field) {
            'price', 'delivery_fee'          => round((float) $v, 3),
            'stock', 'category_id', 'subcategory_id' => (int) $v,
            'is_active', 'is_pack'           => (bool) $v,
            'season'                         => json_encode(array_values((array) (is_string($v) ? json_decode($v, true) : $v))),
            default                          => (string) $v === '' ? null : (string) $v,
        };
    }

    /** Values round-trip through JSON (49 vs 49.0), so compare numbers numerically. */
    private function same($a, $b): bool
    {
        if (is_numeric($a) && is_numeric($b) && !is_bool($a) && !is_bool($b)) {
            return abs((float) $a - (float) $b) < 0.0005;
        }
        return $a === $b;
    }

    private function bigMove($old, $new): bool
    {
        $old = (float) $old;
        $new = (float) $new;
        return $old > 0 && abs($new - $old) / $old > self::SENSITIVE_PRICE_RATIO;
    }

    /** "gallery" → "Gallery", "color 5|7" → "Blanc + Rouge". */
    private function scopeLabel(string $scope): string
    {
        if (!str_starts_with($scope, 'color ')) return ucfirst($scope);
        $ids = explode('|', substr($scope, 6));
        $names = AttributeOption::whereIn('id', $ids)->pluck('value', 'id');
        return collect($ids)->map(fn($id) => $names[$id] ?? "#$id")->implode(' + ');
    }

    private function attributeName(string $slug): string
    {
        return Attribute::where('slug', $slug)->value('name') ?? $slug;
    }
}
