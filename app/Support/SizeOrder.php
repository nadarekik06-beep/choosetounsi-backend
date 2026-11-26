<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Logical display order of size values: letter sizes (XXS → 5XL), then numbers
 * (ages in months before years), then unknown values in their original order,
 * then "Taille unique".
 *
 * DISPLAY ONLY: every method returns a sorted COPY and never touches the given
 * list, the models or the database. Colors and other attributes keep their order.
 *
 * Mirrored by lib/sizes.ts in the storefront and the admin panel.
 */
final class SizeOrder
{
    private const LETTERS = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL', '5XL'];

    private const ALIASES = ['2XS' => 'XXS', '2XL' => 'XXL', 'XXXL' => '3XL', 'XXXXL' => '4XL', 'XXXXXL' => '5XL'];

    /** Compared without spaces, upper case */
    private const ONE_SIZE = ['TAILLEUNIQUE', 'TU', 'ONESIZE', 'UNIQUE', 'مقاسواحد', 'مقاسموحد'];

    private const SLUGS = ['size', 'shoe-size', 'taille', 'tailles', 'pointure'];

    private const NAMES = ['size', 'sizes', 'taille', 'tailles', 'pointure', 'shoe size', 'مقاس', 'المقاس', 'قياس', 'القياس', 'مقاس الحذاء'];

    private const MONTHS = '(?:mois|months?|أشهر|اشهر|شهور|شهر)';

    private const YEARS = '(?:ans?|years?|yrs?|سنوات|سنين|سنة|أعوام|عام)';

    private const GROUP_LETTER  = 0;
    private const GROUP_NUMBER  = 1;
    private const GROUP_UNKNOWN = 2;
    private const GROUP_ONE     = 3;

    /** Is this the size attribute? "Screen size" and other attributes are not. */
    public static function isSizeAttribute(?string $slug, ?string $name = null): bool
    {
        $slug = mb_strtolower(trim((string) $slug));
        if (in_array($slug, self::SLUGS, true)) return true;
        // Custom attributes such as "taille-pantalon" (not "taille-ecran")
        if (preg_match('/^(taille|pointure)-/', $slug) && !preg_match('/[eé]cran/u', $slug)) return true;

        $name = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $name)));
        return in_array($name, self::NAMES, true);
    }

    /** @return array{0: int, 1: float} [group, position inside the group] */
    public static function rank($value): array
    {
        $text = trim((string) $value);
        $key  = mb_strtoupper((string) preg_replace('/[\s\x{00A0}]+/u', '', $text));

        if (in_array($key, self::ONE_SIZE, true)) return [self::GROUP_ONE, 0.0];

        $letter = array_search(self::ALIASES[$key] ?? $key, self::LETTERS, true);
        if ($letter !== false) return [self::GROUP_LETTER, (float) $letter];

        $number = '(\d+(?:[.,]\d+)?)';
        $unit   = '(' . self::MONTHS . '|' . self::YEARS . ')?';
        // "38", "38,5", "EU 38", "38-40", "2 ans", "3-6 mois"
        $pattern = "/^(?:(?:eu|fr)\s*)?{$number}\s*(?:[-–\/]\s*\d+(?:[.,]\d+)?\s*)?{$unit}$/iu";
        if (preg_match($pattern, $text, $m)) {
            $n = (float) str_replace(',', '.', $m[1]);
            // Ages compare in years, so "6 mois" comes before "2 ans"
            if (!empty($m[2]) && preg_match('/^' . self::MONTHS . '$/iu', $m[2])) $n /= 12;
            return [self::GROUP_NUMBER, $n];
        }

        return [self::GROUP_UNKNOWN, 0.0];
    }

    public static function compare($a, $b): int
    {
        return self::rank($a) <=> self::rank($b);
    }

    /**
     * Stable sort of any list by its size value.
     *
     * @return array a new, re-indexed array
     */
    public static function sort(iterable $items, ?callable $valueOf = null): array
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [self::rank($valueOf ? $valueOf($item) : $item), count($rows), $item];
        }
        usort($rows, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($rows, 2);
    }

    /**
     * Options of one attribute: sorted copy when it is the size attribute,
     * the collection as it is for every other attribute.
     *
     * @param object|null $attribute anything with slug / name (Attribute model)
     */
    public static function options($attribute, Collection $options): Collection
    {
        if (!$attribute || !self::isSizeAttribute($attribute->slug, $attribute->name)) {
            return $options;
        }
        $class = get_class($options);

        return new $class(self::sort($options, fn ($o) => $o->value));
    }

    /**
     * Variants (with attributeOptions.attribute loaded): sizes sorted inside each
     * combination of the other options, so colors keep the order they first appear
     * in. Without any size the order is unchanged.
     */
    public static function variants(Collection $variants): Collection
    {
        $rows   = [];
        $groups = [];
        $sized  = false;

        foreach ($variants as $variant) {
            $sizes  = [];
            $others = [];
            foreach ($variant->attributeOptions as $option) {
                $attribute = $option->attribute;
                if ($attribute && self::isSizeAttribute($attribute->slug, $attribute->name)) {
                    $sizes[] = self::rank($option->value);
                    $sized   = true;
                } else {
                    $others[] = (int) $option->id;
                }
            }
            sort($others);
            $group = implode('-', $others);
            $groups[$group] ??= count($groups);
            // -count: a variant without a size goes after the sized ones
            $rows[] = [$groups[$group], -count($sizes), $sizes, count($rows), $variant];
        }

        if (!$sized) return $variants;

        usort($rows, fn (array $a, array $b) => array_slice($a, 0, 4) <=> array_slice($b, 0, 4));
        $class = get_class($variants);

        return new $class(array_column($rows, 4));
    }
}
