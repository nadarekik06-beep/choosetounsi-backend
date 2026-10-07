<?php

namespace App\Console\Commands;

use App\Models\AttributeOption;
use App\Models\OrderItem;
use App\Services\Orders\OrderItemSnapshot;
use App\Services\ProductImages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fills the purchase snapshot (image_url, variant_attributes, variant_label) of
 * order lines placed before checkout took it — see OrderItemSnapshot.
 *
 * Idempotent: only lines with image_source IS NULL are processed, and every
 * processed line gets one, so a second run does nothing.
 *
 * Old lines can only be rebuilt from today's catalog:
 *   variant still there  → its color image ("variant")
 *   variant gone         → color matched from variant_label ("label"), else a
 *                          color-less product image ("fallback"), else none
 * Lines that aren't exact (label / fallback / none) are listed and logged.
 */
class SnapshotOrderItems extends Command
{
    protected $signature   = 'orders:snapshot-items {--dry-run : Report what would change without writing}';
    protected $description = 'Backfill the purchase snapshot (variant image, attributes, label) of existing order lines';

    public function handle(): int
    {
        $dry    = (bool) $this->option('dry-run');
        $counts = array_fill_keys([
            OrderItemSnapshot::SRC_VARIANT, OrderItemSnapshot::SRC_PRODUCT, OrderItemSnapshot::SRC_LABEL,
            OrderItemSnapshot::SRC_FALLBACK, OrderItemSnapshot::SRC_NONE,
        ], 0);
        $inexact = [];

        $total = OrderItem::whereNull('image_source')->count();
        $this->info("Order lines to snapshot: {$total}" . ($dry ? ' (dry run)' : ''));

        OrderItem::whereNull('image_source')
            ->with(['product.images', 'product.variants.attributeOptions.attribute', 'variant.attributeOptions.attribute'])
            ->chunkById(200, function ($items) use ($dry, &$counts, &$inexact) {
                foreach ($items as $item) {
                    $fill = $this->snapshotFor($item);
                    $counts[$fill['image_source']]++;
                    if (!in_array($fill['image_source'], [OrderItemSnapshot::SRC_VARIANT, OrderItemSnapshot::SRC_PRODUCT], true)) {
                        $inexact[] = "#{$item->id} (order {$item->order_id}, product {$item->product_id}): {$fill['image_source']}";
                    }
                    if (!$dry) $item->forceFill($fill)->saveQuietly();
                }
                ProductImages::flush();
                OrderItemSnapshot::flush();
            });

        $this->table(['image_source', 'lines'], collect($counts)->map(fn($n, $k) => [$k, $n])->values());

        if ($inexact) {
            $this->warn(count($inexact) . ' line(s) could not be matched to their exact variant image:');
            foreach (array_slice($inexact, 0, 50) as $line) $this->line("  {$line}");
            if (count($inexact) > 50) $this->line('  … and ' . (count($inexact) - 50) . ' more (full list in the log)');
            Log::warning('[orders:snapshot-items] lines without an exact variant image', ['lines' => $inexact, 'dry_run' => $dry]);
        }

        Log::info('[orders:snapshot-items] done', $counts + ['dry_run' => $dry]);
        return self::SUCCESS;
    }

    /** Snapshot columns for one old line; keeps whatever it already has. */
    private function snapshotFor(OrderItem $item): array
    {
        $product    = $item->product;
        $variant    = $item->variant_id ? $item->variant : null;
        $label      = $item->getAttributes()['variant_label'] ?? null;
        $attributes = $item->variant_attributes ?: ($variant ? OrderItemSnapshot::attributesOf($variant) : null);
        $label    ??= OrderItemSnapshot::labelOf($attributes);

        $fill = ['variant_attributes' => $attributes ?: null, 'variant_label' => $label];

        if (!empty($item->getAttributes()['image_url'])) {
            return $fill + ['image_source' => OrderItemSnapshot::SRC_VARIANT];
        }

        if ($variant) {
            [$path, $source] = OrderItemSnapshot::pick($product, ProductImages::colorIdsOf($variant), true);
        } elseif ($attributes) {
            [$path, $source] = OrderItemSnapshot::pick($product, OrderItemSnapshot::colorIdsIn($attributes), true);
        } elseif ($label) {
            // Variant deleted: recover its color from the label text ("Rouge / M")
            $colorIds = $this->colorIdsFromLabel($item, $label);
            [$path, $source] = OrderItemSnapshot::pick($product, $colorIds ?: null, true);
            if ($colorIds && $source === OrderItemSnapshot::SRC_VARIANT) $source = OrderItemSnapshot::SRC_LABEL;
        } else {
            [$path, $source] = OrderItemSnapshot::pick($product, [], false);
        }

        $url = OrderItemSnapshot::freeze($path);
        return $fill + ['image_url' => $url, 'image_source' => $url ? $source : OrderItemSnapshot::SRC_NONE];
    }

    /** Color options of the product whose text appears in the label, matched whole and case-insensitively. */
    private function colorIdsFromLabel(OrderItem $item, string $label): array
    {
        if (!$item->product) return [];
        $parts = collect(explode('/', $label))->map(fn($p) => mb_strtolower(trim($p)))->filter()->all();

        // Colors that still have photos, even when no variant uses them anymore
        $ids = collect(ProductImages::cachedSets($item->product)['color_groups'])
            ->flatMap(fn($g) => $g['color_option_ids'])
            ->merge($item->product->variants->flatMap(fn($v) => ProductImages::colorIdsOf($v)))
            ->unique()->values();
        if ($ids->isEmpty()) return [];

        return AttributeOption::whereIn('id', $ids)->get()
            ->filter(fn($o) => collect([$o->getAttributes()['value'] ?? null, $o->value_fr, $o->value_ar])
                ->filter()->contains(fn($v) => in_array(mb_strtolower(trim($v)), $parts, true)))
            ->pluck('id')->map(fn($i) => (int) $i)->sort()->values()->all();
    }
}
