<?php

namespace Tests\Feature\Shop;

use App\Models\Coupon;
use App\Models\Pack;
use App\Models\PackItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\SellerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Recommendation\MakesCatalog;
use Tests\TestCase;

/**
 * /deals data: GET /api/deals (promotion + coupon products, packs, shopper interests).
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Shop/DealsCatalogTest.php
 */
class DealsCatalogTest extends TestCase
{
    use DatabaseTransactions, MakesCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTranslator();
        Cache::flush();
    }

    private function promotion(User $seller, Product $product, array $attrs = []): Promotion
    {
        $promo = Promotion::create(array_merge([
            'seller_id' => $seller->id, 'name' => 'Test promo ' . Str::random(4), 'type' => 'discount',
            'discount_type' => 'percentage', 'discount_value' => 20, 'status' => 'active', 'priority' => 5,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(3),
        ], $attrs));
        $promo->products()->attach($product->id);
        return $promo;
    }

    private function deals(): array
    {
        return $this->getJson('/api/deals')->assertOk()->json('data');
    }

    public function test_promotion_products_come_flash_first_with_category(): void
    {
        $seller = $this->makeUser('seller');
        $category = $this->makeCategory();
        $discounted = $this->makeProduct($seller, $category);
        $flashed    = $this->makeProduct($seller, $category);
        $this->promotion($seller, $discounted, ['ends_at' => now()->addHour()]);
        $this->promotion($seller, $flashed, ['type' => 'flash_sale', 'flash_stock' => 10]);

        $rows = collect($this->deals()['products']);
        $ids  = $rows->pluck('id')->all();

        $this->assertLessThan(array_search($discounted->id, $ids), array_search($flashed->id, $ids));
        $flash = $rows->firstWhere('id', $flashed->id);
        $this->assertTrue($flash['deal']['is_flash_sale']);
        $this->assertSame(10, $flash['deal']['flash_stock']);
        $this->assertSame($category->id, $flash['category']['id']);
        $this->assertEquals(40, $flash['final_price']);
        $this->assertNull($flash['coupon']);
    }

    public function test_coupon_products_say_a_coupon_exists_without_the_code(): void
    {
        $seller  = $this->makeUser('seller');
        $product = $this->makeProduct($seller, $this->makeCategory());
        $used    = $this->makeProduct($seller, $this->makeCategory());
        $coupon  = Coupon::create(['seller_id' => $seller->id, 'code' => 'SECRET' . Str::random(4),
            'discount_type' => 'percentage', 'discount_value' => 15, 'is_active' => true]);
        $coupon->products()->attach($product->id);
        $spent = Coupon::create(['seller_id' => $seller->id, 'code' => 'SPENT' . Str::random(4),
            'discount_type' => 'fixed', 'discount_value' => 5, 'is_active' => true, 'usage_limit' => 1, 'usage_count' => 1]);
        $spent->products()->attach($used->id);

        $rows = collect($this->deals()['products']);
        $row  = $rows->firstWhere('id', $product->id);

        $this->assertNotNull($row);
        $this->assertNull($row['deal']);
        $this->assertEquals(15, $row['coupon']['discount_value']);
        $this->assertStringNotContainsString('SECRET', json_encode($row));
        $this->assertNull($rows->firstWhere('id', $used->id));
    }

    public function test_packs_carry_savings_percent_and_shop(): void
    {
        $seller  = $this->makeUser('seller');
        $product = $this->makeProduct($seller, $this->makeCategory());
        $pack = Pack::create(['seller_id' => $seller->id, 'name' => 'Pack ' . Str::random(4),
            'pack_price' => 75, 'original_price' => 100, 'is_active' => true, 'is_approved' => true]);
        PackItem::create(['pack_id' => $pack->id, 'product_id' => $product->id, 'quantity' => 2, 'order' => 0]);

        $row = collect($this->deals()['packs'])->firstWhere('id', $pack->id);

        $this->assertSame(25, $row['discount_percent']);
        $this->assertSame(1, $row['items_count']);
        $this->assertSame($product->category_id, $row['category_id']);
        $this->assertSame($seller->id, $row['seller']['id']);
    }

    public function test_cards_carry_the_shop_photo(): void
    {
        $seller  = $this->makeUser('seller');
        SellerApplication::create([
            'user_id' => $seller->id, 'full_name' => 'Shop Owner', 'phone_number' => '20000000',
            'business_name' => 'Photo Shop', 'business_category' => 'other', 'wilaya' => 'Sfax', 'city' => 'Sfax',
            'status' => 'approved', 'plan' => 'free', 'profile_picture' => 'seller-applications/profiles/shop.png',
        ]);
        $product = $this->makeProduct($seller, $this->makeCategory());
        $this->promotion($seller, $product);

        $row = collect($this->deals()['products'])->firstWhere('id', $product->id);

        $this->assertSame('Photo Shop', $row['seller']['business_name']);
        $this->assertStringEndsWith('/storage/seller-applications/profiles/shop.png', $row['seller']['avatar']);
    }

    public function test_interests_only_for_signed_in_shoppers(): void
    {
        $this->assertSame([], $this->deals()['interest_category_ids']);

        $user = $this->makeUser();
        $category = $this->makeCategory();
        $product  = $this->makeProduct($this->makeUser('seller'), $category);
        DB::table('user_interactions')->insert(['user_id' => $user->id, 'product_id' => $product->id,
            'category_id' => $category->id, 'event_type' => 'favorite_add', 'created_at' => now()]);
        Sanctum::actingAs($user);

        $this->assertContains($category->id, $this->deals()['interest_category_ids']);
    }
}
