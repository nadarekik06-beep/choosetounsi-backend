<?php

namespace Tests\Feature\Discounts;

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\User;
use App\Services\PriceHistory;
use App\Support\PricingCache;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A price change followed by a promotion (or a promotion already running when
 * the price changes) is always priced from the CURRENT price: product card,
 * product page, cart, checkout, order lines, seller order, Intigo COD, commission
 * and payout. Past orders keep the prices they were placed at.
 *
 * Commission (pinned in DiscountTestHelpers): ≤ 100 DT → 15 %, 100.01-200 DT → 12 %;
 * the Black plan (needed for promotions) takes 6 points off: 100.01-200 DT → 6 %.
 * Bracket from the original (pre-discount) unit price, rate on the final price.
 * Delivery: client fee 8.000.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Discounts/PriceChangePromotionTest.php
 */
class PriceChangePromotionTest extends TestCase
{
    use DatabaseTransactions, DiscountTestHelpers;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDiscounts();
        $this->app->instance(\App\Services\ProductTranslator::class, \Mockery::mock(\App\Services\ProductTranslator::class)->shouldIgnoreMissing());
        $s = Str::random(6);
        $this->category = Category::create(['name' => "PC Cat $s", 'name_ar' => "PC Cat $s", 'name_fr' => "PC Cat $s", 'slug' => "pc-cat-$s", 'is_active' => true]);
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

    /** A live product with a category and a cover image (what both edit forms need). */
    private function liveProduct(User $seller, float $price, bool $approved = true): Product
    {
        $name = 'PC Product ' . Str::random(8);
        $p = Product::create([
            'seller_id' => $seller->id, 'category_id' => $this->category->id, 'name' => $name, 'slug' => Str::slug($name),
            'price' => $price, 'stock' => 50, 'is_approved' => $approved, 'is_active' => true,
        ]);
        $path = 'products/' . Str::random(10) . '.jpg';
        Storage::disk('public')->put($path, 'x');
        ProductImage::create(['product_id' => $p->id, 'image_path' => $path, 'order' => 0, 'is_primary' => true]);
        PriceHistory::flush();
        return $p;
    }

    /** Seller product form (direct edit, no update request). */
    private function sellerSetsPrice(User $seller, Product $product, float $price): void
    {
        $this->as($seller)->postJson("/api/seller/products/{$product->id}", ['price' => $price])->assertOk();
        PriceHistory::flush();
    }

    /** Admin product editor: load the document, change the price, save (optionally approve). */
    private function adminSetsPrice(Product $product, float $price, bool $approve = false): \Illuminate\Testing\TestResponse
    {
        $payload = $this->as($this->admin)->getJson("/api/admin/products/{$product->id}/editor")->assertOk()->json('data');
        $p   = $payload['product'];
        $doc = array_intersect_key($p, array_flip([
            'name', 'slug', 'sku', 'description', 'short_description', 'stock', 'category_id', 'subcategory_id',
            'is_active', 'is_pack', 'free_delivery', 'occasions', 'pack_quantity', 'pack_contents', 'admin_note',
        ])) + [
            'loaded_updated_at' => $p['updated_at'],
            'price'      => (string) $price,
            'attributes' => (array) $payload['attributes'],
            'variants'   => array_map(fn($v) => [
                'id' => $v['id'], 'key' => (string) $v['id'], 'option_ids' => $v['option_ids'], 'stock' => $v['stock'],
                'price_override' => $v['price_override'], 'sku' => $v['sku'], 'is_active' => $v['is_active'],
            ], $payload['variants']),
            'images' => [
                'gallery'      => array_map(fn($i) => ['id' => $i['id']], $payload['images']['gallery']),
                'color_groups' => array_map(fn($g) => [
                    'color_option_ids' => $g['color_option_ids'],
                    'items'            => array_map(fn($i) => ['id' => $i['id']], $g['images']),
                ], $payload['images']['color_groups']),
            ],
        ];
        if ($approve) $doc['approve'] = true;
        $res = $this->as($this->admin)->post("/api/admin/products/{$product->id}/editor", ['data' => json_encode($doc)], ['Accept' => 'application/json']);
        PriceHistory::flush();
        return $res;
    }

