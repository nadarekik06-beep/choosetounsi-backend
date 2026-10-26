<?php

namespace App\Services\Gender;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single source of truth for a product's browsing gender.
 *
 * Mapping (in one place): any stored value of the seller's "gender" attribute —
 * option ID ("2", ["2"], [2], double-encoded ["[\"2\"]"]) or text in FR/EN/AR
 * ("Women", "Femme", "نساء"…) — becomes one of:
 *
 *   women | men | unisex | kids            (null = no gender)
 *
 * Option IDs are never hardcoded: they are mapped through attribute_options' own
 * text (value / value_fr / value_ar), so another database with other IDs works too.
 *
 * Priority for products.gender (products.gender_source says which one won):
 *   1. admin    products.gender_override
 *   2. seller   the seller's "gender" attribute value
 *   3. inherited the subcategory's admin-linked genders (one → that one, both → unisex)
 *   4. null     shows only when no gender is selected
 *
 * Browsing: Femme shows women + unisex, Homme shows men + unisex; kids shows in neither.
 */
class GenderResolver
{
    public const WOMEN  = 'women';
    public const MEN    = 'men';
    public const UNISEX = 'unisex';
    public const KIDS   = 'kids';

    /** Genders the storefront can browse by (?gender=). */
    public const BROWSABLE = [self::WOMEN, self::MEN];

    /** Menu columns of a non-gendered category (gender_menu_groups.gender); never a product gender. */
    public const MENU_ALL = 'all';

    /** Every value products.gender may hold. */
    public const ALL = [self::WOMEN, self::MEN, self::UNISEX, self::KIDS];

    public const SOURCE_SELLER    = 'seller';
    public const SOURCE_INHERITED = 'inherited';
    public const SOURCE_ADMIN     = 'admin';

    /** Lower-cased, accent-free words → canonical gender. Arabic is matched as written. */
    private const SYNONYMS = [
        self::WOMEN => [
            'women', 'woman', 'womens', 'female', 'femme', 'femmes', 'feminin', 'feminine',
            'dame', 'dames', 'lady', 'ladies',
            'نساء', 'نسائي', 'نسائية', 'امرأة', 'المرأة', 'سيدات', 'حريمي',
        ],
        self::MEN => [
            'men', 'man', 'mens', 'male', 'homme', 'hommes', 'masculin', 'masculine',
            'رجال', 'رجالي', 'رجالية', 'الرجل',
        ],
        self::UNISEX => [
            'unisex', 'unisexe', 'mixte', 'both',
            'للجنسين', 'مختلط', 'يونيسكس',
        ],
        self::KIDS => [
            'kids', 'kid', 'child', 'children', 'enfant', 'enfants',
            'boys', 'boy', 'garcon', 'garcons', 'girls', 'girl', 'fille', 'filles',
            'bebe', 'baby', 'junior',
            'أطفال', 'اطفال', 'أولاد', 'اولاد', 'بنات', 'طفل',
        ],
    ];

    private ?int $attributeId = null;
    private bool $attributeLoaded = false;

    /** @var array<int,string|null>|null option id → canonical gender */
    private ?array $optionMap = null;

    // ── Mapping ─────────────────────────────────────────────────────────────

    /** Free text ("Femme", "Women", "نساء", "Unisexe"…) → canonical gender or null. */
    public function fromText(?string $text): ?string
    {
        $t = trim(mb_strtolower((string) $text));
        if ($t === '') return null;

        $ascii = trim(Str::ascii($t));
        foreach (self::SYNONYMS as $gender => $words) {
            if (in_array($t, $words, true) || ($ascii !== '' && in_array($ascii, $words, true))) {
                return $gender;
            }
        }
        return null;
    }

    /**
     * A raw product_attribute_values.value for the gender attribute → canonical gender or null.
     * Accepts every format seen so far: 2, "2", ["2"], [2], ["[\"2\"]"], "Women", ["Femme"], [0].
     */
    public function fromStoredValue(mixed $raw): ?string
    {
        $found = [];
        foreach ($this->scalars($raw) as $scalar) {
            $g = ctype_digit($scalar)
                ? ($this->optionMap()[(int) $scalar] ?? null)
                : $this->fromText($scalar);
            if ($g) $found[$g] = true;
        }

        return $this->combine(array_keys($found));
    }

    /** Several genders → one: unisex if both women and men (or unisex) are present. */
    public function combine(array $genders): ?string
    {
        $set = array_flip(array_filter($genders));
        if (isset($set[self::UNISEX]) || (isset($set[self::WOMEN]) && isset($set[self::MEN]))) return self::UNISEX;
        foreach ([self::WOMEN, self::MEN, self::KIDS] as $g) {
            if (isset($set[$g])) return $g;
        }
        return null;
    }

    /** Values of products.gender that show under a browsed gender (the unisex rule). */
    public function matchingValues(string $browsed): array
    {
        return [$browsed, self::UNISEX];
    }

    public function isBrowsable(?string $gender): bool
    {
        return in_array($gender, self::BROWSABLE, true);
    }

    // ── Resolution ──────────────────────────────────────────────────────────

