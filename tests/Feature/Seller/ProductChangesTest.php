<?php

namespace Tests\Feature\Seller;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductChangeSet;
use App\Models\ProductImage;
use App\Models\ProductPriceHistory;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\SellerApplication;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\PriceHistory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Direct seller edits (no update requests): change log, grouped admin
 * notifications, price-decrease → discount rule, 30-day reference price, revert.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Seller/ProductChangesTest.php
 */
class ProductChangesTest extends TestCase
{
    use DatabaseTransactions;

    private User $seller;
    private User $admin;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::flush();
        PriceHistory::flush();
        $this->app->instance(\App\Services\ProductTranslator::class, \Mockery::mock(\App\Services\ProductTranslator::class)->shouldIgnoreMissing());

        $this->seller = $this->makeUser('seller');
        $this->admin  = $this->makeUser('admin');
        SellerApplication::create([
            'user_id' => $this->seller->id, 'full_name' => 'Change Test', 'phone_number' => '20000000',
            'business_name' => 'Change Test Shop', 'business_category' => 'other', 'wilaya' => 'Tunis',
            'city' => 'Tunis', 'status' => 'approved', 'plan' => 'free',
        ]);
        $s = Str::random(6);
        $this->category = Category::create(['name' => "Cat $s", 'name_ar' => "Cat $s", 'name_fr' => "Cat $s", 'slug' => "cat-$s", 'is_active' => true]);
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => ucfirst($role) . ' ' . Str::random(5), 'email' => $role . '_' . Str::random(10) . '@test.local',
            'password' => bcrypt('secret-password'), 'role' => $role, 'is_active' => true,
        ]);
    }

    private function liveProduct(float $price = 100, int $stock = 10): Product
    {
        $name = 'Live Product ' . Str::random(6);
        $p = Product::create([
            'seller_id' => $this->seller->id, 'category_id' => $this->category->id, 'name' => $name,
            'slug' => Str::slug($name), 'price' => $price, 'stock' => $stock, 'is_approved' => true, 'is_active' => true,
        ]);
        $path = 'products/' . Str::random(10) . '.jpg';
        Storage::disk('public')->put($path, 'x');
        ProductImage::create(['product_id' => $p->id, 'image_path' => $path, 'order' => 0, 'is_primary' => true]);
        return $p;
    }

    /** Pretend the product has had this price since $daysAgo. */
    private function backdateHistory(Product $p, int $daysAgo): void
    {
        ProductPriceHistory::where('product_id', $p->id)->update(['changed_at' => now()->subDays($daysAgo)]);
        PriceHistory::flush();
    }

    private function edit(Product $p, array $data)
    {
        Sanctum::actingAs($this->seller);
        return $this->postJson("/api/seller/products/{$p->id}", $data);
    }

    private function adminNotifications()
    {
        return DB::table('notifications')->where('notifiable_id', $this->admin->id)
            ->where('type', \App\Notifications\ProductChangedNotification::class);
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function test_update_request_routes_are_gone(): void
    {
        $p = $this->liveProduct();
        Sanctum::actingAs($this->seller);
        $this->postJson("/api/seller/products/{$p->id}/request-update", ['stock' => 3])->assertNotFound();
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/product-update-requests')->assertNotFound();
    }

    public function test_stock_edit_is_saved_logged_and_not_notified(): void
    {
        $p = $this->liveProduct();
        $this->backdateHistory($p, 60);

        $this->edit($p, ['stock' => 42])->assertOk();

        $p->refresh();
        $this->assertSame(42, $p->stock);
        $this->assertTrue($p->is_approved, 'stays live');

        $set = ProductChangeSet::where('product_id', $p->id)->sole();
        $this->assertTrue($set->stock_only);
        $this->assertFalse($set->notified);
        $this->assertSame(['old' => 10, 'new' => 42], ['old' => $set->items[0]->old_value, 'new' => $set->items[0]->new_value]);
        $this->assertSame(0, $this->adminNotifications()->count());
    }

    public function test_name_and_images_edit_sends_one_sensitive_notification(): void
    {
        $p = $this->liveProduct();
        $this->backdateHistory($p, 60);

        $this->edit($p, [
            'name'   => 'Renamed Product',
            'stock'  => 7,
            'images' => [UploadedFile::fake()->createWithContent('a.png', 'x'), UploadedFile::fake()->createWithContent('b.png', 'y')],
        ])->assertOk();

        $this->assertSame('Renamed Product', $p->fresh()->getRawOriginal('name'));

        $set = ProductChangeSet::where('product_id', $p->id)->sole();
        $this->assertTrue($set->is_sensitive);
        $this->assertEqualsCanonicalizing(['name_changed', 'images_changed'], $set->sensitive_reasons);
        $this->assertStringContainsString('name', $set->summary);
        $this->assertStringContainsString('stock', $set->summary);
        $this->assertStringContainsString('2 images', $set->summary);

        $this->assertSame(1, $this->adminNotifications()->count(), 'one notification per save');
        $data = json_decode($this->adminNotifications()->value('data'), true);
        $this->assertTrue($data['sensitive']);
        $this->assertSame('/product-changes?set=' . $set->id, $data['link']);
    }

    public function test_direct_price_decrease_is_rejected(): void
    {
        $p = $this->liveProduct(100);
        $this->backdateHistory($p, 60);

        $this->edit($p, ['price' => 80])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PRICE_DECREASE_REQUIRES_DISCOUNT')
            ->assertJsonPath('data.product.reference_price', 100)
            ->assertJsonValidationErrors('price');

        $this->assertSame('100.000', (string) $p->fresh()->price);
        $this->assertSame(0, ProductChangeSet::where('product_id', $p->id)->count());

        // Price increase is fine and flagged when > 50%
        $this->edit($p, ['price' => 160])->assertOk();
        $this->assertTrue(ProductChangeSet::where('product_id', $p->id)->sole()->is_sensitive);
    }

    public function test_variant_price_decrease_is_rejected_per_variant(): void
    {
        $p = $this->liveProduct(100);
        $v = ProductVariant::create(['product_id' => $p->id, 'stock' => 5, 'price_override' => 120, 'is_active' => true]);
        $this->backdateHistory($p, 60);

        $this->edit($p, ['variants' => [['id' => $v->id, 'option_ids' => [], 'stock' => 5, 'price_override' => 90, 'is_active' => true]]])
            ->assertStatus(422)
            ->assertJsonPath('data.variants.0.id', $v->id)
            ->assertJsonPath('data.variants.0.reference_price', 120);
        $this->assertSame('120.000', (string) $v->fresh()->price_override);
    }

    public function test_undoing_a_recent_increase_is_allowed(): void
    {
        $p = $this->liveProduct(100);
        $this->backdateHistory($p, 60);

        $this->edit($p, ['price' => 150])->assertOk();   // oops, typo
        $this->edit($p, ['price' => 100])->assertOk();   // back to the 30-day low: fine
        $this->edit($p, ['price' => 99])->assertStatus(422);
    }

    public function test_raised_then_discounted_uses_the_30_day_lowest_price(): void
    {
        $p = $this->liveProduct(100);
        $this->backdateHistory($p, 60);
        $this->edit($p, ['price' => 150])->assertOk();

        $promo = Promotion::create([
            'seller_id' => $this->seller->id, 'name' => 'Fake sale', 'type' => 'discount', 'discount_type' => 'percentage',
            'discount_value' => 20, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(3), 'status' => 'active', 'priority' => 5,
        ]);
        $promo->products()->attach($p->id);
        Cache::flush();

        $page = $this->getJson('/api/products/' . $p->fresh()->slug)->assertOk()->json('data');
        $this->assertEquals(150, $page['price']);
        $this->assertEquals(100, $page['original_price'], 'crossed-out price = lowest of the last 30 days');
        $this->assertEquals(80, $page['effective_price'], '20% off 100, not off 150');

        // Cart charges the same price
        $customer = $this->makeUser('client');
        Cart::create(['user_id' => $customer->id, 'product_id' => $p->id, 'quantity' => 1]);
        Sanctum::actingAs($customer);
        $cart = $this->getJson('/api/cart')->assertOk()->json();
        $this->assertStringContainsString('"price":80', json_encode($cart));
    }

    public function test_variant_reference_price_on_product_page(): void
    {
        $p = $this->liveProduct(100);
        $v = ProductVariant::create(['product_id' => $p->id, 'stock' => 5, 'price_override' => 120, 'is_active' => true]);
        $this->backdateHistory($p, 60);
        $this->edit($p, ['variants' => [['id' => $v->id, 'option_ids' => [], 'stock' => 5, 'price_override' => 200, 'is_active' => true]]])->assertOk();

        $promo = Promotion::create([
            'seller_id' => $this->seller->id, 'name' => 'Sale', 'type' => 'discount', 'discount_type' => 'percentage',
            'discount_value' => 10, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(3), 'status' => 'active', 'priority' => 5,
        ]);
        $promo->products()->attach($p->id);
        Cache::flush();

        $pricing = app(\App\Services\PromotionService::class)->getEffectivePrice($p->fresh(), 200.0, $v->id);
        $this->assertEquals(120, $pricing['original_price']);
        $this->assertEquals(108, $pricing['effective_price']);
    }

    public function test_plan_without_promotions_can_lower_price_directly(): void
    {
        $plan = SubscriptionPlan::create([
            'slug' => 'nopromo-' . Str::random(4), 'name' => 'No promo', 'price_monthly' => 0,
            'features' => ['promotions' => false],
        ]);
        SellerApplication::where('user_id', $this->seller->id)->update(['plan' => $plan->slug]);
        (new \ReflectionProperty(SubscriptionPlan::class, 'cache'))->setValue(null, []);

        $p = $this->liveProduct(100);
        $this->backdateHistory($p, 60);
        $this->edit($p, ['price' => 80])->assertOk();
        $this->assertSame('80.000', (string) $p->fresh()->price);
        (new \ReflectionProperty(SubscriptionPlan::class, 'cache'))->setValue(null, []);
    }

    public function test_pending_products_are_not_price_restricted(): void
    {
        $p = $this->liveProduct(100);
        $p->update(['is_approved' => false]);
        $this->edit($p, ['price' => 50])->assertOk();
    }

    public function test_admin_reverts_a_change_and_detects_conflicts(): void
    {
        $p = $this->liveProduct(100);
        $this->backdateHistory($p, 60);
        $this->edit($p, ['name' => 'Spammy NAME!!!', 'price' => 180])->assertOk();
        $set = ProductChangeSet::where('product_id', $p->id)->sole();

        Sanctum::actingAs($this->admin);
        $list = $this->getJson('/api/admin/product-changes?sensitive=1')->assertOk()->json('data.data');
        $row  = collect($list)->firstWhere('id', $set->id);
        $this->assertNotNull($row);
        $price = collect($row['items'])->firstWhere('field', 'price');
        $this->assertSame('100.000 TND', $price['old_display']);
        $this->assertSame('180.000 TND', $price['new_display']);

        // Seller changes the name again → reverting the name conflicts
        $this->edit($p, ['name' => 'Another name'])->assertOk();
        Sanctum::actingAs($this->admin);
        $nameItem = $set->items()->where('field', 'name')->first();
        $this->postJson("/api/admin/product-changes/{$set->id}/revert", ['item_ids' => [$nameItem->id]])
            ->assertStatus(409);

        // Price reverts cleanly
        $priceItem = $set->items()->where('field', 'price')->first();
        $this->postJson("/api/admin/product-changes/{$set->id}/revert", ['item_ids' => [$priceItem->id]])->assertOk();
        $this->assertSame('100.000', (string) $p->fresh()->price);
        $this->assertNotNull($priceItem->fresh()->reverted_at);

        // "Revert all" (name + the slug derived from it), overwriting the later edit
        $this->postJson("/api/admin/product-changes/{$set->id}/revert", ['force' => true])->assertOk();
        $fresh = $p->fresh();
        $this->assertSame($p->getRawOriginal('name'), $fresh->getRawOriginal('name'));
        $this->assertSame($p->slug, $fresh->slug);
        $this->assertNotNull($set->fresh()->reverted_at, 'all revertible items reverted');

        // Reverts are logged but never notified
        $reverts = ProductChangeSet::where('product_id', $p->id)->where('source', 'admin_revert')->get();
        $this->assertCount(2, $reverts);
        $this->assertFalse($reverts->contains('notified', true));
    }
}
