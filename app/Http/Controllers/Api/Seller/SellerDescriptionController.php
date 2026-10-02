<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerAiProfile;
use App\Services\ProductCopy\DescriptionGenerator;
use App\Services\ProductCopy\DescriptionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI product description generator (seller dashboard, product form).
 *
 *   GET  /seller/ai/description/options   what this seller's plan allows + today's usage
 *   POST /seller/ai/description           generate 1-3 variants
 *   PUT  /seller/ai/description/voice     save the store voice (Black Pepper)
 *   POST /seller/ai/quick-description     legacy alias of POST /seller/ai/description
 *
 * Every plan can generate; DescriptionPolicy enforces tones, languages, variants,
 * extras and the daily limit here — the dashboard only displays the locks.
 */
class SellerDescriptionController extends Controller
{
    public function __construct(
        private DescriptionPolicy $policy,
        private DescriptionGenerator $generator,
    ) {}

    public function options(Request $request): JsonResponse
    {
        $ctx = $this->policy->resolve($request->user());
        if ($ctx instanceof JsonResponse) return $ctx;

        $caps     = $ctx['caps'];
        $sellerId = $request->user()->id;
        $own      = $this->policy->ownLanguage();
        $upgrade  = $this->policy->upgradePlans();
        $need     = fn(callable $allows) => $this->policy->requiredLevel($allows);

        $tones = array_map(fn($tone) => [
            'key'      => $tone,
            'locked'   => !in_array($tone, $caps['tones'], true),
            'requires' => $need(fn($c) => in_array($tone, $c['tones'], true)),
        ], config('ai_descriptions.tones'));

        $languages = array_map(fn($lang) => [
            'key'      => $lang,
            'locked'   => !$caps['any_language'] && $lang !== $own,
            'requires' => $lang === $own ? 'free' : $need(fn($c) => $c['any_language']),
        ], config('ai_descriptions.languages'));

        $voice = null;
        if ($caps['brand_voice']) {
            $profile = SellerAiProfile::where('user_id', $sellerId)->first();
            $voice   = ['voice' => $profile?->brand_voice ?? '', 'keywords' => $profile?->brand_keywords ?? []];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'level'            => $ctx['level'],
                'plan'             => ['slug' => $ctx['plan']->slug, 'name' => $ctx['plan']->name],
                'default_language' => $own,
                'tones'            => $tones,
                'languages'        => $languages,
                'lengths'          => config('ai_descriptions.lengths'),
                'max_variants'     => $caps['max_variants'],
                'multi_language'   => $caps['multi_language'],
                'extras'           => $caps['extras'],
                'brand_voice'      => $caps['brand_voice'],
                'voice'            => $voice,
                'requires'         => [
                    'variants'       => $need(fn($c) => $c['max_variants'] > 1),
                    'extras'         => $need(fn($c) => $c['extras']),
                    'multi_language' => $need(fn($c) => $c['multi_language']),
                    'brand_voice'    => $need(fn($c) => $c['brand_voice']),
                ],
                'limits'           => [
                    'free'  => config('ai_descriptions.levels.free.daily_limit'),
                    'red'   => config('ai_descriptions.levels.red.daily_limit'),
                    'black' => config('ai_descriptions.levels.black.daily_limit'),
                ],
                'upgrade'          => $upgrade,
                'usage'            => $this->policy->usage($sellerId, $caps),
            ],
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $user = $request->user();
        $ctx  = $this->policy->resolve($user);
        if ($ctx instanceof JsonResponse) return $ctx;
        $caps = $ctx['caps'];

        $request->validate([
            'product_id'        => 'nullable|integer',
            'name'              => 'required_without:product_id|nullable|string|max:255',
            'category'          => 'nullable|string|max:120',
            'subcategory'       => 'nullable|string|max:120',
            'price'             => 'nullable|numeric|min:0',
            'short_description' => 'nullable|string|max:500',
            'notes'             => 'nullable|string|max:600',
            'keywords'          => 'nullable',
            'attributes'        => 'nullable|array|max:40',
            'variants'          => 'nullable|array|max:60',
            'variants.*'        => 'nullable|string|max:120',
            'option_groups'     => 'nullable|array|max:6',
            'occasions'         => 'nullable|array',
            'occasions.*'       => 'string|max:30',
            'is_pack'           => 'nullable|boolean',
            'pack_quantity'     => 'nullable|integer|min:0|max:1000',
            'pack_contents'     => 'nullable|string|max:500',
            'image_count'       => 'nullable|integer|min:0',
            'tone'              => 'nullable|string|max:30',
            'length'            => 'nullable|in:' . implode(',', config('ai_descriptions.lengths')),
            'language'          => 'nullable|in:' . implode(',', config('ai_descriptions.languages')),
            'languages'         => 'nullable|array|min:1|max:3',
            'languages.*'       => 'distinct|in:' . implode(',', config('ai_descriptions.languages')),
            'count'             => 'nullable|integer|min:1|max:3',
            'extras'            => 'nullable|boolean',
        ]);

        // ── Options, enforced against the plan ─────────────────────────────
        $tone = (string) $request->input('tone', 'professional');
        $tone = config("ai_descriptions.legacy_tones.$tone", $tone);
        if (!in_array($tone, config('ai_descriptions.tones'), true)) {
            return $this->policy->deny(__('ai_description.invalid_tone'), 'VALIDATION', [], 422);
        }
        if (!in_array($tone, $caps['tones'], true)) {
            return $this->locked('tone', fn($c) => in_array($tone, $c['tones'], true));
        }

        $own       = $this->policy->ownLanguage();
        $languages = $request->input('languages') ?: [$request->input('language') ?: $own];
        if (!$caps['any_language'] && $languages !== [$own]) {
            return $this->locked('language', fn($c) => $c['any_language']);
        }
        if (count($languages) > 1 && !$caps['multi_language']) {
            return $this->locked('multi_language', fn($c) => $c['multi_language']);
        }

        // Several languages → one variant per language; otherwise the requested count
        $count = count($languages) > 1 ? count($languages) : (int) $request->input('count', 1);
        if ($count > $caps['max_variants']) {
            return $this->locked('variants', fn($c) => $c['max_variants'] >= $count);
        }

        $options = [
            'tone'      => $tone,
            'length'    => $request->input('length', 'medium'),
            'languages' => array_values($languages),
            'count'     => $count,
            'extras'    => $caps['extras'] && $request->boolean('extras', true),
        ];

        // ── Daily limit ────────────────────────────────────────────────────
        if (!$this->policy->hasQuota($user->id, $caps)) {
            $usage = $this->policy->usage($user->id, $caps);
            $next  = $ctx['level'] === 'black' ? null : ($ctx['level'] === 'free' ? 'red' : 'black');
            return $this->policy->deny(
                __('ai_description.daily_limit', ['limit' => $usage['limit']]),
                'AI_DAILY_LIMIT',
                ['usage' => $usage, 'required_plan' => $next ? ($this->policy->upgradePlans()[$next] ?? null) : null],
                429
            );
        }

        // ── Product brief ──────────────────────────────────────────────────
        $brief = $this->generator->brief($user, $request->all(), $caps['brand_voice']);
        if ($brief === null) {
            return $this->policy->deny(__('seller.common.product_not_found'), 'NOT_FOUND', [], 404);
        }
        if (empty($brief['name'])) {
            return $this->policy->deny(__('ai_description.name_required'), 'VALIDATION', [], 422);
        }

        // ── Generate ───────────────────────────────────────────────────────
        $result = $this->generator->generate($brief, $options);

        if (!$result['ok']) {
            return match ($result['error']) {
                'rate_limited' => $this->policy->deny(
                    __('ai_description.rate_limited', ['seconds' => $result['retry_after'] ?: 30]),
                    'AI_RATE_LIMITED', ['retry_after' => $result['retry_after'] ?: 30], 429
                ),
                'busy_today' => $this->policy->deny(__('ai_description.busy_today'), 'AI_BUSY', [], 503),
                'bad_output' => $this->policy->deny(__('ai_description.bad_output'), 'AI_BAD_OUTPUT', [], 502),
                default      => $this->policy->deny(__('ai_description.unavailable'), 'AI_UNAVAILABLE', [], 503),
            };
        }

        $this->policy->consume($user->id);
        $first = $result['variants'][0];

        return response()->json([
            'success' => true,
            'data'    => [
                'variants'     => $result['variants'],
                'options'      => $options,
                'partial'      => count($result['variants']) < $result['requested'],
                'usage'        => $this->policy->usage($user->id, $caps),
                'model'        => $result['model'],
                // Shape of the previous endpoint, for older dashboard builds
                'ai_result'    => [
                    'short_description' => $first['short_description'],
                    'description'       => $first['description'],
                ],
                'data_context' => [
                    'name'      => $brief['name'],
                    'category'  => $brief['category'],
                    'store'     => $brief['store'],
                    'promo'     => $brief['promo'],
                    'occasions' => $brief['occasions'],
                ],
            ],
        ]);
    }

