<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * URL slug and SKU of a seller product, always made by the server.
 *
 * - Slug: from the name (Arabic is transliterated by Str::slug). A name that gives
 *   nothing usable (only symbols / emoji) falls back to "produit-xxxxxx". Unique across
 *   every product row, soft-deleted ones included (the unique index covers them).
 * - SKU: "CT-" + the product id on 6 digits (CT-000184), unique across products and variants.
 */
class ProductIdentifiers
{
    private const SLUG_MAX = 180;

    public static function uniqueSlug(?string $name, ?int $excludeId = null): string
    {
        $base = Str::limit(Str::slug((string) $name), self::SLUG_MAX, '');
        $base = trim($base, '-');
        if ($base === '') $base = 'produit-' . self::randomToken();

        $slug = $base;
        for ($n = 2; self::slugTaken($slug, $excludeId); $n++) {
            // Very common names: stop counting and add a random token instead
            $slug = $n <= 50 ? "{$base}-{$n}" : "{$base}-" . self::randomToken();
        }
        return $slug;
    }

    public static function skuFor(int $productId): string
    {
        $base = 'CT-' . str_pad((string) $productId, 6, '0', STR_PAD_LEFT);
        $sku  = $base;
        for ($n = 2; self::skuTaken($sku, $productId); $n++) {
            $sku = "{$base}-{$n}";
        }
        return $sku;
    }

    /** Gives a freshly created product its SKU without firing model events again. */
    public static function assignSku(Product $product): void
    {
        $sku = self::skuFor($product->id);
        DB::table('products')->where('id', $product->id)->update(['sku' => $sku]);
        $product->setAttribute('sku', $sku);
        $product->syncOriginalAttribute('sku');
    }

    /**
     * Runs $create with a slug; if another request took the same slug in between
     * (unique index), retries with a new one instead of failing the product creation.
     */
    public static function createWithUniqueSlug(?string $name, callable $create)
    {
        $slug = self::uniqueSlug($name);
        for ($attempt = 1; ; $attempt++) {
            try {
                return $create($slug);
            } catch (QueryException $e) {
                $duplicateSlug = ($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'slug');
                if (!$duplicateSlug || $attempt >= 3) throw $e;
                $slug = self::uniqueSlug(Str::limit($slug, self::SLUG_MAX - 8, '') . '-' . self::randomToken());
            }
        }
    }

    private static function slugTaken(string $slug, ?int $excludeId): bool
    {
        return DB::table('products')->where('slug', $slug)
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->exists();
    }

    private static function skuTaken(string $sku, int $productId): bool
    {
        return DB::table('products')->where('sku', $sku)->where('id', '!=', $productId)->exists()
            || DB::table('product_variants')->where('sku', $sku)->exists();
    }

    private static function randomToken(): string
    {
        return Str::lower(Str::random(6));
    }
}
