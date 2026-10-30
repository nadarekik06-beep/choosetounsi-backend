<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Listing quality rules, defined once for every way a product is created:
 * the seller form (SellerProductController::store), the admin "Add product"
 * page and "Split into separate products" (AdminProductEditor).
 *
 *   New product    subcategory required when the category has active subcategories,
 *                  short description ≥ 20 characters, not generic, no phone number,
 *                  total stock ≥ 1, at least one photo.
 *   Edit           lenient so existing listings stay editable: the subcategory is only
 *                  required when the category changes, the short description is only
 *                  checked when it changes, and stock 0 is allowed (sold out).
 *
 * warnings() lists the same problems for admins reviewing a product (never blocking).
 */
class ProductListingRules
{
    public const SHORT_DESCRIPTION_MIN = 20;
    /** More photos than this without variants: possibly several articles in one product. */
    public const MANY_PHOTOS = 5;

    /** Phrases that say nothing about the product (lowercase, accents removed). */
    private const GENERIC_PHRASES = [
        'meilleure prix', 'meilleur prix', 'meilleurs prix', 'best price', 'bon prix', 'prix choc', 'prix bas',
        'petit prix', 'prix imbattable', 'promo', 'promotion', 'solde', 'soldes', 'offre speciale', 'top qualite',
        'bonne qualite', 'haute qualite', 'qualite', 'quality', 'good quality', 'high quality', 'nouveau', 'new',
        'disponible', 'available', 'livraison gratuite', 'free delivery', 'a saisir', 'pas cher', 'cheap',
        'اقل سعر', 'أقل سعر', 'افضل سعر', 'أفضل سعر', 'جودة عالية', 'تخفيض', 'عرض',
    ];

    // ── Checks ───────────────────────────────────────────────────────────────

    public static function categoryHasSubcategories(?int $categoryId): bool
    {
        if (!$categoryId) return false;
        return DB::table('subcategories')->where('category_id', $categoryId)->where('is_active', true)->exists();
    }

    /** A Tunisian phone number written anywhere in the text ("22 123 456", "+216 98.765.432"). */
    public static function containsPhone(?string $text): bool
    {
        $joined = preg_replace('/(?<=\d)[\s.\-]+(?=\d)/u', '', (string) $text);
        return (bool) preg_match('/(?<!\d)(?:\+?216|00216)?[2459]\d{7}(?!\d)/', $joined);
    }

    /** Nothing left once the "best price / promo" filler is removed. */
    public static function isGeneric(?string $text): bool
    {
        $s = mb_strtolower(trim((string) $text));
        if ($s === '') return false;
        $s = self::stripAccents($s);
        foreach (self::GENERIC_PHRASES as $phrase) {
            $s = str_replace(self::stripAccents($phrase), ' ', $s);
        }
        $letters = preg_replace('/[^\p{L}]+/u', '', $s);
        return mb_strlen($letters) < 8;
    }

    /** Short description problem (lang key suffix) or null. */
    public static function shortDescriptionIssue(?string $text): ?string
    {
        $t = trim((string) $text);
        if ($t === '') return 'short_description_required';
        if (self::containsPhone($t)) return 'short_description_phone';
        if (mb_strlen($t) < self::SHORT_DESCRIPTION_MIN) return 'short_description_min';
        if (self::isGeneric($t)) return 'short_description_generic';
        return null;
    }

    // ── New products ─────────────────────────────────────────────────────────

    /**
     * Field-keyed errors for a product being created.
     *
     * @param array{category_id: ?int, subcategory_id: ?int, short_description: ?string,
     *              total_stock: int, image_count: int} $input
     */
    public static function creationErrors(array $input): array
    {
        $e = [];

        if (empty($input['subcategory_id']) && self::categoryHasSubcategories($input['category_id'] ?? null)) {
            $e['subcategory_id'] = __('seller.product.rules.subcategory_required');
        }
        if ($issue = self::shortDescriptionIssue($input['short_description'] ?? null)) {
            $e['short_description'] = __("seller.product.rules.{$issue}", ['min' => self::SHORT_DESCRIPTION_MIN]);
        }
        if ((int) ($input['total_stock'] ?? 0) < 1) {
            $e['stock'] = __('seller.product.rules.stock_min');
        }
        if ((int) ($input['image_count'] ?? 0) < 1) {
            $e['images'] = __('seller.product.rules.image_required');
        }

        return $e;
    }