    public function saveVoice(Request $request): JsonResponse
    {
        $ctx = $this->policy->resolve($request->user());
        if ($ctx instanceof JsonResponse) return $ctx;
        if (!$ctx['caps']['brand_voice']) {
            return $this->locked('brand_voice', fn($c) => $c['brand_voice']);
        }

        $data = $request->validate([
            'voice'    => 'nullable|string|max:600',
            'keywords' => 'nullable',
        ]);

        $profile = SellerAiProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'brand_voice'    => trim((string) ($data['voice'] ?? '')) ?: null,
                'brand_keywords' => $this->generator->keywords($data['keywords'] ?? []) ?: null,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => __('ai_description.voice_saved'),
            'data'    => ['voice' => $profile->brand_voice ?? '', 'keywords' => $profile->brand_keywords ?? []],
        ]);
    }

    private function locked(string $feature, callable $allows): JsonResponse
    {
        $level = $this->policy->requiredLevel($allows);
        $plan  = $level && $level !== 'free' ? ($this->policy->upgradePlans()[$level] ?? null) : null;

        return $this->policy->deny(
            __('ai_description.locked.' . $feature, ['plan' => $plan['name'] ?? ucfirst((string) $level)]),
            'FEATURE_LOCKED',
            ['feature' => $feature, 'required_level' => $level, 'required_plan' => $plan]
        );
    }
}
