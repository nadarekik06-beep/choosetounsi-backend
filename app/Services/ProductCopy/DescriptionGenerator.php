<?php

namespace App\Services\ProductCopy;

use App\Models\Product;
use App\Models\SellerAiProfile;
use App\Models\SellerApplication;
use App\Models\User;
use App\Services\Chat\GroqClient;
use App\Services\PromotionService;
use App\Support\Occasions;
use Illuminate\Support\Facades\Log;

/**
 * AI product descriptions: product brief → Groq (one JSON call for every variant)
 * → cleaned, validated variants.
 *
 * Free-tier friendly: one request per generation, a fallback model when the main
 * one is rate limited or down, and nothing counted against the seller when the
 * call fails. Never returns template text dressed up as AI output.
 */
class DescriptionGenerator
{
    public function __construct(
        private GroqClient $groq,
        private DescriptionPrompt $prompt,
        private DescriptionCleaner $cleaner,
        private PromotionService $promotions,
    ) {}

    /**
     * Product facts from the form (fresh, may be unsaved) completed by the database
     * when the product exists: promotion, pack, occasions, stock, store name.
     *
     * @return array|null null when product_id is given but is not this seller's product
     */
    public function brief(User $seller, array $input, bool $withBrandVoice): ?array
    {
        $product = null;
        if (!empty($input['product_id'])) {
            $product = Product::with(['category:id,name', 'subcategory:id,name', 'occasionRows'])
                ->where('seller_id', $seller->id)
                ->find((int) $input['product_id']);
            if (!$product) return null;
        }

        $price = isset($input['price']) && $input['price'] !== '' && (float) $input['price'] > 0
            ? (float) $input['price']
            : ($product ? (float) $product->price : null);

        $occasions = array_values(array_intersect(Occasions::keys(), (array) ($input['occasions'] ?? [])));
        if (!$occasions && $product) $occasions = $product->occasions;

        $isPack = array_key_exists('is_pack', $input) ? (bool) $input['is_pack'] : (bool) $product?->is_pack;
        $pack   = $isPack ? [
            'quantity' => (int) ($input['pack_quantity'] ?? $product?->pack_quantity ?? 0) ?: null,
            'contents' => $this->str($input['pack_contents'] ?? $product?->pack_contents, 300),
        ] : null;

        $brief = [
            'name'        => $this->str($input['name'] ?? $product?->name, 200),
            'store'       => $this->str(SellerApplication::where('user_id', $seller->id)->where('status', 'approved')->value('business_name'), 120),
            'category'    => $this->str($input['category'] ?? $product?->category?->name, 120),
            'subcategory' => $this->str($input['subcategory'] ?? $product?->subcategory?->name, 120),
            'price'       => $price,
            'promo'       => $product && $price ? $this->promo($product, $price) : null,
            'attributes'  => $this->attributes($input['attributes'] ?? []),
            'variants'    => $this->list($input['variants'] ?? [], 30, 80),
            'option_groups' => $this->groups($input['option_groups'] ?? []),
            'pack'        => $pack,
            'stock'       => $product ? (int) $product->stock : null,
            'occasions'   => $occasions,
            'notes'       => $this->str($input['notes'] ?? null, 600),
            'draft'       => $this->str($input['short_description'] ?? null, 500),
            'keywords'    => $this->keywords($input['keywords'] ?? []),
            'brand_voice' => null,
        ];

        if ($withBrandVoice) {
            $profile = SellerAiProfile::where('user_id', $seller->id)->first();
            if ($profile && ($profile->brand_voice || $profile->brand_keywords)) {
                $brief['brand_voice'] = [
                    'voice'    => $this->str($profile->brand_voice, 600),
                    'keywords' => $this->keywords($profile->brand_keywords ?? []),
                ];
            }
        }

        return $brief;
    }

