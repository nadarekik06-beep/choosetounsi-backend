<?php

namespace Tests\Feature\Seller;

use App\Models\Category;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Models\User;
use App\Services\ProductCopy\DescriptionCleaner;
use App\Services\ProductCopy\DescriptionPrompt;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AI product descriptions: plan gating (backend), daily limits, Groq failures,
 * output cleaning. Groq is always faked.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Seller/AiDescriptionTest.php
 */
class AiDescriptionTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = 'https://api.groq.com/*';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.groq.key' => 'test-key']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function seller(string $plan): User
    {
        $user = User::factory()->create(['role' => 'seller', 'is_approved' => true, 'locale' => 'fr']);
        SellerApplication::create([
            'user_id' => $user->id, 'full_name' => 'AI Test', 'phone_number' => '20000000',
            'business_name' => 'Atelier ' . Str::random(4), 'business_category' => 'other', 'wilaya' => 'Tunis',
            'city' => 'Tunis', 'status' => 'approved', 'plan' => $plan,
        ]);
        Sanctum::actingAs($user);
        return $user;
    }

    private function variant(string $lang = 'fr', array $extra = []): array
    {
        $texts = [
            'fr' => ['Le coton épais garde sa tenue après chaque lavage, même en plein été à Sousse.',
                     ['Taille S à XL : une coupe pour chacun', 'Coton : léger et agréable sur la peau'],
                     'Choisissez votre taille et ajoutez-le au panier.', 'T-shirt en coton, du S au XL.'],
            'en' => ['Thick cotton that keeps its shape after every wash, even in the middle of a Sousse summer.',
                     ['Sizes S to XL: a cut for everyone', 'Cotton: light and pleasant on the skin'],
                     'Pick your size and add it to your cart.', 'Cotton T-shirt, S to XL.'],
            'ar' => ['قطن سميك يحافظ على شكله بعد كل غسلة، حتى في عزّ صيف سوسة.',
                     ['مقاسات من S إلى XL تناسب الجميع', 'قطن خفيف ومريح على البشرة'],
                     'اختر مقاسك وأضفه إلى السلة.', 'قميص قطني بمقاسات من S إلى XL.'],
        ][$lang];

        return $extra + [
            'language' => $lang, 'intro' => $texts[0], 'bullets' => $texts[1], 'closing' => $texts[2], 'short' => $texts[3],
            'seo_title' => 'T-shirt coton', 'tags' => ['t-shirt', 'coton', 'été', 'homme', 'sousse'], 'social' => 'Le t-shirt de l\'été. #ete',
        ];
    }

    /** Groq chat-completion response carrying $data as the JSON message. */
    private function groq(array $data, int $status = 200): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response([
            'choices' => [['message' => ['content' => json_encode($data, JSON_UNESCAPED_UNICODE)], 'finish_reason' => 'stop']],
            'usage'   => ['total_tokens' => 1000],
        ], $status);
    }

    private function body(array $extra = []): array
    {
        return $extra + [
            'name' => 'T-Shirt coton', 'category' => 'Mode', 'price' => 35,
            'attributes' => ['Matière' => 'Coton'], 'option_groups' => ['Taille' => ['S', 'M', 'L', 'XL']],
            'tone' => 'professional', 'length' => 'medium', 'language' => 'fr', 'count' => 1,
        ];
    }

    private function generate(array $body)
    {
        return $this->withHeader('Accept-Language', 'fr')->postJson('/api/seller/ai/description', $body);
    }

    // ── Plan gating ───────────────────────────────────────────────────────

    public function test_free_seller_gets_the_generator_with_locks(): void
    {
        $this->seller('free');

        $res = $this->withHeader('Accept-Language', 'fr')->getJson('/api/seller/ai/description/options')->assertOk();
        $res->assertJsonPath('data.level', 'free')
            ->assertJsonPath('data.max_variants', 1)
            ->assertJsonPath('data.extras', false)
            ->assertJsonPath('data.default_language', 'fr')
            ->assertJsonPath('data.usage.limit', config('ai_descriptions.levels.free.daily_limit'));

        $tones = collect($res->json('data.tones'))->keyBy('key');
        $this->assertFalse($tones['professional']['locked']);
        $this->assertFalse($tones['friendly']['locked']);
        $this->assertTrue($tones['luxury']['locked']);
        $this->assertSame('red', $tones['luxury']['requires']);

        $langs = collect($res->json('data.languages'))->keyBy('key');
        $this->assertFalse($langs['fr']['locked']);
        $this->assertTrue($langs['ar']['locked']);
    }

    public function test_free_seller_locked_options_are_refused_by_the_backend(): void
    {
        $this->seller('free');
        Http::fake([self::URL => $this->groq(['variants' => [$this->variant()]])]);

        $this->generate($this->body(['tone' => 'luxury']))->assertStatus(403)
            ->assertJsonPath('code', 'FEATURE_LOCKED')->assertJsonPath('feature', 'tone')
            ->assertJsonPath('required_plan.slug', 'red');
        $this->generate($this->body(['language' => 'ar']))->assertStatus(403)->assertJsonPath('feature', 'language');
        $this->generate($this->body(['count' => 3]))->assertStatus(403)->assertJsonPath('feature', 'variants');

        Http::assertNothingSent();
    }

    public function test_free_seller_generates_one_variant_without_extras(): void
    {
        $this->seller('free');
        Http::fake([self::URL => $this->groq(['variants' => [$this->variant()]])]);

        $res = $this->generate($this->body(['tone' => 'friendly']))->assertOk();

        $res->assertJsonCount(1, 'data.variants')
            ->assertJsonPath('data.usage.used', 1)
            ->assertJsonPath('data.ai_result.short_description', 'T-shirt en coton, du S au XL.');
        $variant = $res->json('data.variants.0');
        $this->assertArrayNotHasKey('seo_title', $variant);
        $this->assertStringContainsString("\n• Taille S à XL", $variant['description']);

        // Prompt carries the real product data and the store name
        Http::assertSent(function (HttpRequest $req) {
            $user = $req['messages'][1]['content'];
            return str_contains($user, 'T-Shirt coton') && str_contains($user, 'Taille (4): S, M, L, XL')
                && str_contains($user, 'Matière: Coton') && str_contains($user, 'Store: Atelier')
                && $req['response_format']['type'] === 'json_schema';
        });
    }

    public function test_daily_limit_is_enforced_and_failures_are_not_counted(): void
    {
        $this->seller('free');
        config(['ai_descriptions.levels.free.daily_limit' => 2]);
        Http::fake([self::URL => Http::sequence()
            ->push(['error' => ['message' => 'down']], 500)->push(['error' => ['message' => 'down']], 500)   // main model ×2 (retry)
            ->push(['error' => ['message' => 'down']], 500)->push(['error' => ['message' => 'down']], 500)   // fallback ×2
            ->whenEmpty($this->groq(['variants' => [$this->variant()]]))]);

        $this->generate($this->body())->assertStatus(503)->assertJsonPath('code', 'AI_UNAVAILABLE');
        $this->generate($this->body())->assertOk()->assertJsonPath('data.usage.used', 1);
        $this->generate($this->body())->assertOk()->assertJsonPath('data.usage.remaining', 0);
        $this->generate($this->body())->assertStatus(429)->assertJsonPath('code', 'AI_DAILY_LIMIT')
            ->assertJsonPath('required_plan.slug', 'red');
    }

    public function test_red_seller_gets_three_variants_with_extras_but_not_multi_language(): void
    {
        $this->seller('red');
        Http::fake([self::URL => $this->groq(['variants' => [
            $this->variant('en'),
            $this->variant('en', ['intro' => 'Heading to the beach in Hammamet? This cotton tee keeps its shape all day long.']),
            $this->variant('en', ['intro' => 'Who wants a T-shirt that looks tired after two washes? Not you, and not this thick cotton one.']),
        ]])]);

        $res = $this->generate($this->body(['tone' => 'luxury', 'language' => 'en', 'count' => 3]))->assertOk();
        $res->assertJsonCount(3, 'data.variants');
        $this->assertSame(['t-shirt', 'coton', 'été', 'homme', 'sousse'], $res->json('data.variants.0.tags'));
        $this->assertNotSame('', $res->json('data.variants.0.seo_title'));

        $this->generate($this->body(['languages' => ['fr', 'ar']]))->assertStatus(403)
            ->assertJsonPath('feature', 'multi_language')->assertJsonPath('required_plan.slug', 'black');
    }

    public function test_black_seller_generates_every_language_at_once_with_store_voice(): void
    {
        $this->seller('black');
        $this->putJson('/api/seller/ai/description/voice', ['voice' => 'Chaleureux, tutoiement, clin d\'œil à la mer', 'keywords' => 'fait main, Mahdia'])
            ->assertOk()->assertJsonPath('data.keywords', ['fait main', 'Mahdia']);

        Http::fake([self::URL => $this->groq(['variants' => [$this->variant('fr'), $this->variant('ar'), $this->variant('en')]])]);

        $res = $this->generate($this->body(['languages' => ['fr', 'ar', 'en']]))->assertOk();
        $this->assertSame(['fr', 'ar', 'en'], array_column($res->json('data.variants'), 'language'));

        Http::assertSent(fn(HttpRequest $req) => str_contains($req['messages'][1]['content'], 'Store voice')
            && str_contains($req['messages'][1]['content'], 'fait main, Mahdia'));
    }

    public function test_store_voice_is_black_only(): void
    {
        $this->seller('red');
        $this->putJson('/api/seller/ai/description/voice', ['voice' => 'x'])->assertStatus(403)
            ->assertJsonPath('feature', 'brand_voice');
    }

    // ── Groq failures ─────────────────────────────────────────────────────

    public function test_rate_limit_falls_back_to_the_second_model(): void
    {
        $this->seller('red');
        $fallback = config('ai_descriptions.fallback_model');
        Http::fake(fn(HttpRequest $req) => $req['model'] === $fallback
            ? $this->groq(['variants' => [$this->variant()]])
            : Http::response(['error' => ['message' => 'rate limited']], 429, ['retry-after' => '12']));

        $this->generate($this->body())->assertOk()->assertJsonPath('data.model', $fallback);
    }

    public function test_rate_limit_on_both_models_gives_a_clear_message_and_counts_nothing(): void
    {
        $user = $this->seller('red');
        Http::fake([self::URL => Http::response(['error' => ['message' => 'rate limited']], 429, ['retry-after' => '12'])]);

        $this->generate($this->body())->assertStatus(429)
            ->assertJsonPath('code', 'AI_RATE_LIMITED')->assertJsonPath('retry_after', 12);
        $this->assertSame(0, app(\App\Services\ProductCopy\DescriptionPolicy::class)
            ->usage($user->id, config('ai_descriptions.levels.red'))['used']);
    }

    public function test_unusable_output_is_an_error_not_a_fake_description(): void
    {
        $this->seller('red');
        Http::fake([self::URL => $this->groq(['variants' => [['language' => 'fr', 'intro' => '', 'bullets' => [], 'closing' => '', 'short' => '']]])]);

        $this->generate($this->body())->assertStatus(502)->assertJsonPath('code', 'AI_BAD_OUTPUT');
    }

    public function test_legacy_quick_description_route_still_works(): void
    {
        $this->seller('red');
        Http::fake([self::URL => $this->groq(['variants' => [$this->variant()]])]);

        $this->withHeader('Accept-Language', 'fr')->postJson('/api/seller/ai/quick-description', [
            'name' => 'T-Shirt', 'category' => 'Mode', 'price' => 35, 'tone' => 'casual', 'language' => 'fr',
        ])->assertOk()->assertJsonPath('data.options.tone', 'friendly')
          ->assertJsonStructure(['data' => ['ai_result' => ['short_description', 'description']]]);
    }

    public function test_other_sellers_product_is_not_found_and_non_sellers_are_refused(): void
    {
        $owner = $this->seller('red');
        $s     = Str::random(6);
        $cat   = Category::create(['name' => "Cat $s", 'name_ar' => "Cat $s", 'name_fr' => "Cat $s", 'slug' => "cat-$s", 'is_active' => true]);
        $product = Product::create(['seller_id' => $owner->id, 'category_id' => $cat->id, 'name' => 'Mine', 'slug' => "mine-$s", 'price' => 10, 'stock' => 1]);

        $this->seller('red');
        Http::fake();
        $this->generate($this->body(['product_id' => $product->id]))->assertStatus(404);

        Sanctum::actingAs(User::factory()->create(['role' => 'seller', 'is_approved' => true]));
        $this->generate($this->body())->assertStatus(403);
        Http::assertNothingSent();
    }

    // ── Output cleaning ───────────────────────────────────────────────────

    public function test_cleaner_strips_markdown_and_removes_cliches_and_invented_specs(): void
    {
        $cleaner = new DescriptionCleaner();
        $facts   = "- Product name: Air fryer\n- Attributes: Power: 2100W; Volume: 6.2L\n- Price: 225 DT";
        $plan    = [['language' => 'fr', 'hook' => 'detail', 'closing' => 'direct']];

        $out = $cleaner->clean(['variants' => [[
            'intro'   => '**Avec 2100 W**, la cuisson démarre vite. Livrée en 24h partout.',
            'bullets' => ['- Capacité de 6,2 L pour toute la famille', '* Parfait pour les soirées', 'Garantie 2 ans incluse', 'Frites prêtes en 15 minutes', 'Panier de 6.2 litres facile à vider'],
            'closing' => 'Ajoutez-la au panier pour vos dîners de la semaine.',
            'short'   => '## Friteuse 6,2 L',
        ]]], $plan, $facts, false);

        $this->assertCount(1, $out);
        $v = $out[0];
        $this->assertSame('Avec 2100 W, la cuisson démarre vite.', $v['intro']);              // delivery claim removed
        $this->assertSame(['Capacité de 6,2 L pour toute la famille', 'Panier de 6.2 litres facile à vider'], $v['bullets']);
        $this->assertSame('Friteuse 6,2 L', $v['short_description']);
        $this->assertContains('unverified_spec', $v['flags']);                              // "15 minutes"
        $this->assertContains('unverified_claim', $v['flags']);                             // delivery, warranty
        $this->assertContains('banned_phrase', $v['flags']);
        $this->assertStringNotContainsString('*', $v['description']);
    }

    public function test_cleaner_fixes_misspelled_brand_tags_but_keeps_real_words(): void
    {
        $cleaner = new DescriptionCleaner();
        $plan    = [['language' => 'fr', 'hook' => 'detail', 'closing' => 'direct']];
        $facts   = "- Product name: Air fryer\n- Store: ABDOUSHOP\n- Attributes: Brand: Phillip";

        $out = $cleaner->clean(['variants' => [[
            'intro' => 'La friteuse à air Phillip prépare les frites pour toute la famille.', 'bullets' => ['Capacité familiale pour le dîner', 'Panier amovible pour le service'],
            'closing' => 'Ajoutez-la au panier.', 'short' => 'Friteuse à air', 'seo_title' => 'Friteuse', 'social' => 'Frites #cuisine',
            'tags' => ['philippe', '#T-Shirt', 'cuisine', 'abdoushopp', 'cuisine'],
        ]]], $plan, $facts, true);

        $this->assertSame(['phillip', 't-shirt', 'cuisine', 'abdoushop'], $out[0]['tags']);
    }

    public function test_cleaner_drops_wrong_language_and_duplicate_openings(): void
    {
        $cleaner = new DescriptionCleaner();
        $plan    = [
            ['language' => 'ar', 'hook' => 'detail', 'closing' => 'direct'],
            ['language' => 'fr', 'hook' => 'scene', 'closing' => 'direct'],
            ['language' => 'fr', 'hook' => 'buyer', 'closing' => 'direct'],
        ];
        $fr = ['intro' => 'Le coton épais garde sa tenue après chaque lavage et pendant tout l\'été.', 'bullets' => ['Coupe ample pour la chaleur', 'Du S au XL pour toute la famille'], 'closing' => 'Ajoutez-le au panier.', 'short' => 'T-shirt coton'];

        $out = $cleaner->clean(['variants' => [$fr, $fr, $fr]], $plan, '- Product name: T-shirt', false);

        // #0 is French text in an Arabic slot, #2 repeats #1's opening
        $this->assertCount(1, $out);
        $this->assertSame('fr', $out[0]['language']);
    }

    public function test_prompt_gives_each_variant_a_different_opening_and_uses_the_seller_data(): void
    {
        $prompt = new DescriptionPrompt();
        $built  = $prompt->build([
            'name' => 'Assiette Nabeul', 'store' => 'Dar Fekhar', 'category' => 'Maison', 'price' => 45.5,
            'promo' => ['percent' => 20, 'final' => 36.4, 'ends_at' => '12 October 2026', 'flash' => false],
            'attributes' => ['Matière' => 'Céramique'], 'variants' => [], 'option_groups' => [], 'pack' => null,
            'occasions' => ['ramadan'], 'notes' => 'Peinte à la main à Nabeul', 'draft' => null, 'keywords' => ['poterie'],
        ], ['tone' => 'promo', 'length' => 'short', 'languages' => ['fr'], 'count' => 3, 'extras' => true]);

        $this->assertSame('offer', $built['plan'][0]['hook']);                       // promo tone + real offer
        $this->assertCount(3, array_unique(array_column($built['plan'], 'hook')));
        $this->assertStringContainsString('-20%, now 36,400 DT, until 12 October 2026', $built['facts']);
        $this->assertStringContainsString('Peinte à la main à Nabeul', $built['facts']);
        $this->assertStringContainsString('"parfait pour"', $built['system']);
        $this->assertSame(3, $built['schema']['properties']['variants']['minItems']);
    }
}