    private function sellerPromotion(User $seller, array $productIds, string $discountType, float $value): \Illuminate\Testing\TestResponse
    {
        return $this->as($seller)->postJson('/api/seller/promotions', [
            'name' => 'Seller promo ' . Str::random(4), 'type' => 'discount', 'discount_type' => $discountType, 'discount_value' => $value,
            'starts_at' => now()->setTimezone('Africa/Tunis')->format('Y-m-d\TH:i'),
            'ends_at'   => now()->addDays(5)->setTimezone('Africa/Tunis')->format('Y-m-d\TH:i'),
            'product_ids' => $productIds,
        ]);
    }

    private function adminPromotion(array $productIds, string $discountType, float $value): \Illuminate\Testing\TestResponse
    {
        return $this->as($this->admin)->postJson('/api/admin/promotions', [
            'name' => 'Admin promo ' . Str::random(4), 'type' => 'discount', 'discount_type' => $discountType, 'discount_value' => $value,
            'starts_at' => now()->subMinute()->toDateTimeString(), 'ends_at' => now()->addDays(7)->toDateTimeString(),
            'product_ids' => $productIds,
        ]);
    }

    /** Card (list endpoint), product page and /deals show final / original / -X%. */
    private function assertShownEverywhere(Product $product, float $final, float $original, int $percent): void
    {
        $detail = $this->getJson("/api/products/{$product->slug}")->assertOk()->json('data');
        $this->assertMoney($final, $detail['final_price'], 'product page final');
        $this->assertMoney($original, $detail['original_price'], 'product page crossed-out');
        $this->assertSame($percent, $detail['discount_percent'], 'product page badge');

        $card = collect($this->getJson("/api/products?seller_id={$product->seller_id}")->assertOk()->json('data.data')
            ?? $this->getJson("/api/products?seller_id={$product->seller_id}")->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($card, 'product card listed');
        $this->assertMoney($final, $card['final_price'], 'card final');
        $this->assertMoney($original, $card['original_price'], 'card crossed-out');
        $this->assertSame($percent, $card['discount_percent'], 'card badge');

        $deal = collect($this->getJson('/api/deals')->assertOk()->json('data.products'))->firstWhere('id', $product->id);
        $this->assertNotNull($deal, '/deals lists it');
        $this->assertMoney($final, $deal['final_price'], '/deals final (cache rebuilt after the change)');
    }

    /** Cart, quote, order line, seller order, Intigo COD, commission, payout. */
    private function assertChargedEverywhere(Product $product, User $seller, float $final, float $original, array $parcel): array
    {
        $this->addToCart($product);
        $cart = $this->as($this->customer)->getJson('/api/cart')->assertOk()->json('data.items');
        $item = collect($cart)->firstWhere('product_id', $product->id);
        $this->assertMoney($final, $item['price'], 'cart price');
        $this->assertMoney($original, $item['original_price'], 'cart crossed-out');

        $quoteLine = collect($this->quote()['parcels'][0]['lines'])->firstWhere('product_id', $product->id);
        $this->assertMoney($final, $quoteLine['unit_price'], 'checkout unit price');
        $this->assertMoney($original, $quoteLine['original_unit_price'], 'checkout original price');

        $res  = $this->place();
        $line = $this->line($res['order_id'], $product);
        $this->assertMoney($final, $line->unit_price, 'order line unit price');
        $this->assertMoney($original, $line->original_unit_price, 'order line original price');
        $this->assertMoney($original, $line->commission_tier_price, 'commission bracket from the current original price');

        $so = $this->parcel($res['order_id'], $seller);
        $this->assertParcel($so, $parcel);
        $this->assertMoney($parcel['cod'], $this->intigoPrice($so), 'Intigo COD');
        return $res;
    }

    // ── 1. Seller: price 100 → 150, then 20 % → 120 everywhere ────────────

    public function test_1_seller_raises_the_price_then_adds_20_percent(): void
    {
        $seller  = $this->seller('black');
        $product = $this->liveProduct($seller, 100);

        $this->sellerSetsPrice($seller, $product, 150);
        $this->assertMoney(150, $product->fresh()->price);

        // The seller form's preview (server values) already says 120
        $preview = $this->as($seller)->postJson('/api/seller/promotions/preview', [
            'product_ids' => [$product->id], 'discount_type' => 'percentage', 'discount_value' => 20,
        ])->assertOk()->json('data.0');
        $this->assertMoney(150, $preview['original_price'], 'preview crossed-out = new price');
        $this->assertMoney(120, $preview['final_price'], 'preview = 20 % off 150, not off the old 100');

        $this->sellerPromotion($seller, [$product->id], 'percentage', 20)->assertCreated();

        $this->assertShownEverywhere($product, 120, 150, 20);
        // 150 → 12 % bracket − 6 (Black) = 6 %, 6 % of 120 = 7.200; COD 120 + 8; payout 112.800
        $res = $this->assertChargedEverywhere($product, $seller, 120, 150,
            ['items' => '120', 'delivery_fee' => '8', 'cod' => '128', 'commission' => '7.2', 'payout' => '112.8']);
        $this->assertEquals(6, (float) $this->line($res['order_id'], $product)->commission_percentage);
    }

    // ── 2. Admin: pending product priced 100, admin sets 150 + approves, then 20 % ─

    public function test_2_admin_changes_the_price_of_a_pending_product_then_adds_20_percent(): void
    {
        $seller  = $this->seller('black');
        $product = $this->liveProduct($seller, 100, false);   // "add product" request waiting for review

        $this->adminSetsPrice($product, 150, true)->assertOk();
        $this->assertMoney(150, $product->fresh()->price);
        $this->assertTrue((bool) $product->fresh()->is_approved);

        $preview = $this->as($this->admin)->postJson('/api/admin/promotions/preview', [
            'product_ids' => [$product->id], 'discount_type' => 'percentage', 'discount_value' => 20,
        ])->assertOk()->json('data.0');
        $this->assertMoney(120, $preview['final_price'], 'admin preview');

        $this->adminPromotion([$product->id], 'percentage', 20)->assertCreated();

        $this->assertShownEverywhere($product, 120, 150, 20);
        $this->assertChargedEverywhere($product, $seller, 120, 150,
            ['items' => '120', 'delivery_fee' => '8', 'cod' => '128', 'commission' => '7.2', 'payout' => '112.8']);

        // Same behaviour as the seller: an already-approved product re-priced by the admin
        $other = $this->liveProduct($seller, 100);
        $this->adminSetsPrice($other, 150)->assertOk();
        $this->adminPromotion([$other->id], 'percentage', 20)->assertCreated();
        $this->assertShownEverywhere($other, 120, 150, 20);
    }

    // ── 3. Variants: one variant's price changed, then a promotion ─────────

    public function test_3_variant_price_change_then_promotion_uses_the_variant_price(): void
    {
        $seller  = $this->seller('black');
        $product = $this->liveProduct($seller, 100);
        $s       = Str::random(5);
        $size    = Attribute::create(['name' => "Size $s", 'name_ar' => "Size $s", 'name_fr' => "Taille $s", 'slug' => "size-$s", 'type' => 'select']);
        $optS    = AttributeOption::create(['attribute_id' => $size->id, 'value' => 'S', 'value_ar' => 'S', 'value_fr' => 'S']);
        $optM    = AttributeOption::create(['attribute_id' => $size->id, 'value' => 'M', 'value_ar' => 'M', 'value_fr' => 'M']);
        $small   = ProductVariant::create(['product_id' => $product->id, 'stock' => 10, 'is_active' => true, 'price_override' => 80]);
        $medium  = ProductVariant::create(['product_id' => $product->id, 'stock' => 10, 'is_active' => true]);
        $small->attributeOptions()->sync([$optS->id]);
        $medium->attributeOptions()->sync([$optM->id]);
        PriceHistory::flush();

        // Seller form: S goes 80 → 120, M keeps following the base price (100)
        $this->as($seller)->postJson("/api/seller/products/{$product->id}", ['variants' => [
            ['id' => $small->id,  'option_ids' => [$optS->id], 'stock' => 10, 'price_override' => 120, 'is_active' => true],
            ['id' => $medium->id, 'option_ids' => [$optM->id], 'stock' => 10, 'price_override' => '',  'is_active' => true],
        ]])->assertOk();
        PriceHistory::flush();
        $this->assertMoney(120, $small->fresh()->price_override);

        $this->sellerPromotion($seller, [$product->id], 'percentage', 25)->assertCreated();

        $variants = collect($this->getJson("/api/products/{$product->slug}")->assertOk()->json('data.variants'))->keyBy('id');
        $this->assertMoney(90, $variants[$small->id]['final_price'], 'S: 25 % off its new 120, not off the old 80 (60)');
        $this->assertMoney(120, $variants[$small->id]['original_price']);
        $this->assertSame(25, $variants[$small->id]['discount_percent']);
        $this->assertMoney(75, $variants[$medium->id]['final_price'], 'M: 25 % off the base 100');

        $this->as($this->customer)->postJson('/api/cart', ['product_id' => $product->id, 'variant_id' => $small->id, 'quantity' => 1])->assertOk();
        $item = collect($this->as($this->customer)->getJson('/api/cart')->json('data.items'))->firstWhere('variant_id', $small->id);
        $this->assertMoney(90, $item['price'], 'cart');

        $res  = $this->place();
        $line = DB::table('order_items')->where('order_id', $res['order_id'])->where('variant_id', $small->id)->first();
        $this->assertMoney(90, $line->unit_price);
        $this->assertMoney(120, $line->original_unit_price);
        $so = $this->parcel($res['order_id'], $seller);
        // 120 → 6 % (Black): 6 % of 90 = 5.400
        $this->assertParcel($so, ['items' => '90', 'cod' => '98', 'commission' => '5.4', 'payout' => '84.6']);
        $this->assertMoney(98, $this->intigoPrice($so));
    }

    // ── 4. Promotion already running at 20 % on 100, then price → 150 ──────

    public function test_4_running_promotion_follows_a_price_change(): void
    {
        $seller  = $this->seller('black');
        $product = $this->liveProduct($seller, 100);
        $this->sellerPromotion($seller, [$product->id], 'percentage', 20)->assertCreated();
        $this->assertShownEverywhere($product, 80, 100, 20);   // also warms the /deals cache

        $this->sellerSetsPrice($seller, $product, 150);

        $this->assertShownEverywhere($product, 120, 150, 20);
        $this->assertChargedEverywhere($product, $seller, 120, 150,
            ['items' => '120', 'cod' => '128', 'commission' => '7.2', 'payout' => '112.8']);
    }

    // ── 5. Fixed amount ≥ the new price → refused with a clear message ─────

    public function test_5_fixed_amount_not_below_the_current_price_is_refused(): void
    {
        $seller  = $this->seller('black');
        $product = $this->liveProduct($seller, 100);
        $this->sellerSetsPrice($seller, $product, 150);
        $this->adminSetsPrice($product, 50)->assertOk();   // the admin lowers it to 50

        $msg = $this->sellerPromotion($seller, [$product->id], 'fixed', 60)->assertStatus(422)->json('errors.discount_value.0');
        $this->assertStringContainsString('lower than the current price', $msg);
        $this->assertStringContainsString('(50.000 DT)', $msg, 'names the current price');

        $msg = $this->adminPromotion([$product->id], 'fixed', 50)->assertStatus(422)->json('errors.discount_value.0');
        $this->assertStringContainsString('(50.000 DT)', $msg, 'admin gets the same rule and message');
        $this->assertSame(0, Promotion::whereHas('products', fn ($q) => $q->where('products.id', $product->id))->count());

        // A fixed amount below the current price is fine, and is taken off the CURRENT price
        $this->adminSetsPrice($product, 150)->assertOk();
        $this->sellerPromotion($seller, [$product->id], 'fixed', 120)->assertCreated();   // the old 100 base would have refused this
        $this->assertShownEverywhere($product, 30, 150, 80);

        // Lowering the price to (or under) a running fixed amount is refused, seller and admin alike
        $msg = $this->adminSetsPrice($product, 110)->assertStatus(422)->json('errors.price.0');
        $this->assertStringContainsString('120.000 DT', $msg);
        $this->assertStringContainsString('(110.000 DT)', $msg);
        $this->as($this->admin)->putJson("/api/admin/products/{$product->id}", ['price' => 100])->assertStatus(422);
        $this->assertMoney(150, $product->fresh()->price, 'price untouched');
    }

    // ── 6. Past orders never change ───────────────────────────────────────

    public function test_6_past_orders_keep_their_prices(): void
    {
        $seller  = $this->seller('black');
        $product = $this->liveProduct($seller, 100);
        $this->sellerSetsPrice($seller, $product, 150);
        $promo = $this->promo([$product], 'discount', 20);
        $res   = $this->assertChargedEverywhere($product, $seller, 120, 150,
            ['items' => '120', 'cod' => '128', 'commission' => '7.2', 'payout' => '112.8']);

        $before = [
            'line' => (array) $this->line($res['order_id'], $product),
            'so'   => (array) $this->parcel($res['order_id'], $seller),
            'order'=> (array) DB::table('orders')->where('id', $res['order_id'])->first(),
        ];

        // Price and promotion change afterwards, and the reprice command runs
        $this->sellerSetsPrice($seller, $product, 200);
        $promo->update(['discount_value' => 50]);
        $this->artisan('promotions:reprice', ['--apply' => true])->assertExitCode(0);

        $this->assertEquals($before['line'], (array) $this->line($res['order_id'], $product), 'order line unchanged');
        $this->assertEquals($before['so'], (array) $this->parcel($res['order_id'], $seller), 'seller order unchanged');
        $this->assertEquals($before['order'], (array) DB::table('orders')->where('id', $res['order_id'])->first(), 'order unchanged');
        $this->assertMoney(128, $this->intigoPrice((object) $before['so']), 'Intigo still collects 128');
        // …while the storefront shows the new price
        $this->assertShownEverywhere($product, 100, 200, 50);
    }

    // ── Reprice command ───────────────────────────────────────────────────

    public function test_reprice_command_refreshes_outdated_cart_snapshots_only_with_apply(): void
    {
        $seller  = $this->seller('black');
        $product = $this->liveProduct($seller, 150);
        $promo   = $this->promo([$product], 'discount', 20);
        $row     = Cart::create([
            'user_id' => $this->customer->id, 'product_id' => $product->id, 'quantity' => 1,
            'promotion_id_snapshot' => $promo->id, 'promo_price_snapshot' => 80,   // shown under the old rule
        ]);
        $version = PricingCache::version();

        $this->artisan('promotions:reprice')->expectsOutput('1 cart row(s) with an outdated promo price snapshot.')->assertExitCode(0);
        $this->assertMoney(80, $row->fresh()->promo_price_snapshot, 'dry run writes nothing');

        $this->artisan('promotions:reprice', ['--apply' => true])->assertExitCode(0);
        $this->assertMoney(120, $row->fresh()->promo_price_snapshot);
        $this->assertGreaterThan($version, PricingCache::version(), 'display caches rebuilt');
    }
}
