<?php

namespace App\Services;

use App\Models\Product;
use App\Services\Chat\GroqClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Translates a seller's product name / short description / description into fr, ar and en
 * with Groq (one call per product) and caches the result in products.translations.
 *
 * Translation happens only when the text changes (tracked by translations_hash) and only
 * for live products. Requests never call Groq: they read the cached column.
 */
class ProductTranslator
{
    public const LOCALES = ['fr', 'ar', 'en'];
    private const FIELDS = ['name', 'short_description', 'description'];
    private const MAX_DESCRIPTION = 3500; // longer descriptions are not sent to the model

    public function __construct(private GroqClient $groq) {}

    public static function sourceHash(Product $product): string
    {
        $raw = $product->getAttributes();

        return md5(implode("\x1f", array_map(fn ($f) => (string) ($raw[$f] ?? ''), self::FIELDS)));
    }

    public static function needsTranslation(Product $product): bool
    {
        $raw = $product->getAttributes();

        return !empty($raw['is_approved']) && !empty($raw['is_active'])
            && ($raw['translations_hash'] ?? null) !== self::sourceHash($product);
    }

    public function translateById(int $id, bool $force = false): bool
    {
        $product = Product::withoutGlobalScopes()->find($id);

        return $product ? $this->translate($product, $force) : false;
    }

    /** @return bool true when fresh translations were stored */
    public function translate(Product $product, bool $force = false): bool
    {
        if (!$force && !self::needsTranslation($product)) {
            return false;
        }
        if (!$this->groq->isConfigured()) {
            return false;
        }

        $raw    = $product->getAttributes();
        $source = [
            'name'              => (string) ($raw['name'] ?? ''),
            'short_description' => (string) ($raw['short_description'] ?? ''),
            // Very long descriptions are left untranslated rather than cut (the original is shown).
            'description'       => mb_strlen((string) ($raw['description'] ?? '')) <= self::MAX_DESCRIPTION
                ? (string) ($raw['description'] ?? '') : '',
        ];

        $result = $this->groq->chatJson([
            ['role' => 'system', 'content' => $this->prompt()],
            ['role' => 'user', 'content' => json_encode($source, JSON_UNESCAPED_UNICODE)],
        ], 'product_translation', 4000);

        $translations = $this->clean($result, $source);
        if (!$translations) {
            Log::warning('[ProductTranslator] No usable translation', ['product_id' => $product->id, 'error' => $this->groq->lastError ?? null]);
            return false;
        }

        // Query builder update: no model events, so this never re-triggers a translation.
        DB::table('products')->where('id', $product->id)->update([
            'translations'      => json_encode($translations, JSON_UNESCAPED_UNICODE),
            'translations_hash' => self::sourceHash($product),
            'translated_at'     => now(),
        ]);

        Log::info('[ProductTranslator] Translated', ['product_id' => $product->id]);

        return true;
    }

    private function prompt(): string
    {
        return <<<'TXT'
You translate product listings for a Tunisian e-commerce marketplace.
The user message is a JSON object with "name", "short_description" and "description", written in any language
(French, Arabic, Tunisian darija or English). Translate each field into French, Modern Standard Arabic and English.

Rules:
- Natural, fluent e-commerce copy. Keep the meaning; do not add or remove claims.
- Keep brand names, model numbers, sizes, units and numbers exactly as written (e.g. "5KG", "iPhone 13", "250 ml").
- Arabic uses Western digits (0-9).
- If a field is empty, return an empty string for it.
- When the source is already in a target language, return it for that language with only spelling fixes.

Answer with JSON only:
{"fr":{"name":"","short_description":"","description":""},"ar":{"name":"","short_description":"","description":""},"en":{"name":"","short_description":"","description":""}}
TXT;
    }

    private function clean(?array $result, array $source): ?array
    {
        if (!$result) {
            return null;
        }

        $out = [];
        foreach (self::LOCALES as $locale) {
            $row = $result[$locale] ?? null;
            if (!is_array($row) || trim((string) ($row['name'] ?? '')) === '') {
                return null; // incomplete answer: keep the old cache, retry next time
            }
            foreach (self::FIELDS as $field) {
                $value = trim((string) ($row[$field] ?? ''));
                $out[$locale][$field] = $source[$field] === '' ? '' : mb_substr($value, 0, $field === 'name' ? 255 : 10000);
            }
        }

        return $out;
    }
}
