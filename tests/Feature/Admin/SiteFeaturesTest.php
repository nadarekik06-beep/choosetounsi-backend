<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\SiteFeatures;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin-switchable storefront sections (WearTounsi).
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Admin/SiteFeaturesTest.php
 */
class SiteFeaturesTest extends TestCase
{
    use DatabaseTransactions;

    private Product $brandProduct;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(SiteFeatures::CACHE_KEY);
        \App\Models\PlatformSetting::where('key', 'like', SiteFeatures::PREFIX . '%')->delete();
        $this->resetMemo();

        $category = Category::create(['name' => 'Features Test ' . Str::random(5), 'name_fr' => 'Test', 'name_ar' => 'Test', 'slug' => 'features-test-' . Str::random(8)]);
        $name = 'WearTounsi Test ' . Str::random(6);
        $this->brandProduct = Product::create([
            'seller_id'           => null,
            'category_id'         => $category->id,
            'name'                => $name,
            'slug'                => Str::slug($name),
            'price'               => 50,
            'stock'               => 5,
            'is_approved'         => true,
            'is_active'           => true,
            'is_platform_product' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Cache::forget(SiteFeatures::CACHE_KEY);
        $this->resetMemo();
        parent::tearDown();
    }

    private function resetMemo(): void
    {
        (new \ReflectionProperty(SiteFeatures::class, 'memo'))->setValue(null, null);
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'name'      => 'Features Test ' . Str::random(5),
            'email'     => 'features_' . Str::random(10) . '@test.local',
            'password'  => bcrypt('secret-password'),
            'role'      => $role,
            'is_active' => true,
        ]);
    }

    private function brandIds(): array
    {
        return collect($this->getJson('/api/brand-products?per_page=60')->assertOk()->json('data.data'))->pluck('id')->all();
    }

    public function test_wear_tounsi_is_off_by_default_and_hides_brand_products(): void
    {
        $this->getJson('/api/site-features')->assertOk()->assertJsonPath('data.wear_tounsi', false);
        $this->assertNotContains($this->brandProduct->id, $this->brandIds());
        $this->getJson("/api/brand-products/{$this->brandProduct->slug}")->assertNotFound();
    }

    public function test_admin_enables_wear_tounsi(): void
    {
        Sanctum::actingAs($this->makeUser('admin'));
        $this->putJson('/api/admin/site-features', ['wear_tounsi' => true])
            ->assertOk()->assertJsonPath('data.wear_tounsi', true);

        $this->getJson('/api/site-features')->assertJsonPath('data.wear_tounsi', true);
        $this->assertContains($this->brandProduct->id, $this->brandIds());
    }

    public function test_non_admin_cannot_toggle(): void
    {
        Sanctum::actingAs($this->makeUser('client'));
        $this->putJson('/api/admin/site-features', ['wear_tounsi' => true])->assertForbidden();
        $this->assertFalse(SiteFeatures::enabled('wear_tounsi'));
    }
}
