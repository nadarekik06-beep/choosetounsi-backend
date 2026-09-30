<?php

namespace Tests\Feature\Ads;

use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Models\Sponsorship;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\UserPreference;
use App\Services\Ads\AdSettings;
use App\Services\PlanGate;
use App\Support\Wilayas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Recommendation\MakesCatalog;
use Tests\TestCase;

/**
 * Phase 0 of the sponsoring redesign: settings, and the audit bugs fixed before
 * the new engine lands (targeting, wilaya, no writes on GET, honest sorts, …).
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Ads/SponsoringFoundationsTest.php
 */
class SponsoringFoundationsTest extends TestCase
{
    use DatabaseTransactions, MakesCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        SubscriptionPlan::flushCache();
        app(AdSettings::class)->flush();
        $this->fakeTranslator();
    }

    private function sponsor(Product $p, array $attrs = []): Sponsorship
    {
        DB::table('products')->where('id', $p->id)->update(['is_sponsored' => true, 'sponsored_priority' => 30, 'sponsored_at' => now()]);
        return Sponsorship::create(array_merge([
            'seller_id' => $p->seller_id, 'product_id' => $p->id, 'plan_type' => 'red', 'boost_score' => 30,
            'pricing_model' => Sponsorship::PRICING_LEGACY,
            'status' => 'active', 'start_at' => now()->subDay(), 'end_at' => now()->addWeek(),
        ], $attrs));
    }

    private function approvedSeller(string $plan = 'free'): User
    {
        $seller = $this->makeUser('seller');
        SellerApplication::create([
            'user_id' => $seller->id, 'full_name' => 'Ads Test', 'phone_number' => '20000000',
            'business_name' => 'Ads Test Shop ' . Str::random(4), 'business_category' => 'other', 'wilaya' => 'Tunis',
            'city' => 'Tunis', 'status' => 'approved', 'plan' => $plan,
        ]);
        return $seller;
    }

    private function feedIds(array $headers = [], array $query = []): array
    {
        $res = $this->getJson('/api/sponsored-products?' . http_build_query($query + ['limit' => 40]), $headers)->assertOk();
        return collect($res->json('data'))->where('is_sponsored', true)->pluck('id')->all();
    }

    // ── Settings ────────────────────────────────────────────────────────────

    public function test_ad_settings_fall_back_to_config_and_apply_admin_overrides(): void
    {
        $settings = app(AdSettings::class);
        $this->assertSame(0.2, (float) $settings->get('min_cpc'));
        $this->assertSame(0.15, (float) $settings->get('tier_click_discount.red'));

        PlatformSetting::setValue('ads.min_cpc', 0.35);
        $this->assertSame(0.2, (float) $settings->get('min_cpc'), 'cached until flushed');
        $settings->flush();
        $this->assertSame(0.35, (float) $settings->get('min_cpc'));

        $settings->set(['tier_click_discount' => ['free' => 0, 'red' => 0.2, 'black' => 0.4]]);
        $this->assertSame(0.2, (float) $settings->get('tier_click_discount.red'));

        $this->expectException(InvalidArgumentException::class);
        $settings->set(['not_a_setting' => 1]);
    }

    // ── Bug 2: public feed resolves the Bearer user, so targeting applies ─────

    public function test_public_feed_applies_targeting_for_bearer_token_users(): void
    {
        $seller = $this->makeUser('seller');
        $cat    = $this->makeCategory();
        $forWomen = $this->makeProduct($seller, $cat);
        $forAll   = $this->makeProduct($seller, $cat);
        $this->sponsor($forWomen, ['target_gender' => 'female']);
        $this->sponsor($forAll);

        $buyer = $this->makeUser();
        UserPreference::create(['user_id' => $buyer->id, 'gender' => 'male']);
        $token = $buyer->createToken('test')->plainTextToken;

        $ids = $this->feedIds(['Authorization' => "Bearer {$token}"], ['category_slug' => $cat->slug]);
        $this->assertContains($forAll->id, $ids);
        $this->assertNotContains($forWomen->id, $ids, 'a male buyer must not get a women-only ad');

        // Backfill never re-adds the targeted-out ad, even unlabelled.
        $all = $this->getJson("/api/sponsored-products?category_slug={$cat->slug}&min_results=5", ['Authorization' => "Bearer {$token}"])->json('data');
        $this->assertNotContains($forWomen->id, collect($all)->pluck('id')->all());

        // Guests are never excluded by targeting. (The test app keeps resolved guards between
        // requests; a real request starts fresh.)
        $this->app['auth']->forgetGuards();
        $this->assertContains($forWomen->id, $this->feedIds([], ['category_slug' => $cat->slug]));
    }

    // ── Bug 3: wilaya targeting uses the buyer's address / last order ────────

    public function test_wilaya_names_normalize_across_spellings(): void
    {
        $this->assertSame('Le Kef', Wilayas::normalize('Kef'));
        $this->assertSame('La Manouba', Wilayas::normalize('manouba'));
        $this->assertSame('Médenine', Wilayas::normalize('Medenine'));
        $this->assertSame('Béja', Wilayas::normalize('BEJA'));
        $this->assertNull(Wilayas::normalize('Paris'));
        $this->assertSame(['Le Kef', 'Sfax'], Wilayas::normalizeMany(['Kef', 'Le Kef', 'sfax', 'nowhere']));
    }

    public function test_wilaya_targeting_uses_default_address_then_latest_order(): void
    {
        $seller = $this->makeUser('seller');
        $product = $this->makeProduct($seller, $this->makeCategory());
        $kefAd  = $this->sponsor($product, ['target_wilaya_ids' => ['Kef']]);   // legacy loose spelling
        $sfaxAd = new Sponsorship(['target_wilaya_ids' => ['Sfax']]);

        $buyer = $this->makeUser();
        UserAddress::create(['user_id' => $buyer->id, 'label' => 'Home', 'wilaya' => 'Le Kef', 'address' => 'Rue 1', 'phone' => '20000000', 'is_default' => true]);
        $buyer = $buyer->fresh();
        $this->assertTrue($kefAd->matchesUser($buyer));
        $this->assertFalse($sfaxAd->matchesUser($buyer));

        // No address: the latest order's wilaya is used.
        $orderBuyer = $this->makeUser();
        DB::table('orders')->insert([
            'user_id' => $orderBuyer->id, 'order_number' => 'ADS-' . Str::random(10), 'total_amount' => 10,
            'wilaya' => 'Sfax', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('Sfax', $orderBuyer->targetingWilaya());
        $this->assertTrue($sfaxAd->matchesUser($orderBuyer));
        $this->assertFalse($kefAd->matchesUser($orderBuyer));

        // Unknown location: not filtered out.
        $this->assertTrue($kefAd->matchesUser($this->makeUser()));
    }

    // ── Bug 14: reads never write; the scheduled command ends sponsorships ───

    public function test_ended_sponsorship_is_hidden_without_a_write_and_ended_by_the_command(): void
    {
        $seller  = $this->makeUser('seller');
        $cat     = $this->makeCategory();
        $product = $this->makeProduct($seller, $cat);
        $ended   = $this->sponsor($product, ['start_at' => now()->subWeek(), 'end_at' => now()->subMinute()]);

        $this->assertNotContains($product->id, $this->feedIds([], ['category_slug' => $cat->slug]));
        $this->assertSame('active', $ended->fresh()->status, 'a GET must not change sponsorship rows');

        $this->artisan('ads:complete-ended')->assertExitCode(0);
        $this->assertSame('completed', $ended->fresh()->status);
        $this->assertFalse((bool) $product->fresh()->is_sponsored);

        $this->artisan('sponsorships:expire')->assertExitCode(0);   // old name still works
    }

    // ── Bug 13: explicit sorts are honoured; sponsored products don't jump ahead

    public function test_explicit_sort_is_not_overridden_by_sponsored_products(): void
    {
        $seller = $this->makeUser('seller');
        $cat    = $this->makeCategory();
        $cheap  = $this->makeProduct($seller, $cat, ['price' => 5]);
        $pricey = $this->makeProduct($seller, $cat, ['price' => 500]);
        $this->sponsor($pricey);

        $ids = collect($this->getJson("/api/products?category_slug={$cat->slug}&sort=price_asc")->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertSame([$cheap->id, $pricey->id], $ids);
    }

    // ── Bug 8: admin search can't bypass the status filter ───────────────────

    public function test_admin_search_keeps_status_filter(): void
    {
        $seller = $this->makeUser('seller', ['name' => 'Zyx Findable Seller']);
        $cat    = $this->makeCategory();
        $active    = $this->sponsor($this->makeProduct($seller, $cat));
        $cancelled = $this->sponsor($this->makeProduct($seller, $cat), ['status' => 'cancelled']);

        Sanctum::actingAs($this->makeUser('admin'));
        $ids = collect($this->getJson('/api/admin/sponsorships?status=cancelled&search=Zyx%20Findable')->assertOk()->json('data.data'))->pluck('id')->all();

        $this->assertContains($cancelled->id, $ids);
        $this->assertNotContains($active->id, $ids);
    }

    // ── Bug 9: one tier resolution everywhere (custom plans price by tier) ────

    public function test_custom_plan_resolves_to_its_tier(): void
    {
        $slug = 'gold-' . Str::lower(Str::random(5));
        DB::table('subscription_plans')->insert([
            'slug' => $slug, 'name' => 'Gold', 'tier' => 2, 'features' => json_encode(['sponsorships' => true, 'black_hub' => true]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        SubscriptionPlan::flushCache();
        $seller = $this->approvedSeller($slug);

        $this->assertSame('black', app(PlanGate::class)->tierFor($seller->id));

        Sanctum::actingAs($seller);
        $this->getJson('/api/seller/ads/config')->assertOk()->assertJsonPath('data.tier', 'black');
    }

    // ── Bug 4: the Black Pepper direct toggle is gone ────────────────────────

    public function test_black_pepper_direct_toggle_route_is_removed(): void
    {
        $seller  = $this->approvedSeller('black');
        $product = $this->makeProduct($seller, $this->makeCategory());
        Sanctum::actingAs($seller);

        $this->postJson("/api/seller/black/sponsor/{$product->id}", ['action' => 'activate'])->assertNotFound();
        $this->assertFalse((bool) $product->fresh()->is_sponsored);
    }

    // ── Bug 18: AI service calls carry the shared secret ─────────────────────

    public function test_ai_service_requests_carry_the_shared_secret(): void
    {
        config(['services.ai.token' => 'phase0-secret']);
        Http::fake(['*' => Http::response(['results' => []])]);

        Http::ai()->post('http://ai.test/similar', ['seeds' => [1 => 1.0]]);

        Http::assertSent(fn ($request) => $request->hasHeader('X-AI-Token', 'phase0-secret'));
    }
}