    /**
     * @param  array $options tone, length, languages[], count, extras
     * @return array{ok: true, variants: array, model: string, requested: int}
     *       | array{ok: false, error: string, retry_after: ?int}
     */
    public function generate(array $brief, array $options): array
    {
        $prompt   = $this->prompt->build($brief, $options);
        $messages = [
            ['role' => 'system', 'content' => $prompt['system']],
            ['role' => 'user',   'content' => $prompt['user']],
        ];
        $maxTokens = $this->maxTokens($options);
        $base      = [
            'temperature' => $options['count'] > 1 ? 0.85 : 0.75,
            'schema'      => $prompt['schema'],
            'reasoning_effort' => config('ai_descriptions.reasoning_effort'),
            'schema_name' => 'product_descriptions',
            'top_p'       => 0.95,
            'timeout'     => config('ai_descriptions.timeout'),
            'reserve'     => config('ai_descriptions.reserve_calls'),
        ];

        $models  = array_values(array_unique(array_filter([config('ai_descriptions.model'), config('ai_descriptions.fallback_model')])));
        $lastErr = 'unavailable';
        $retry   = null;

        foreach ($models as $i => $model) {
            $data = $this->groq->chatJson($messages, 'product_description', $maxTokens, $base + ['model' => $model]);

            if ($data !== null) {
                $data     = $this->repair($this->cleaner->prepare($data), $prompt, $model);
                $variants = $this->cleaner->clean($data, $prompt['plan'], $prompt['facts'], (bool) $options['extras']);
                if ($this->cleaner->dropped) {
                    Log::info('[ProductCopy] Variants dropped', [
                        'model'   => $model,
                        'reasons' => $this->cleaner->dropped,
                        'raw'     => mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 2500),
                    ]);
                }
                if ($variants) {
                    return ['ok' => true, 'variants' => $variants, 'model' => $model, 'requested' => $options['count']];
                }
                Log::warning('[ProductCopy] Model output rejected by the cleaner', ['model' => $model, 'raw' => mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 600)]);
                $lastErr = 'bad_output';   // try the fallback model once
                continue;
            }