    /** The seller's gender attribute id (null when the attribute does not exist). */
    public function attributeId(): ?int
    {
        if (!$this->attributeLoaded) {
            $this->attributeId     = DB::table('attributes')->where('slug', 'gender')->value('id');
            $this->attributeLoaded = true;
        }
        return $this->attributeId;
    }

    /** Genders a subcategory inherits from the admin gender menu (one → it, both → unisex). */
    public function inheritedForSubcategory(?int $subcategoryId): ?string
    {
        if (!$subcategoryId) return null;

        $genders = DB::table('gender_menu_items as i')
            ->join('gender_menu_groups as g', 'g.id', '=', 'i.group_id')
            ->where('i.subcategory_id', $subcategoryId)
            ->whereIn('g.gender', self::BROWSABLE)
            ->distinct()
            ->pluck('g.gender')
            ->all();

        return $this->combine($genders);
    }

    /** subcategory_id → inherited gender, for every linked subcategory (batch use). */
    public function inheritedMap(): array
    {
        return DB::table('gender_menu_items as i')
            ->join('gender_menu_groups as g', 'g.id', '=', 'i.group_id')
            ->whereIn('g.gender', self::BROWSABLE)
            ->select('i.subcategory_id', 'g.gender')
            ->distinct()
            ->get()
            ->groupBy('subcategory_id')
            ->map(fn ($rows) => $this->combine($rows->pluck('gender')->all()))
            ->all();
    }

    /**
     * [gender, source] for one product row (needs id, subcategory_id, gender_override).
     * $sellerRaw / $inherited may be passed in by batch callers to skip the lookups.
     */
    public function resolve(object $product, mixed $sellerRaw = false, ?array $inherited = null): array
    {
        $override = $product->gender_override ?? null;
        if (in_array($override, self::ALL, true)) {
            return [$override, self::SOURCE_ADMIN];
        }

        if ($sellerRaw === false) {
            $attrId    = $this->attributeId();
            $sellerRaw = $attrId
                ? DB::table('product_attribute_values')->where('product_id', $product->id)->where('attribute_id', $attrId)->value('value')
                : null;
        }
        if ($g = $this->fromStoredValue($sellerRaw)) {
            return [$g, self::SOURCE_SELLER];
        }

        $sub = $product->subcategory_id ?? null;
        $g   = $inherited !== null ? ($sub ? ($inherited[$sub] ?? null) : null) : $this->inheritedForSubcategory($sub);
        if ($g) {
            return [$g, self::SOURCE_INHERITED];
        }

        return [null, null];
    }

    /**
     * Recompute and store one product's gender. Written with the query builder so it fires
     * no model events and leaves updated_at alone. Returns true when something changed.
     */
    public function sync(int $productId): bool
    {
        $p = DB::table('products')->where('id', $productId)
            ->first(['id', 'subcategory_id', 'gender', 'gender_source', 'gender_override']);
        if (!$p) return false;

        [$gender, $source] = $this->resolve($p);
        if ($p->gender === $gender && $p->gender_source === $source) return false;

        DB::table('products')->where('id', $productId)->update(['gender' => $gender, 'gender_source' => $source]);
        return true;
    }

    /** Recompute every product of these subcategories (after the admin changed gender links). */
    public function syncSubcategories(array $subcategoryIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $subcategoryIds))));
        if (!$ids) return 0;

        $changed = 0;
        DB::table('products')->whereIn('subcategory_id', $ids)->orderBy('id')
            ->select('id')->chunk(500, function ($rows) use (&$changed) {
                foreach ($rows as $r) $changed += $this->sync((int) $r->id) ? 1 : 0;
            });
        return $changed;
    }

    /** Forget cached lookups (tests, long-running workers). */
    public function flush(): void
    {
        $this->attributeLoaded = false;
        $this->attributeId     = null;
        $this->optionMap       = null;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /** @return array<int,string|null> option id → canonical gender, from the option's own text */
    private function optionMap(): array
    {
        if ($this->optionMap !== null) return $this->optionMap;

        $attrId = $this->attributeId();
        if (!$attrId) return $this->optionMap = [];

        $this->optionMap = [];
        foreach (DB::table('attribute_options')->where('attribute_id', $attrId)->get(['id', 'value', 'value_fr', 'value_ar']) as $o) {
            $this->optionMap[(int) $o->id] = $this->fromText($o->value)
                ?? $this->fromText($o->value_fr ?? null)
                ?? $this->fromText($o->value_ar ?? null);
        }
        return $this->optionMap;
    }

    /** Flatten any stored shape (JSON, nested JSON strings, arrays, numbers) into trimmed strings. */
    private function scalars(mixed $raw, int $depth = 0): array
    {
        if ($raw === null || $depth > 4) return [];
        if (is_bool($raw)) return [];
        if (is_int($raw) || is_float($raw)) return [(string) (int) $raw];
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $v) array_push($out, ...$this->scalars($v, $depth + 1));
            return $out;
        }

        $s = trim((string) $raw);
        if ($s === '') return [];

        if ($s[0] === '[' || $s[0] === '{' || $s[0] === '"') {
            $decoded = json_decode($s, true);
            if (json_last_error() === JSON_ERROR_NONE && $decoded !== $s) {
                return $this->scalars($decoded, $depth + 1);
            }
        }
        return [$s];
    }
}
