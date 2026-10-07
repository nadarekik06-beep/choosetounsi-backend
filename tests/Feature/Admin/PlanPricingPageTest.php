<?php

namespace Tests\Feature\Admin;

use App\Models\PlanDisplayFeature;
use App\Models\SellerApplication;
use App\Models\SubscriptionAuditLog;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\PlanGate;
use App\Services\PricingCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin-managed pricing page: display features (CRUD, reorder, validation, audit),
 * capability wording / visibility, the cached public GET /api/seller-plans,
 * and capability enforcement staying exactly as before.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Admin/PlanPricingPageTest.php
 */
class PlanPricingPageTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        SubscriptionPlan::flushCache();
        $this->admin = $this->user('admin');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function user(string $role): User
    {
        return User::query()->forceCreate([
            'name' => 'Pricing ' . Str::random(5), 'email' => 'pricing_' . Str::random(12) . '@test.local',
            'password' => bcrypt('secret-password'), 'role' => $role, 'is_active' => true, 'is_approved' => true,
        ]);
    }

    private function plan(array $attrs = []): SubscriptionPlan
    {
        $plan = SubscriptionPlan::create(array_merge([
            'slug' => 'pp-' . Str::lower(Str::random(6)), 'name' => 'Pricing Test Plan', 'tier' => 1,
            'price_monthly' => 79, 'max_products' => 40, 'display_order' => 500,
            'features' => ['promotions' => true, 'coupons' => false, 'sponsorships' => true, 'analytics' => true, 'ai_tools' => false, 'black_hub' => false],
            'is_active' => true,
        ], $attrs));
        SubscriptionPlan::flushCache();
        return $plan;
    }

    private function base(SubscriptionPlan $plan): string
    {
        return "/api/admin/subscription-plans/{$plan->id}/display-features";
    }

    private function publicCard(string $slug): ?array
    {
        return collect($this->getJson('/api/seller-plans')->assertOk()->json('data'))->firstWhere('key', $slug);
    }

    private function sellerOn(string $slug): User
    {
        $seller = $this->user('seller');
        SellerApplication::create([
            'user_id' => $seller->id, 'full_name' => 'Pricing Seller', 'phone_number' => '20000000',
            'business_name' => 'Pricing Shop ' . Str::random(5), 'business_category' => 'other', 'wilaya' => 'Tunis',
            'city' => 'Tunis', 'status' => 'approved', 'plan' => $slug,
        ]);
        return $seller;
    }

    // ── Display features: CRUD, reorder, admin only ───────────────────────────

    public function test_admin_can_add_update_and_delete_display_features(): void
    {
        $plan = $this->plan();
        Sanctum::actingAs($this->admin);

        $id = $this->postJson($this->base($plan), [
            'label' => '  Support prioritaire  ', 'description' => 'Réponse sous 24 h', 'icon' => 'headset', 'highlight' => true,
        ])->assertCreated()
            ->assertJsonPath('data.label', 'Support prioritaire')
            ->assertJsonPath('data.included', true)
            ->assertJsonPath('data.highlight', true)
            ->json('data.id');

        $this->putJson($this->base($plan) . "/{$id}", ['label' => 'Pas de support prioritaire', 'included' => false, 'reason' => 'Comparison row'])
            ->assertOk()->assertJsonPath('data.included', false)->assertJsonPath('data.icon', 'headset');
        $this->assertDatabaseHas('plan_display_features', ['id' => $id, 'label' => 'Pas de support prioritaire', 'included' => 0]);

        $this->deleteJson($this->base($plan) . "/{$id}")->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseMissing('plan_display_features', ['id' => $id]);
    }

    public function test_admin_can_reorder_display_features(): void
    {
        $plan = $this->plan();
        $ids = collect(['A', 'B', 'C'])->map(fn($l, $i) => $plan->displayFeatures()->create(['label' => $l, 'sort_order' => $i])->id)->all();
        Sanctum::actingAs($this->admin);

        $this->putJson($this->base($plan) . '/reorder', ['ids' => array_reverse($ids)])
            ->assertOk()->assertJsonPath('data.0.label', 'C')->assertJsonPath('data.2.label', 'A');

        // Partial lists and foreign ids are refused
        $this->putJson($this->base($plan) . '/reorder', ['ids' => [$ids[0], $ids[1]]])->assertStatus(422);
        $other = $this->plan()->displayFeatures()->create(['label' => 'X'])->id;
        $this->putJson($this->base($plan) . '/reorder', ['ids' => [$ids[0], $ids[1], $other]])->assertStatus(422);
    }

    public function test_display_feature_endpoints_are_admin_only(): void
    {
        $plan = $this->plan();
        $feature = $plan->displayFeatures()->create(['label' => 'Existing']);

        foreach (['seller', 'client'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson($this->base($plan))->assertForbidden();
            $this->postJson($this->base($plan), ['label' => 'Hack'])->assertForbidden();
            $this->putJson($this->base($plan) . "/{$feature->id}", ['label' => 'Hack'])->assertForbidden();
            $this->putJson($this->base($plan) . '/reorder', ['ids' => [$feature->id]])->assertForbidden();
            $this->deleteJson($this->base($plan) . "/{$feature->id}")->assertForbidden();
            $this->putJson("/api/admin/subscription-plans/{$plan->id}", ['display_features' => []])->assertForbidden();
        }
        $this->assertDatabaseHas('plan_display_features', ['id' => $feature->id, 'label' => 'Existing']);
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    public function test_display_feature_validation(): void
    {
        $plan = $this->plan();
        Sanctum::actingAs($this->admin);

        $this->postJson($this->base($plan), ['label' => ''])->assertStatus(422)->assertJsonValidationErrors('label');
        $this->postJson($this->base($plan), ['label' => str_repeat('a', 81)])->assertStatus(422)->assertJsonValidationErrors('label');
        $this->postJson($this->base($plan), ['label' => 'Ok', 'description' => str_repeat('a', 161)])->assertStatus(422)->assertJsonValidationErrors('description');
        $this->postJson($this->base($plan), ['label' => 'Ok', 'icon' => 'skull'])->assertStatus(422)->assertJsonValidationErrors('icon');
        $this->postJson($this->base($plan), ['label' => str_repeat('a', 80), 'description' => str_repeat('b', 160)])->assertCreated();

        // At most 15 per plan, one by one or as a list
        for ($i = $plan->displayFeatures()->count(); $i < PlanDisplayFeature::MAX_PER_PLAN; $i++) {
            $plan->displayFeatures()->create(['label' => "F{$i}", 'sort_order' => $i]);
        }
        $this->postJson($this->base($plan), ['label' => 'One too many'])->assertStatus(422);

        $sixteen = array_map(fn($i) => ['label' => "F{$i}"], range(1, 16));
        $this->putJson("/api/admin/subscription-plans/{$plan->id}", ['display_features' => $sixteen])
            ->assertStatus(422)->assertJsonValidationErrors('display_features');
        $this->putJson("/api/admin/subscription-plans/{$plan->id}", ['display_features' => [['label' => '']]])
            ->assertStatus(422)->assertJsonValidationErrors('display_features.0.label');
        $this->putJson("/api/admin/subscription-plans/{$plan->id}", ['hidden_limits' => ['commission_rate']])
            ->assertStatus(422)->assertJsonValidationErrors('hidden_limits.0');
        $this->putJson("/api/admin/subscription-plans/{$plan->id}", ['capability_display' => ['analytics' => ['label' => str_repeat('a', 81)]]])
            ->assertStatus(422)->assertJsonValidationErrors('capability_display.analytics.label');
    }

    // ── Plan editor: one save for the whole pricing page, audited ──────────────

    public function test_plan_update_syncs_pricing_page_and_writes_audit_log(): void
    {
        $plan = $this->plan();
        $keep = $plan->displayFeatures()->create(['label' => 'Ancien', 'sort_order' => 0]);
        $drop = $plan->displayFeatures()->create(['label' => 'À retirer', 'sort_order' => 1]);
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/admin/subscription-plans/{$plan->id}", [
            'tagline'            => 'Pour les pros',
            'is_recommended'     => true,
            'capability_display' => ['analytics' => ['label' => 'Stats pro', 'visible' => true], 'coupons' => ['visible' => false], 'bogus' => ['label' => 'x']],
            'hidden_limits'      => ['max_images_per_product'],
            'display_features'   => [
                ['label' => 'Nouveau', 'icon' => 'rocket', 'highlight' => true],
                ['id' => $keep->id, 'label' => 'Ancien renommé', 'included' => false],
            ],
            'reason'             => 'Refonte de la page tarifs',
        ])->assertOk()
            ->assertJsonPath('data.display_features.0.label', 'Nouveau')
            ->assertJsonPath('data.display_features.1.id', $keep->id);

        $plan->refresh();
        $this->assertSame('Pour les pros', $plan->tagline);
        $this->assertTrue($plan->is_recommended);
        $this->assertSame(['analytics' => ['label' => 'Stats pro'], 'coupons' => ['visible' => false]], $plan->capability_display);
        $this->assertSame(['max_images_per_product'], $plan->hidden_limits);
        $this->assertDatabaseMissing('plan_display_features', ['id' => $drop->id]);
        // Only one recommended plan
        $this->assertSame(1, SubscriptionPlan::where('is_recommended', true)->count());

        $log = SubscriptionAuditLog::where('subscription_plan_id', $plan->id)->where('action', 'plan_updated')->latest('id')->firstOrFail();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame('Refonte de la page tarifs', $log->reason);
        $this->assertSame(['Ancien', 'À retirer'], array_column($log->before['display_features'], 'label'));
        $this->assertSame(['Nouveau', 'Ancien renommé'], array_column($log->after['display_features'], 'label'));
        $this->assertNull($log->before['tagline']);
        $this->assertSame('Pour les pros', $log->after['tagline']);
    }

    public function test_each_display_feature_change_is_audited(): void
    {
        $plan = $this->plan();
        Sanctum::actingAs($this->admin);

        $id = $this->postJson($this->base($plan), ['label' => 'Un', 'reason' => 'Ajout test'])->json('data.id');
        $two = $this->postJson($this->base($plan), ['label' => 'Deux'])->json('data.id');
        $this->putJson($this->base($plan) . "/{$id}", ['label' => 'Un bis']);
        $this->putJson($this->base($plan) . '/reorder', ['ids' => [$two, $id]]);
        $this->deleteJson($this->base($plan) . "/{$two}");

        $logs = SubscriptionAuditLog::where('subscription_plan_id', $plan->id)->orderBy('id')->get();
        $this->assertSame(
            ['display_feature_added', 'display_feature_added', 'display_feature_updated', 'display_features_reordered', 'display_feature_removed'],
            $logs->pluck('action')->all()
        );
        $this->assertSame('Ajout test', $logs[0]->reason);
        $this->assertSame([], $logs[0]->before['display_features']);
        $this->assertSame(['Un'], array_column($logs[0]->after['display_features'], 'label'));
        $this->assertSame(['Un', 'Deux'], array_column($logs[2]->before['display_features'], 'label'));
        $this->assertSame(['Un bis', 'Deux'], array_column($logs[2]->after['display_features'], 'label'));
        $this->assertSame(['Deux', 'Un bis'], array_column($logs[3]->after['display_features'], 'label'));
        $this->assertTrue($logs->every(fn($l) => $l->actor_id === $this->admin->id && $l->actor_role === 'admin'));
    }

    // ── Public endpoint ────────────────────────────────────────────────────────

    public function test_public_endpoint_returns_only_active_plans_with_public_fields(): void
    {
        $active   = $this->plan(['max_products' => null, 'max_images_per_product' => 5, 'max_sponsored_products' => 3]);
        $inactive = $this->plan(['is_active' => false]);
        $archived = $this->plan(['is_active' => false, 'archived_at' => now()]);
        $active->displayFeatures()->create(['label' => 'Second', 'sort_order' => 2, 'included' => false]);
        $active->displayFeatures()->create(['label' => 'Premier', 'sort_order' => 1, 'icon' => 'star', 'highlight' => true]);

        $data = $this->getJson('/api/seller-plans')->assertOk()->json('data');
        $slugs = array_column($data, 'key');
        $this->assertContains($active->slug, $slugs);
        $this->assertNotContains($inactive->slug, $slugs);
        $this->assertNotContains($archived->slug, $slugs);
        $this->assertSame(SubscriptionPlan::offered()->count(), count($data));

        $card = collect($data)->firstWhere('key', $active->slug);
        $this->assertEqualsCanonicalizing([
            'key', 'name', 'tagline', 'badge_color', 'tier', 'price', 'price_yearly', 'currency',
            'billing_period', 'trial_days', 'max_products', 'features', 'commission_min', 'commission_max',
            'is_default', 'is_recommended', 'limits', 'capabilities', 'display_features',
        ], array_keys($card));
        foreach (['id', 'slug', 'description', 'commission_rate', 'commission_reduction', 'is_active', 'archived_at', 'created_at', 'capability_display', 'hidden_limits'] as $internal) {
            $this->assertArrayNotHasKey($internal, $card);
        }
        $this->assertSame('TND', $card['currency']);
        $this->assertEquals(79, $card['price']);

        $this->assertSame([
            ['key' => 'max_products', 'value' => null, 'label' => 'Produits illimités'],
            ['key' => 'max_images_per_product', 'value' => 5, 'label' => '5 photos par produit'],
            ['key' => 'max_sponsored_products', 'value' => 3, 'label' => '3 produits sponsorisés à la fois'],
        ], $card['limits']);
        $this->assertSame(['promotions', 'coupons', 'sponsorships', 'analytics', 'ai_tools', 'black_hub'], array_column($card['capabilities'], 'key'));
        $this->assertSame([true, false, true, true, false, false], array_column($card['capabilities'], 'included'));
        $this->assertSame([
            ['label' => 'Premier', 'description' => null, 'icon' => 'star', 'included' => true, 'highlight' => true],
            ['label' => 'Second', 'description' => null, 'icon' => null, 'included' => false, 'highlight' => false],
        ], $card['display_features']);
    }

    public function test_hidden_capabilities_and_limits_and_label_overrides(): void
    {
        $plan = $this->plan([
            'max_products' => 30,
            'hidden_limits' => ['max_images_per_product'],
            'capability_display' => ['coupons' => ['visible' => false], 'analytics' => ['label' => 'Stats pro', 'description' => 'Tout voir']],
            // No sponsoring: its limit line is never shown
            'features' => ['promotions' => true, 'analytics' => true],
        ]);

        $card = $this->publicCard($plan->slug);
        $this->assertSame([['key' => 'max_products', 'value' => 30, 'label' => '30 produits']], $card['limits']);
        $this->assertNotContains('coupons', array_column($card['capabilities'], 'key'));
        $analytics = collect($card['capabilities'])->firstWhere('key', 'analytics');
        $this->assertSame('Stats pro', $analytics['label']);
        $this->assertSame('Tout voir', $analytics['description']);
        $this->assertSame('Promotions et ventes flash', collect($card['capabilities'])->firstWhere('key', 'promotions')['label']);
    }

    public function test_public_cache_is_invalidated_on_every_kind_of_change(): void
    {
        $plan = $this->plan(['name' => 'Avant']);
        $this->assertSame('Avant', $this->publicCard($plan->slug)['name']);
        $this->assertTrue(Cache::has(PricingCatalog::CACHE_KEY));

        // Plan edit through the admin API
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/admin/subscription-plans/{$plan->id}", ['name' => 'Après', 'capability_display' => ['analytics' => ['label' => 'Stats+']]])->assertOk();
        $card = $this->publicCard($plan->slug);
        $this->assertSame('Après', $card['name']);
        $this->assertSame('Stats+', collect($card['capabilities'])->firstWhere('key', 'analytics')['label']);

        // Display feature added / edited / removed
        $id = $this->postJson($this->base($plan), ['label' => 'Nouveauté'])->assertCreated()->json('data.id');
        $this->assertSame(['Nouveauté'], array_column($this->publicCard($plan->slug)['display_features'], 'label'));
        $this->putJson($this->base($plan) . "/{$id}", ['label' => 'Nouveauté 2'])->assertOk();
        $this->assertSame(['Nouveauté 2'], array_column($this->publicCard($plan->slug)['display_features'], 'label'));
        $this->deleteJson($this->base($plan) . "/{$id}")->assertOk();
        $this->assertSame([], $this->publicCard($plan->slug)['display_features']);

        // Deactivated → gone from the public list
        $this->patchJson("/api/admin/subscription-plans/{$plan->id}/toggle")->assertOk();
        $this->assertNull($this->publicCard($plan->slug));
    }

    public function test_recommended_toggle_is_exclusive(): void
    {
        $a = $this->plan();
        $b = $this->plan();
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/admin/subscription-plans/{$a->id}/recommended")->assertOk()->assertJsonPath('data.is_recommended', true);
        $this->patchJson("/api/admin/subscription-plans/{$b->id}/recommended")->assertOk();
        $this->assertFalse($a->fresh()->is_recommended);
        $this->assertTrue($this->publicCard($b->slug)['is_recommended']);
        $this->assertSame(1, collect($this->getJson('/api/seller-plans')->json('data'))->where('is_recommended', true)->count());
    }

    // ── Enforcement is unchanged ───────────────────────────────────────────────

    public function test_capability_enforcement_ignores_pricing_page_settings(): void
    {
        $plan = $this->plan();
        $seller = $this->sellerOn($plan->slug);
        $gate = app(PlanGate::class);

        $before = collect(\App\Enums\PlanCapability::keys())->mapWithKeys(fn($k) => [$k => $gate->feature($seller->id, $k) === null])->all();
        $this->assertSame(['analytics' => true, 'ai_tools' => false, 'black_hub' => false, 'promotions' => true, 'coupons' => false, 'sponsorships' => true], $before);

        // Hide / relabel everything on the pricing page, add display bullets claiming more
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/admin/subscription-plans/{$plan->id}", [
            'capability_display' => collect($before)->map(fn() => ['visible' => false, 'label' => 'Autre'])->all(),
            'hidden_limits'      => ['max_products', 'max_images_per_product', 'max_sponsored_products'],
            'display_features'   => [['label' => 'Outils IA inclus']],
        ])->assertOk();
        SubscriptionPlan::flushCache();

        $after = collect(\App\Enums\PlanCapability::keys())->mapWithKeys(fn($k) => [$k => $gate->feature($seller->id, $k) === null])->all();
        $this->assertSame($before, $after);
        $this->assertEquals($plan->fresh()->features, $plan->features);

        // Routes still gated the same way
        Sanctum::actingAs($seller);
        $this->postJson('/api/seller/coupons', [])->assertForbidden()->assertJsonPath('code', 'PLAN_REQUIRED');
        $this->postJson('/api/seller/ai/price-optimizer', [])->assertForbidden()->assertJsonPath('code', 'PLAN_REQUIRED');
    }

    public function test_existing_plans_keep_their_capabilities_after_migration(): void
    {
        $expected = [
            'free'  => ['promotions' => true, 'coupons' => true, 'sponsorships' => true, 'analytics' => false, 'ai_tools' => false, 'black_hub' => false],
            'red'   => ['promotions' => true, 'coupons' => true, 'sponsorships' => true, 'analytics' => true, 'ai_tools' => true, 'black_hub' => false],
            'black' => ['promotions' => true, 'coupons' => true, 'sponsorships' => true, 'analytics' => true, 'ai_tools' => true, 'black_hub' => true],
        ];
        foreach ($expected as $slug => $flags) {
            $plan = SubscriptionPlan::where('slug', $slug)->first();
            if (!$plan) continue; // test DB without the seeded plans
            foreach ($flags as $key => $on) {
                if (array_key_exists($key, $plan->features ?? [])) {
                    $this->assertSame($on, $plan->hasFeature($key), "{$slug}.{$key}");
                }
            }
        }
        $this->assertSame(\App\Enums\PlanCapability::keys(), array_keys(\App\Enums\PlanCapability::adminLabels()));
    }
}