            $err = $this->groq->lastError ?? 'unavailable';
            if (in_array($err, ['budget', 'not_configured'], true)) {
                return ['ok' => false, 'error' => $err === 'budget' ? 'busy_today' : 'unavailable', 'retry_after' => null];
            }
            if ($err === 'rate_limited') {
                $lastErr = 'rate_limited';
                $retry   = $this->groq->retryAfter;
            } elseif (in_array($err, ['bad_json', 'empty', 'http_400'], true)) {   // 400 = json_validate_failed
                $lastErr = 'bad_output';
            } elseif ($lastErr !== 'rate_limited') {
                $lastErr = 'unavailable';
            }
        }

        return ['ok' => false, 'error' => $lastErr, 'retry_after' => $retry];
    }

    /**
     * Rewrite only the sentences that use a banned phrase or an unverified spec, with a
     * small call on the fallback model (its own free quota). Whatever is still wrong
     * afterwards is removed by the cleaner.
     */
    private function repair(array $prepared, array $prompt, string $usedModel): array
    {
        $issues = $this->cleaner->issues($prepared, $prompt['plan'], $prompt['facts']);
        if (!$issues) return $prepared;
        $issues = array_slice($issues, 0, 12);

        $items = [];
        foreach ($issues as $i => $issue) {
            $items[] = ['id' => $i + 1, 'language' => $issue['language'], 'text' => $issue['text'], 'problem' => $issue['problem']];
        }
        $banned = [];
        foreach (array_unique(array_column($issues, 'language')) as $lang) {
            $banned = array_merge($banned, DescriptionPrompt::BANNED[$lang] ?? []);
        }

        $system = 'You fix sentences from product descriptions. Rewrite each sentence in the same language and tone so the problem disappears, '
            . 'keeping its meaning, its grammar with the surrounding text, and about the same length. '
            . 'If the problem is a number or claim that is not in PRODUCT DATA, remove that claim instead of replacing it with another one. '
            . 'Never add facts that are not in PRODUCT DATA. Plain text, no markdown. '
            . 'Never use these phrases: "' . implode('", "', array_unique(array_merge($banned, DescriptionPrompt::BANNED['en']))) . '". '
            . 'Reply with JSON only: {"fixes":[{"id":1,"text":"..."}]}';
        $user = "PRODUCT DATA\n{$prompt['facts']}\n\nSENTENCES TO FIX\n" . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $model = config('ai_descriptions.fallback_model') ?: $usedModel;
        $data  = $this->groq->chatJson(
            [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            'product_description_repair',
            400 + 90 * count($items),
            ['model' => $model, 'temperature' => 0.3, 'timeout' => 25]
        );
        if (!is_array($data['fixes'] ?? null)) return $prepared;

        $ctx   = $this->cleaner->context($prompt['facts']);
        $fixes = [];
        foreach ($data['fixes'] as $fix) {
            $id    = (int) ($fix['id'] ?? 0);
            $text  = $this->cleaner->text($fix['text'] ?? '', keepHashtags: true);
            $issue = $issues[$id - 1] ?? null;
            // A fix that still breaks a rule is ignored; the cleaner then removes the sentence
            if ($issue && $text !== '' && !$this->cleaner->problem($text, $issue['language'], $ctx)) {
                $fixes[$issue['text']] = $text;
            }
        }
        Log::info('[ProductCopy] Repair pass', ['issues' => count($issues), 'fixed' => count($fixes)]);
        return $this->cleaner->applyFixes($prepared, $fixes);
    }

    /** Reasoning budget + room for each variant; Arabic needs ~1.6× the tokens. */
    private function maxTokens(array $options): int
    {
        $perVariant = ['short' => 260, 'medium' => 400, 'long' => 580][$options['length']];
        $total      = config('ai_descriptions.reasoning_effort') === 'low' ? 500 : 1300;
        for ($i = 0; $i < $options['count']; $i++) {
            $lang   = $options['languages'][$i] ?? $options['languages'][0];
            $tokens = $perVariant + ($options['extras'] ? 140 : 0);
            $total += (int) round($tokens * ($lang === 'ar' ? 1.6 : 1.0));
        }
        return min($total, 5000);
    }

    private function promo(Product $product, float $price): ?array
    {
        try {
            $promotion = $this->promotions->getActivePromotionForProduct($product->id);
            if (!$promotion) return null;
            $pricing = $this->promotions->priceWith($product, $price, null, $promotion);
        } catch (\Throwable $e) {
            Log::warning('[ProductCopy] Promotion lookup failed: ' . $e->getMessage());
            return null;
        }
        if (($pricing['discount_percent'] ?? 0) <= 0) return null;

        return [
            'percent' => (int) $pricing['discount_percent'],
            'final'   => (float) $pricing['final_price'],
            'ends_at' => $promotion->ends_at?->timezone(config('ai_descriptions.timezone'))->format('j F Y'),
            'flash'   => $promotion->type === 'flash_sale',
        ];
    }

    // ── Input hygiene ─────────────────────────────────────────────────────

    private function str(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) return null;
        $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private function attributes(mixed $attributes): array
    {
        $out = [];
        foreach ((array) $attributes as $label => $value) {
            if (is_array($value)) $value = implode(', ', array_filter($value, 'is_scalar'));
            $label = $this->str(ucfirst(str_replace('_', ' ', (string) $label)), 60);
            $value = $this->str($value, 160);
            if ($label && $value !== null) $out[$label] = $value;
            if (count($out) >= 25) break;
        }
        return $out;
    }

    private function list(mixed $items, int $count, int $max): array
    {
        $out = [];
        foreach ((array) $items as $item) {
            $s = $this->str($item, $max);
            if ($s !== null && !in_array($s, $out, true)) $out[] = $s;
            if (count($out) >= $count) break;
        }
        return $out;
    }

    /** {"Size": ["S", "M"], "Colour": ["Red"]} — options grouped by type, as the seller defined them. */
    private function groups(mixed $groups): array
    {
        $out = [];
        foreach ((array) $groups as $label => $values) {
            $label  = $this->str($label, 60);
            $values = $this->list(is_array($values) ? $values : [$values], 30, 60);
            if ($label && $values) $out[$label] = $values;
            if (count($out) >= 6) break;
        }
        return $out;
    }

    public function keywords(mixed $keywords): array
    {
        if (is_string($keywords)) $keywords = preg_split('/[,;\n،]+/u', $keywords);
        return $this->list($keywords ?? [], 8, 40);
    }
}