    /**
     * Errors for an edit of an existing product: only what the edit changes is checked,
     * so listings created before these rules keep saving.
     *
     * @param array{category_id?: ?int, subcategory_id?: ?int, short_description?: ?string} $input
     *        keys present only when the edit sends them
     */
    public static function updateErrors(Product $product, array $input): array
    {
        $e = [];

        $categoryId    = array_key_exists('category_id', $input) && $input['category_id'] ? (int) $input['category_id'] : $product->category_id;
        $subcategoryId = array_key_exists('subcategory_id', $input) ? $input['subcategory_id'] : $product->subcategory_id;
        $categoryChanged = (int) $categoryId !== (int) $product->category_id;
        if ($categoryChanged && empty($subcategoryId) && self::categoryHasSubcategories($categoryId)) {
            $e['subcategory_id'] = __('seller.product.rules.subcategory_required');
        }

        if (array_key_exists('short_description', $input)) {
            $new = trim((string) $input['short_description']);
            $old = trim((string) $product->getRawOriginal('short_description'));
            if ($new !== $old && $new !== '' && ($issue = self::shortDescriptionIssue($new))) {
                $e['short_description'] = __("seller.product.rules.{$issue}", ['min' => self::SHORT_DESCRIPTION_MIN]);
            }
        }

        return $e;
    }

    /** Laravel rules for variant stock, shared by the seller and admin payloads. */
    public static function variantStockRules(string $prefix = 'variants'): array
    {
        return ["{$prefix}.*.stock" => 'nullable|integer|min:0|max:1000000'];
    }

    public static function throwIfAny(array $errors): void
    {
        if ($errors) {
            throw ValidationException::withMessages(array_map(fn($m) => [$m], $errors));
        }
    }

    // ── Admin review ─────────────────────────────────────────────────────────

    /**
     * Quality warnings for the admin review/edit pages. Each item:
     *   code, message (admin, English), seller_note (seller's language, for "Request changes"),
     *   reason (ProductModerationLog::REASONS code).
     */
    public static function warnings(Product $product): array
    {
        $locale  = optional($product->seller)->preferredLocale() ?? config('app.locale');
        $note    = fn(string $code, array $p = []) => __("seller.product.quality.{$code}", $p, $locale);
        $images  = $product->images()->distinct()->count('image_path');
        $variants = $product->variants()->get(['id', 'stock', 'is_active']);
        $stock   = $variants->isNotEmpty()
            ? (int) $variants->where('is_active', true)->sum('stock')
            : (int) $product->stock;
        $short   = (string) $product->getRawOriginal('short_description');
        $out     = [];

        if (!$product->subcategory_id && self::categoryHasSubcategories($product->category_id)) {
            $out[] = ['code' => 'missing_subcategory', 'reason' => 'wrong_category',
                'message' => 'No subcategory: the product is missing from subcategory pages and filters, and has no variants or attributes.',
                'seller_note' => $note('missing_subcategory')];
        }
        if ($stock <= 0) {
            $out[] = ['code' => 'zero_stock', 'reason' => 'other',
                'message' => 'Stock is 0: buyers see it as out of stock and cannot order it.',
                'seller_note' => $note('zero_stock')];
        }
        if ($images > self::MANY_PHOTOS && $variants->isEmpty()) {
            $out[] = ['code' => 'many_photos_no_variants', 'reason' => 'duplicate_listing',
                'message' => "{$images} photos and no variants: this may be several different articles in one product. Consider splitting it.",
                'seller_note' => $note('many_photos_no_variants', ['count' => $images])];
        }
        if ($issue = self::shortDescriptionIssue($short)) {
            $msg = match ($issue) {
                'short_description_required' => 'No short description.',
                'short_description_phone'    => 'The short description contains a phone number.',
                'short_description_min'      => 'Short description too short (' . mb_strlen(trim($short)) . ' / ' . self::SHORT_DESCRIPTION_MIN . ' characters).',
                default                      => 'Short description is generic ("' . \Illuminate\Support\Str::limit(trim($short), 40) . '") and says nothing about the article.',
            };
            $out[] = ['code' => $issue, 'reason' => 'misleading_description', 'message' => $msg,
                'seller_note' => $note('short_description', ['min' => self::SHORT_DESCRIPTION_MIN])];
        }

        return $out;
    }

    private static function stripAccents(string $s): string
    {
        $t = class_exists(\Normalizer::class) ? \Normalizer::normalize($s, \Normalizer::FORM_D) : $s;
        return preg_replace('/\p{Mn}+/u', '', (string) $t);
    }
}
