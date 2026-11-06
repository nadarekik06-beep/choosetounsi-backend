<?php

namespace Tests\Feature\Promotions;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\SellerApplication;
use App\Models\SellerOrder;
use App\Models\User;
use App\Notifications\Sellers\AdminPromotionNotification;
use App\Services\CommissionService;
use App\Services\Intigo\ParcelPayload;
use App\Services\PriceHistory;
use App\Support\Millimes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin promotions: same tables and pricing as seller promotions, created from
 * the admin panel on any seller's products. The seller pays the discount
 * (owner's decision): commission and payout on the discounted price.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Promotions/AdminPromotionTest.php
 */
class AdminPromotionTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $seller;
    private User $seller2;
    private User $customer;
    private Category $category;
    private Product $robe;     // 100 DT, seller 1
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::flush();
        PriceHistory::flush();
        Notification::fake();
        $this->app->instance(\App\Services\ProductTranslator::class, \Mockery::mock(\App\Services\ProductTranslator::class)->shouldIgnoreMissing());
        Http::fake(['*' => Http::response([], 500)]);

        $this->token    = 'zq' . Str::lower(Str::random(6));
        $this->admin    = $this->makeUser('admin');
        $this->seller   = $this->makeSeller();
        $this->seller2  = $this->makeSeller();
        $this->customer = $this->makeUser('client');
        $s = Str::random(6);
        $this->category = Category::create(['name' => "AP Cat $s", 'name_ar' => "AP Cat $s", 'name_fr' => "AP Cat $s", 'slug' => "ap-cat-$s", 'is_active' => true]);
        $this->robe     = $this->makeProduct("Robe {$this->token} soirée", 100);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function makeUser(string $role): User
    {
        return $this->withCompleteProfile(User::create([
            'name' => 'AP ' . Str::random(5), 'email' => "ap_{$role}_" . Str::random(10) . '@test.local',
            'password' => bcrypt('secret-password'), 'role' => $role, 'is_active' => true,
        ]));
    }

    private function makeSeller(): User
    {
        $u = $this->makeUser('seller');
        SellerApplication::create([
            'user_id' => $u->id, 'full_name' => 'AP Seller', 'phone_number' => '20000000',
            'business_name' => 'AP Shop ' . Str::random(4), 'business_category' => 'other', 'wilaya' => 'Tunis',
            'city' => 'Tunis', 'status' => 'approved', 'plan' => 'black',
        ]);
        return $u;
    }

    private function makeProduct(string $name, float $price, ?User $seller = null, int $stock = 30): Product
    {
        $p = Product::create([
            'seller_id' => ($seller ?? $this->seller)->id, 'category_id' => $this->category->id, 'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(4)), 'price' => $price, 'stock' => $stock,
            'is_approved' => true, 'is_active' => true,
        ]);
        $path = 'products/' . Str::random(10) . '.jpg';
        Storage::disk('public')->put($path, 'x');
        ProductImage::create(['product_id' => $p->id, 'image_path' => $path, 'order' => 0, 'is_primary' => true]);
        return $p;
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken]);
    }

    private function adminPromo(array $productIds, array $attrs = []): \Illuminate\Testing\TestResponse
    {
        return $this->as($this->admin)->postJson('/api/admin/promotions', array_merge([
            'name' => 'Soldes Choose', 'type' => 'discount', 'discount_type' => 'percentage', 'discount_value' => 50,
            'starts_at' => now()->subMinute()->toDateTimeString(), 'ends_at' => now()->addDays(7)->toDateTimeString(),
            'product_ids' => $productIds,
        ], $attrs));
    }

    private function sellerPromo(Product $product, array $attrs = []): Promotion
    {
        $promo = Promotion::create(array_merge([
            'seller_id' => $product->seller_id, 'name' => 'Seller promo', 'type' => 'discount',
            'discount_type' => 'percentage', 'discount_value' => 20, 'status' => 'active', 'priority' => 5,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(10),
        ], $attrs));
        $promo->products()->attach($product->id);
        return $promo;
    }

    private function address(array $extra = []): array
    {
        return [
            'recipient_name' => 'Test Buyer', 'phone' => '22123456', 'wilaya' => 'Tunis',
            'delegation' => 'Bab Bhar', 'address' => '1 rue Test', 'postal_code' => '1000',
            'payment_method' => 'cod',
        ] + $extra;
    }

    private function addToCart(Product $product, ?ProductVariant $variant = null, int $qty = 1): array
    {
        return $this->as($this->customer)->postJson('/api/cart', array_filter([
            'product_id' => $product->id, 'variant_id' => $variant?->id, 'quantity' => $qty,
        ]))->assertOk()->json('data');
    }

    private function findIn(array $json, int $id): ?array
    {
        if (($json['id'] ?? null) === $id && array_key_exists('slug', $json) && array_key_exists('price', $json)) return $json;
        foreach ($json as $v) {
            if (is_array($v) && ($hit = $this->findIn($v, $id))) return $hit;
        }
        return null;
    }

    private function assertHalfPrice(?array $p, string $where): void
    {
        $this->assertNotNull($p, "{$where}: product missing");
        $this->assertEqualsWithDelta(50, (float) $p['final_price'], 0.0005, "{$where}: final_price");
        $this->assertEqualsWithDelta(100, (float) $p['original_price'], 0.0005, "{$where}: original_price");
        $this->assertSame(50, $p['discount_percent'], "{$where}: discount_percent");
        $this->assertSame('admin', $p['promotion']['source'] ?? null, "{$where}: promotion source");
    }

    // ── 1. Admin creates a 50% promotion → priced everywhere ──────────────

    public function test_1_admin_50_percent_promotion_shows_everywhere_and_notifies_the_seller(): void
    {
        $res = $this->adminPromo([$this->robe->id])->assertCreated()->json('data.0');
        $this->assertSame('admin', $res['source']);
        $this->assertSame('seller', $res['funded_by']);
        $this->assertSame('active', $res['display_status']);
        $this->assertSame($this->admin->id, $res['created_by_admin']['id']);
        $this->assertDatabaseHas('promotions', ['id' => $res['id'], 'seller_id' => $this->seller->id, 'created_by' => 'admin', 'created_by_admin_id' => $this->admin->id]);

        $id = $this->robe->id;
        foreach (['fr', 'en', 'ar'] as $lang) {
            $detail = $this->withHeaders(['Accept-Language' => $lang])->getJson("/api/products/{$this->robe->slug}")->assertOk()->json('data');
            $this->assertHalfPrice($detail, "detail ({$lang})");
        }
        $this->assertHalfPrice($this->findIn($this->getJson("/api/products?seller_id={$this->seller->id}")->assertOk()->json(), $id), 'seller store / list');
        $this->assertHalfPrice($this->findIn($this->getJson("/api/categories/{$this->category->slug}/products")->assertOk()->json(), $id), 'category');
        $this->assertHalfPrice($this->findIn($this->postJson('/api/search/text', ['query' => $this->token])->assertOk()->json(), $id), 'search');
        $cards = app(\App\Services\Recommendation\ProductCardPresenter::class)->present(Product::whereKey($id)->with('primaryImage')->get());
        $this->assertHalfPrice($cards[0], 'home feed card');
        $chat = collect(app(\App\Services\Chat\ProductRetriever::class)->search(['keywords' => [$this->token]])['products'])->firstWhere('id', $id);
        $this->assertNotNull($chat, 'AI chat finds it');
        $this->assertEqualsWithDelta(50, $chat['price'], 0.0005, 'AI chat price');
        $this->assertSame(50, $chat['discount_percent'], 'AI chat card -X%');

        $cart = $this->addToCart($this->robe, null, 2);
        $this->assertEqualsWithDelta(50, (float) $cart['items'][0]['price'], 0.0005);
        $this->assertEqualsWithDelta(100, (float) $cart['items'][0]['original_price'], 0.0005);
        $this->assertSame(50, $cart['items'][0]['discount_percent']);
        $this->assertEqualsWithDelta(100, (float) $cart['savings'], 0.0005, 'cart total savings');

        $quote = $this->as($this->customer)->postJson('/api/checkout/quote', ['payment_method' => 'cod'])->assertOk()->json('data');
        $line  = $quote['parcels'][0]['lines'][0];
        $this->assertEqualsWithDelta(100, $line['original_unit_price'], 0.0005);
        $this->assertEqualsWithDelta(50, $line['unit_price'], 0.0005);
        $this->assertEqualsWithDelta(100, $line['promo_discount'], 0.0005);
        $this->assertSame(50, $line['discount_percent']);
        $this->assertEqualsWithDelta(100, $quote['promo_savings'], 0.0005);

        // Seller: notified, sees it labelled and read-only
        Notification::assertSentTo($this->seller, AdminPromotionNotification::class, fn ($n) => $n->event === 'created');
        $mine = $this->as($this->seller)->getJson('/api/seller/promotions')->assertOk()->json('data.data.0');
        $this->assertTrue($mine['is_admin_promotion']);
        $this->assertTrue($mine['read_only']);
        $this->as($this->seller)->putJson("/api/seller/promotions/{$res['id']}", ['discount_value' => 5])->assertForbidden();
        $this->as($this->seller)->deleteJson("/api/seller/promotions/{$res['id']}")->assertForbidden();
        $this->assertSame('50.000', Promotion::find($res['id'])->getRawOriginal('discount_value'));
    }

    // ── 2. Checkout: order, items, seller order, commission, payout, COD ──

    public function test_2_checkout_stores_the_breakdown_and_every_amount_uses_the_final_price(): void
    {
        $this->adminPromo([$this->robe->id])->assertCreated();
        $this->addToCart($this->robe);

        // 50 + 8 delivery
        $res = $this->as($this->customer)->postJson('/api/checkout', $this->address(['expected_total' => 58]))->assertCreated()->json();
        $this->assertEqualsWithDelta(58, $res['total'], 0.0005);

        $item = DB::table('order_items')->where('order_id', $res['order_id'])->first();
        $this->assertEqualsWithDelta(50, (float) $item->unit_price, 0.0005);
        $this->assertEqualsWithDelta(50, (float) $item->price, 0.0005);
        $this->assertEqualsWithDelta(100, (float) $item->original_unit_price, 0.0005);
        $this->assertEqualsWithDelta(50, (float) $item->promo_discount_amount, 0.0005);
        $this->assertSame('admin', $item->promotion_source);
        $this->assertNotNull($item->promotion_id);
        $this->assertEqualsWithDelta(50, (float) $item->total, 0.0005);
        $this->assertEqualsWithDelta(0, (float) $item->discount_amount, 0.0005, 'coupon share stays 0');

        // Commission on the final price (seller pays the discount)
        $c = app(CommissionService::class)->calculateForSeller($this->seller->id, 50.0, 1);
        $this->assertSame($c['commission_amount_m'], Millimes::of($item->commission_amount));
        $this->assertSame(Millimes::of(50) - $c['commission_amount_m'], Millimes::of($item->seller_amount));

        $so = SellerOrder::where('order_id', $res['order_id'])->firstOrFail();
        $this->assertEqualsWithDelta(50, (float) $so->subtotal, 0.0005);
        $this->assertSame(Millimes::of($item->commission_amount), Millimes::of($so->commission_amount));
        $this->assertSame(Millimes::of($item->seller_amount), Millimes::of($so->seller_net_amount));
        $this->assertSame(Millimes::of(58), Millimes::of($so->cod_amount));
        $this->assertSame(Millimes::of(58), ParcelPayload::codMillimes($so), 'Intigo COD amount');
        $this->assertSame(Millimes::of(58), Millimes::of(Order::find($res['order_id'])->total_amount));
    }

    // ── 3. Multi-seller cart: one promoted, one normal ────────────────────

    public function test_3_multi_seller_cart_with_one_promoted_and_one_normal_product(): void
    {
        $other = $this->makeProduct("Sac {$this->token}", 60, $this->seller2);
        $this->adminPromo([$this->robe->id])->assertCreated();
        $this->addToCart($this->robe);
        $this->addToCart($other);

        // (50 + 8) + (60 + 8)
        $res = $this->as($this->customer)->postJson('/api/checkout', $this->address(['expected_total' => 126]))->assertCreated()->json();
        $this->assertSame(2, SellerOrder::where('order_id', $res['order_id'])->count());

        $promoted = DB::table('order_items')->where('order_id', $res['order_id'])->where('product_id', $this->robe->id)->first();
        $normal   = DB::table('order_items')->where('order_id', $res['order_id'])->where('product_id', $other->id)->first();
        $this->assertEqualsWithDelta(50, (float) $promoted->unit_price, 0.0005);
        $this->assertEqualsWithDelta(50, (float) $promoted->promo_discount_amount, 0.0005);
        $this->assertEqualsWithDelta(60, (float) $normal->unit_price, 0.0005);
        $this->assertEqualsWithDelta(60, (float) $normal->original_unit_price, 0.0005);
        $this->assertEqualsWithDelta(0, (float) $normal->promo_discount_amount, 0.0005);
        $this->assertNull($normal->promotion_id);
        $this->assertNull($normal->promotion_source);

        $soNormal = SellerOrder::where('order_id', $res['order_id'])->where('seller_id', $this->seller2->id)->first();
        $c = app(CommissionService::class)->calculateForSeller($this->seller2->id, 60.0, 1);
        $this->assertSame($c['commission_amount_m'], Millimes::of($soNormal->commission_amount));
        $this->assertSame(Millimes::of(68), Millimes::of($soNormal->cod_amount));
    }

    // ── 4. Variants ──────────────────────────────────────────────────────

    public function test_4_promotion_applies_to_each_variant_price(): void
    {
        $robe = $this->robe;
        $cheap = ProductVariant::create(['product_id' => $robe->id, 'stock' => 5, 'is_active' => true, 'price_override' => 80]);
        $base  = ProductVariant::create(['product_id' => $robe->id, 'stock' => 5, 'is_active' => true]);

        $preview = $this->as($this->admin)->postJson('/api/admin/promotions/preview', [
            'product_ids' => [$robe->id], 'discount_type' => 'percentage', 'discount_value' => 50,
        ])->assertOk()->json('data.0');
        $this->assertEqualsWithDelta(50, $preview['final_price'], 0.0005);
        $this->assertEqualsWithDelta(40, $preview['variants'][0]['final_price'], 0.0005);
        $this->assertSame(50, $preview['variants'][0]['discount_percent']);

        $this->adminPromo([$robe->id])->assertCreated();
        $this->addToCart($robe, $cheap);
        $this->addToCart($robe, $base);

        // (40 + 50) + 8 delivery (one seller, one parcel)
        $res = $this->as($this->customer)->postJson('/api/checkout', $this->address(['expected_total' => 98]))->assertCreated()->json();
        $v1 = DB::table('order_items')->where('order_id', $res['order_id'])->where('variant_id', $cheap->id)->first();
        $v2 = DB::table('order_items')->where('order_id', $res['order_id'])->where('variant_id', $base->id)->first();
        $this->assertEqualsWithDelta(40, (float) $v1->unit_price, 0.0005);
        $this->assertEqualsWithDelta(80, (float) $v1->original_unit_price, 0.0005);
        $this->assertEqualsWithDelta(50, (float) $v2->unit_price, 0.0005);
        $this->assertEqualsWithDelta(100, (float) $v2->original_unit_price, 0.0005);
        $this->assertEqualsWithDelta(8, (float) SellerOrder::where('order_id', $res['order_id'])->value('delivery_fee'), 0.0005, 'shipping not discounted');
    }

    // ── 5. Promotion ends while in the cart ──────────────────────────────

    public function test_5_promotion_that_ends_while_in_the_cart_charges_the_normal_price_and_says_so(): void
    {
        $promoId = $this->adminPromo([$this->robe->id])->assertCreated()->json('data.0.id');
        $this->addToCart($this->robe);

        Promotion::whereKey($promoId)->update(['ends_at' => now()->subSecond()]);

        $cart = $this->as($this->customer)->getJson('/api/cart')->assertOk()->json('data');
        $this->assertTrue($cart['items'][0]['promo_ended']);
        $this->assertEqualsWithDelta(50, $cart['items'][0]['promo_ended_price'], 0.0005);
        $this->assertEqualsWithDelta(100, (float) $cart['items'][0]['price'], 0.0005);
        $this->assertSame([$this->robe->name], $cart['promo_ended']);

        $quote = $this->as($this->customer)->postJson('/api/checkout/quote', ['payment_method' => 'cod'])->assertOk()->json('data');
        $this->assertTrue($quote['parcels'][0]['lines'][0]['promo_ended']);
        $this->assertEqualsWithDelta(108, $quote['total'], 0.0005);

        // The page still shows the promo total → refused, new total returned
        $this->as($this->customer)->postJson('/api/checkout', $this->address(['expected_total' => 58]))
            ->assertStatus(409)->assertJsonPath('code', 'price_changed')->assertJsonPath('data.total', 108);

        $res = $this->as($this->customer)->postJson('/api/checkout', $this->address(['expected_total' => 108]))->assertCreated()->json();
        $item = DB::table('order_items')->where('order_id', $res['order_id'])->first();
        $this->assertEqualsWithDelta(100, (float) $item->unit_price, 0.0005);
        $this->assertNull($item->promotion_id);
        $this->assertEqualsWithDelta(0, (float) $item->promo_discount_amount, 0.0005);
    }

    // ── 6. Conflict with an active seller promotion ──────────────────────

    public function test_6_conflict_with_a_seller_promotion_warns_then_replaces_never_stacks(): void
    {
        $sellerPromo = $this->sellerPromo($this->robe);

        $this->adminPromo([$this->robe->id])->assertStatus(409)
            ->assertJsonPath('code', 'promotion_conflict')
            ->assertJsonPath('conflicts.0.id', $sellerPromo->id)
            ->assertJsonPath('conflicts.0.source', 'seller');
        $this->assertSame(0, Promotion::byAdmin()->count(), 'nothing created without replace');

        $adminId = $this->adminPromo([$this->robe->id], ['replace_conflicts' => true])->assertCreated()->json('data.0.id');

        $this->assertSame('expired', $sellerPromo->fresh()->status, 'seller promo had only this product: ended');
        $this->assertFalse($sellerPromo->products()->where('products.id', $this->robe->id)->exists());
        $detail = $this->getJson("/api/products/{$this->robe->slug}")->json('data');
        $this->assertSame($adminId, $detail['promotion']['id']);
        $this->assertEqualsWithDelta(50, (float) $detail['final_price'], 0.0005);
        Notification::assertSentTo($this->seller, AdminPromotionNotification::class, fn ($n) => $n->event === 'replaced');

        // A seller can't add a promotion on top of the admin one either
        $this->as($this->seller)->postJson('/api/seller/promotions', [
            'name' => 'Mine', 'type' => 'flash_sale', 'discount_type' => 'percentage', 'discount_value' => 30,
            'starts_at' => now()->addHour()->toDateTimeString(), 'ends_at' => now()->addHours(5)->toDateTimeString(),
            'product_ids' => [$this->robe->id],
        ])->assertStatus(422)->assertJsonPath('code', 'promotion_conflict');
    }

    public function test_6b_replace_keeps_the_seller_promotion_on_its_other_products(): void
    {
        $other = $this->makeProduct("Jupe {$this->token}", 40);
        $sellerPromo = $this->sellerPromo($this->robe);
        $sellerPromo->products()->attach($other->id);

        $this->adminPromo([$this->robe->id], ['replace_conflicts' => true])->assertCreated();
        $this->assertSame('active', $sellerPromo->fresh()->status);
        $this->assertSame([$other->id], $sellerPromo->products()->pluck('products.id')->map(fn ($i) => (int) $i)->all());
        $this->assertEqualsWithDelta(32, (float) $this->getJson("/api/products/{$other->slug}")->json('data.final_price'), 0.0005);
    }

    // ── 7. Seller coupon + promoted product ──────────────────────────────

    public function test_7_seller_coupon_does_not_stack_with_an_admin_promotion(): void
    {
        $this->adminPromo([$this->robe->id])->assertCreated();
        $coupon = Coupon::create(['seller_id' => $this->seller->id, 'code' => 'AP' . Str::upper(Str::random(6)), 'discount_type' => 'fixed', 'discount_value' => 10, 'is_active' => true]);
        $coupon->products()->attach($this->robe->id);
        $this->addToCart($this->robe);

        $before = Order::count();
        $res = $this->as($this->customer)->postJson('/api/checkout', $this->address(['coupon_codes' => [$coupon->code]]));
        $this->assertContains($res->status(), [409, 422]);
        // Its only product is promoted: nothing left for the coupon (AUDIT M-5)
        $this->assertSame(__('messages.coupon.all_items_promoted'), $res->json('message'));
        $this->assertSame($before, Order::count());
    }

    // ── 8. Fake price from the frontend ──────────────────────────────────

    public function test_8_prices_sent_by_the_client_are_ignored_and_a_wrong_total_is_refused(): void
    {
        $this->adminPromo([$this->robe->id])->assertCreated();
        $this->addToCart($this->robe);

        $this->as($this->customer)->postJson('/api/checkout', $this->address(['expected_total' => 1]))
            ->assertStatus(409)->assertJsonPath('code', 'price_changed')->assertJsonPath('data.total', 58);

        // Forged price fields are not part of the contract: the server recomputes
        $res = $this->as($this->customer)->postJson('/api/checkout', $this->address([
            'price' => 1, 'unit_price' => 1, 'total' => 1, 'subtotal' => 1, 'final_price' => 1,
            'items' => [['product_id' => $this->robe->id, 'price' => 1, 'quantity' => 1]],
        ]))->assertCreated()->json();
        $this->assertEqualsWithDelta(58, $res['total'], 0.0005);
        $this->assertEqualsWithDelta(50, (float) DB::table('order_items')->where('order_id', $res['order_id'])->value('unit_price'), 0.0005);
    }

    // ── Admin validation, pause / resume / delete ────────────────────────

    public function test_admin_validation_rules(): void
    {
        $id = $this->robe->id;
        $this->adminPromo([$id], ['discount_value' => 0])->assertStatus(422);
        $this->adminPromo([$id], ['discount_value' => 100])->assertStatus(422);
        $this->adminPromo([$id], ['discount_type' => 'fixed', 'discount_value' => 100])->assertStatus(422)->assertJsonValidationErrors('discount_value');
        $this->adminPromo([$id], ['ends_at' => now()->subDay()->toDateTimeString()])->assertStatus(422);
        $this->adminPromo([999999999])->assertStatus(422);
        Product::whereKey($id)->update(['is_active' => false]);
        $this->adminPromo([$id])->assertStatus(422);
        Product::whereKey($id)->update(['is_active' => true]);

        // 99% and any duration are fine for the admin
        $this->adminPromo([$id], ['discount_value' => 99, 'ends_at' => now()->addDays(200)->toDateTimeString()])->assertCreated();
        // Not for sellers or clients
        $this->as($this->seller)->postJson('/api/admin/promotions', [])->assertForbidden();
        $this->as($this->customer)->getJson('/api/admin/promotions')->assertForbidden();
    }

    public function test_one_admin_promotion_per_seller_and_list_filters(): void
    {
        $other = $this->makeProduct("Sac {$this->token}", 60, $this->seller2);
        $created = $this->adminPromo([$this->robe->id, $other->id])->assertCreated()->json('data');
        $this->assertCount(2, $created);
        $this->assertEqualsCanonicalizing([$this->seller->id, $this->seller2->id], array_column(array_column($created, 'seller'), 'id'));
        $this->sellerPromo($this->makeProduct("Top {$this->token}", 30));

        $admin = $this->as($this->admin);
        $this->assertSame(2, $admin->getJson('/api/admin/promotions?source=admin&search=' . urlencode('Soldes Choose'))->json('data.total'));
        $this->assertSame(1, $admin->getJson("/api/admin/promotions?source=admin&seller_id={$this->seller2->id}")->json('data.total'));
        $this->assertGreaterThanOrEqual(1, $admin->getJson('/api/admin/promotions?source=seller&status=active')->json('data.total'));

        $found = collect($admin->getJson('/api/admin/promotions/products?search=' . $this->token)->assertOk()->json('data'))->firstWhere('id', $this->robe->id);
        $this->assertSame('admin', $found['current_promotion']['source']);
        $this->assertEqualsWithDelta(50, $found['final_price'], 0.0005);
    }

    public function test_pause_resume_edit_and_delete_notify_the_seller(): void
    {
        $id = $this->adminPromo([$this->robe->id])->assertCreated()->json('data.0.id');
        $admin = $this->as($this->admin);

        $admin->postJson("/api/admin/promotions/{$id}/pause")->assertOk()->assertJsonPath('data.display_status', 'paused');
        $this->assertEqualsWithDelta(100, (float) $this->getJson("/api/products/{$this->robe->slug}")->json('data.final_price'), 0.0005);

        // While paused the seller may start their own; resuming then conflicts
        $sp = $this->sellerPromo($this->robe);
        $this->as($this->admin)->postJson("/api/admin/promotions/{$id}/resume")->assertStatus(409);
        $this->as($this->admin)->postJson("/api/admin/promotions/{$id}/resume", ['replace_conflicts' => true])->assertOk()->assertJsonPath('data.display_status', 'active');
        $this->assertSame('expired', $sp->fresh()->status);

        $this->as($this->admin)->putJson("/api/admin/promotions/{$id}", ['discount_value' => 30])->assertOk()->assertJsonPath('data.discount_value', 30);
        $this->assertEqualsWithDelta(70, (float) $this->getJson("/api/products/{$this->robe->slug}")->json('data.final_price'), 0.0005);

        $this->as($this->admin)->deleteJson("/api/admin/promotions/{$id}")->assertOk();
        $this->assertNull(Promotion::find($id));

        foreach (['created', 'paused', 'resumed', 'updated', 'ended', 'replaced'] as $event) {
            Notification::assertSentTo($this->seller, AdminPromotionNotification::class, fn ($n) => $n->event === $event);
        }
    }

    public function test_deleting_a_promotion_used_by_orders_ends_it_instead(): void
    {
        $id = $this->adminPromo([$this->robe->id])->assertCreated()->json('data.0.id');
        $this->addToCart($this->robe);
        $order = $this->as($this->customer)->postJson('/api/checkout', $this->address())->assertCreated()->json('order_id');

        $this->as($this->admin)->deleteJson("/api/admin/promotions/{$id}")->assertOk();
        $this->assertSame('expired', Promotion::find($id)->status);
        $this->assertSame($id, (int) DB::table('order_items')->where('order_id', $order)->value('promotion_id'));
    }

    public function test_notification_renders_in_the_sellers_language(): void
    {
        $n = new AdminPromotionNotification('created', [
            'id' => 1, 'name' => 'Soldes', 'discount_type' => 'percentage', 'discount_value' => 50,
            'starts_at' => now()->toISOString(), 'ends_at' => now()->addDay()->toISOString(),
        ], ['Robe']);
        foreach (['fr' => 'Nouvelle promotion', 'en' => 'New Choose', 'ar' => 'عرض جديد'] as $locale => $needle) {
            app()->setLocale($locale);
            $this->assertStringContainsString($needle, $n->toDatabase($this->seller)['title']);
            $this->assertStringContainsString('50%', $n->toDatabase($this->seller)['body']);
            $this->assertNotEmpty($n->toMail($this->seller)->subject);
        }
    }

    // ── 9. Seller promotions: no regression + fixed holes ────────────────

    public function test_9_seller_promotion_flow_still_works(): void
    {
        $res = $this->as($this->seller)->postJson('/api/seller/promotions', [
            'name' => 'Rentrée', 'type' => 'discount', 'discount_type' => 'percentage', 'discount_value' => 20,
            // ISO with offset, as the seller form sends it (naive strings are read as Tunis time)
            'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addDays(10)->toIso8601String(),
            'product_ids' => [$this->robe->id],
        ])->assertCreated()->json('data');
        $this->assertSame('seller', $res['created_by']);
        $this->assertFalse($res['read_only']);
        $this->assertEqualsWithDelta(80, (float) $this->getJson("/api/products/{$this->robe->slug}")->json('data.final_price'), 0.0005);
        $this->assertSame('seller', $this->getJson("/api/products/{$this->robe->slug}")->json('data.promotion.source'));

        // Same payload the dashboard modal sends on edit
        $this->as($this->seller)->putJson("/api/seller/promotions/{$res['id']}", [
            'name' => 'Rentrée+', 'type' => 'discount', 'discount_type' => 'percentage', 'discount_value' => 25,
            'starts_at' => $res['starts_at'], 'ends_at' => now()->addDays(12)->toDateTimeString(), 'product_ids' => [$this->robe->id],
        ])->assertOk()->assertJsonPath('data.discount_value', 25);

        $this->addToCart($this->robe);
        $order = $this->as($this->customer)->postJson('/api/checkout', $this->address(['expected_total' => 83]))->assertCreated()->json('order_id');
        $item = DB::table('order_items')->where('order_id', $order)->first();
        $this->assertSame('seller', $item->promotion_source);
        $this->assertEqualsWithDelta(25, (float) $item->promo_discount_amount, 0.0005);

        // Used by an order: "delete" ends it, the order keeps it
        $this->as($this->seller)->deleteJson("/api/seller/promotions/{$res['id']}")->assertOk();
        $this->assertSame('expired', Promotion::find($res['id'])->status);
    }

    public function test_9b_seller_edit_can_no_longer_take_other_sellers_products_or_break_prices(): void
    {
        $foreign = $this->makeProduct("Autre {$this->token}", 50, $this->seller2);
        $promo = $this->sellerPromo($this->robe);
        $seller = $this->as($this->seller);

        $seller->putJson("/api/seller/promotions/{$promo->id}", ['product_ids' => [$foreign->id]])->assertForbidden();
        $this->as($this->seller)->putJson("/api/seller/promotions/{$promo->id}", ['discount_value' => 0])->assertStatus(422);
        $this->as($this->seller)->putJson("/api/seller/promotions/{$promo->id}", ['discount_value' => 95])->assertStatus(422);
        $this->as($this->seller)->putJson("/api/seller/promotions/{$promo->id}", ['status' => 'expired'])->assertStatus(422);
        $this->assertFalse($promo->products()->where('products.id', $foreign->id)->exists());

        // Fixed amount ≥ price: refused at creation
        $this->as($this->seller)->postJson('/api/seller/promotions', [
            'name' => 'Trop', 'type' => 'discount', 'discount_type' => 'fixed', 'discount_value' => 150,
            'starts_at' => now()->addDays(20)->toDateTimeString(), 'ends_at' => now()->addDays(25)->toDateTimeString(),
            'product_ids' => [$this->robe->id],
        ])->assertStatus(422)->assertJsonValidationErrors('discount_value');

        // Two of the seller's own promotions on one product can't overlap any more
        $this->as($this->seller)->postJson('/api/seller/promotions', [
            'name' => 'Flash', 'type' => 'flash_sale', 'discount_type' => 'percentage', 'discount_value' => 30,
            'starts_at' => now()->addHour()->toDateTimeString(), 'ends_at' => now()->addHours(5)->toDateTimeString(),
            'product_ids' => [$this->robe->id],
        ])->assertStatus(422)->assertJsonPath('code', 'promotion_conflict');
    }

    public function test_a_promotion_that_would_make_the_price_zero_is_ignored(): void
    {
        // Seller lowered the price below a fixed discount after it was created
        $this->sellerPromo($this->robe, ['discount_type' => 'fixed', 'discount_value' => 40]);
        $this->robe->update(['price' => 30]);
        PriceHistory::flush();

        $detail = $this->getJson("/api/products/{$this->robe->slug}")->json('data');
        $this->assertGreaterThan(0, (float) $detail['final_price']);
    }
}
