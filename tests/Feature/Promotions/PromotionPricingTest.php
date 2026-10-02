<?php

namespace Tests\Feature\Promotions;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Favorite;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Promotion;
use App\Models\SellerOrder;
use App\Models\User;
use App\Services\Chat\ProductRetriever;
use App\Services\PriceHistory;
use App\Services\PromotionService;
use App\Services\Recommendation\ProductCardPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One price everywhere: every endpoint that returns a product shows its active
 * promotion or flash sale, and the cart, coupon preview and checkout charge
 * exactly that price. Flash quotas are reserved atomically and released on
 * cancellation / failed payment.
 *
 * Mirrors the "Jean à jambes larges" case: 98 DT, back-to-school promotion -45% → 53.90 DT.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Promotions/PromotionPricingTest.php
 */
class PromotionPricingTest extends TestCase
{
    use DatabaseTransactions;

    private User $seller;
    private User $customer;
    private Category $category;
    private Product $jeans;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::flush();
        PriceHistory::flush();
        config(['platform.shipping_cost' => 8.0]);
        $this->app->instance(\App\Services\ProductTranslator::class, \Mockery::mock(\App\Services\ProductTranslator::class)->shouldIgnoreMissing());
        // AI service down: search uses the SQL fallback, chat skips semantic scores
        Http::fake(['*' => Http::response([], 500)]);

        $this->token    = 'zq' . Str::lower(Str::random(6));
        $this->seller   = $this->makeUser('seller');
        $this->customer = $this->makeUser('client');
        $s = Str::random(6);
        $this->category = Category::create(['name' => "Promo Cat $s", 'name_ar' => "Promo Cat $s", 'name_fr' => "Promo Cat $s", 'slug' => "promo-cat-$s", 'is_active' => true]);
        $this->jeans    = $this->makeProduct("Jean {$this->token} jambes larges", 98);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function makeUser(string $role): User
    {
        return $this->withCompleteProfile(User::create([
            'name' => 'Promo ' . Str::random(5), 'email' => "promo_{$role}_" . Str::random(10) . '@test.local',
            'password' => bcrypt('secret-password'), 'role' => $role, 'is_active' => true,
        ]));
    }

    private function makeProduct(string $name, float $price, int $stock = 30): Product
    {
        $p = Product::create([
            'seller_id' => $this->seller->id, 'category_id' => $this->category->id, 'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(4)), 'price' => $price, 'stock' => $stock,
            'is_approved' => true, 'is_active' => true,
        ]);
        $path = 'products/' . Str::random(10) . '.jpg';
        Storage::disk('public')->put($path, 'x');
        ProductImage::create(['product_id' => $p->id, 'image_path' => $path, 'order' => 0, 'is_primary' => true]);
        return $p;
    }

    private function promotion(Product $product, array $attrs = []): Promotion
    {
        $promo = Promotion::create(array_merge([
            'seller_id' => $this->seller->id, 'name' => 'back to school promotion', 'type' => 'discount',
            'discount_type' => 'percentage', 'discount_value' => 45, 'status' => 'active', 'priority' => 5,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(30),
        ], $attrs));
        $promo->products()->attach($product->id);
        return $promo;
    }

    private function flashSale(Product $product, array $attrs = []): Promotion
    {
        return $this->promotion($product, array_merge([
            'name' => 'Flash Friday', 'type' => 'flash_sale', 'discount_value' => 60, 'priority' => 10,
            'ends_at' => now()->addHours(5),
        ], $attrs));
    }

    private function asCustomer(): static
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->customer->createToken('t')->plainTextToken]);
    }

    private function address(): array
    {
        return [
            'recipient_name' => 'Test Buyer', 'phone' => '22123456', 'wilaya' => 'Tunis',
            'delegation' => 'Bab Bhar', 'address' => '1 rue Test', 'postal_code' => '1000',
            'payment_method' => 'cod',
        ];
    }

    /** Finds the product in any response shape (list, paginated, sections, single). */
    private function findIn(array $json, int $id): ?array
    {
        // A product payload, not a nested category/seller that happens to share the id
        if (($json['id'] ?? null) === $id && array_key_exists('slug', $json) && array_key_exists('price', $json)) return $json;
        foreach ($json as $v) {
            if (is_array($v) && ($hit = $this->findIn($v, $id))) return $hit;
        }
        return null;
    }

    private function assertPromoPriced(?array $p, string $where, float $final = 53.9, int $pct = 45, string $type = 'promotion'): void
    {
        $this->assertNotNull($p, "{$where}: product missing from response");
        $this->assertEqualsWithDelta($final, (float) $p['final_price'], 0.0005, "{$where}: final_price");
        $this->assertEqualsWithDelta(98, (float) $p['original_price'], 0.0005, "{$where}: original_price");
        $this->assertSame($pct, $p['discount_percent'], "{$where}: discount_percent");
        $this->assertSame($type, $p['promo_type'], "{$where}: promo_type");
        $this->assertNotNull($p['ends_at'], "{$where}: ends_at");
    }

    private function assertFullPrice(?array $p, string $where): void
    {
        $this->assertNotNull($p, "{$where}: product missing from response");
        $this->assertEqualsWithDelta(98, (float) $p['final_price'], 0.0005, "{$where}: final_price");
        $this->assertNull($p['promo_type'], "{$where}: promo_type");
        $this->assertNull($p['promotion'], "{$where}: promotion");
    }

    // ── Every surface shows the jeans at 53.90 / -45% ─────────────────────

    public function test_jeans_promotion_shows_on_every_product_endpoint(): void
    {
        $this->promotion($this->jeans);
        $sibling = $this->makeProduct("Pantalon {$this->token}", 70);   // same category → "related"/"same category"
        $id = $this->jeans->id;

        $search = $this->postJson('/api/search/text', ['query' => $this->token])->assertOk()->json();
        $this->assertPromoPriced($this->findIn($search['sections'], $id), 'search');

        $byIds = $this->postJson('/api/products/by-ids', ['ids' => [$id]])->assertOk()->json();
        $this->assertPromoPriced($this->findIn($byIds, $id), 'by-ids');

        $list = $this->getJson("/api/products?seller_id={$this->seller->id}&sort=price_asc")->assertOk()->json();
        $this->assertPromoPriced($this->findIn($list, $id), 'products list / vendor page');

        $cat = $this->getJson("/api/categories/{$this->category->slug}/products")->assertOk()->json();
        $this->assertPromoPriced($this->findIn($cat, $id), 'category products');

        $similar = $this->getJson("/api/products/{$sibling->slug}/similar")->assertOk()->json();
        $this->assertPromoPriced($this->findIn($similar, $id), 'same category (similar)');

        $detail = $this->getJson("/api/products/{$this->jeans->slug}")->assertOk()->json('data');
        $this->assertPromoPriced($detail, 'product detail');
        $this->assertEqualsWithDelta(53.9, (float) $detail['effective_price'], 0.0005, 'detail effective_price (old key)');

        $cards = app(ProductCardPresenter::class)->present(Product::whereKey($id)->with('primaryImage')->get());
        $this->assertPromoPriced($cards[0], 'home feed card');

        // Price filters use the price paid: 53.90 is under 60
        $filtered = $this->postJson('/api/search/text', ['query' => $this->token, 'max_price' => 60])->assertOk()->json();
        $this->assertNotNull($this->findIn($filtered['sections'] ?? $filtered, $id), 'search max_price uses final price');
    }

    public function test_jeans_promotion_in_favorites_cart_and_coupon_preview(): void
    {
        $this->promotion($this->jeans);
        Favorite::create(['user_id' => $this->customer->id, 'product_id' => $this->jeans->id]);
        $row = Cart::create(['user_id' => $this->customer->id, 'product_id' => $this->jeans->id, 'quantity' => 2]);

        $fav = $this->asCustomer()->getJson('/api/favorites')->assertOk()->json('data.0');
        $this->assertEqualsWithDelta(53.9, (float) $fav['final_price'], 0.0005);
        $this->assertSame(45, $fav['discount_percent']);

        $cart = $this->asCustomer()->getJson('/api/cart')->assertOk()->json('data');
        $this->assertEqualsWithDelta(53.9, (float) $cart['items'][0]['price'], 0.0005);
        $this->assertEqualsWithDelta(98, (float) $cart['items'][0]['original_price'], 0.0005);
        $this->assertEqualsWithDelta(107.8, (float) $cart['subtotal'], 0.0005);
        $this->assertSame($row->id, $cart['items'][0]['id']);
    }

    public function test_checkout_and_buy_now_charge_the_promo_price(): void
    {
        $this->promotion($this->jeans);
        Cart::create(['user_id' => $this->customer->id, 'product_id' => $this->jeans->id, 'quantity' => 1]);

        // Summary shown: 53.90 + 8 delivery = 61.90 → the order total is exactly that
        $res = $this->asCustomer()->postJson('/api/checkout', $this->address() + ['expected_total' => 61.9])
            ->assertCreated()->json();
        $this->assertEqualsWithDelta(61.9, $res['total'], 0.0005);
        $item = DB::table('order_items')->where('order_id', $res['order_id'])->first();
        $this->assertEqualsWithDelta(53.9, (float) $item->price, 0.0005);
        $this->assertNotNull($item->promotion_id);

        $buy = $this->asCustomer()->postJson('/api/checkout/buy-now', $this->address() + [
            'product_id' => $this->jeans->id, 'quantity' => 2, 'expected_total' => 115.8,
        ])->assertCreated()->json();
        $this->assertEqualsWithDelta(115.8, $buy['total'], 0.0005);    // 2 × 53.90 + 8
        $this->assertEqualsWithDelta(53.9, (float) DB::table('order_items')->where('order_id', $buy['order_id'])->value('price'), 0.0005);
    }

    public function test_checkout_refuses_a_total_the_customer_was_not_shown(): void
    {
        $this->promotion($this->jeans);
        $before = Order::count();

        // The page still shows the full price (e.g. opened before the promo started)
        $this->asCustomer()->postJson('/api/checkout/buy-now', $this->address() + [
            'product_id' => $this->jeans->id, 'quantity' => 1, 'expected_total' => 106,
        ])->assertStatus(409)
          ->assertJsonPath('code', 'price_changed')
          ->assertJsonPath('data.total', 61.9);

        $this->assertSame($before, Order::count());
    }

    // ── Expired / future / flash ───────────────────────────────────────────

    public function test_expired_promotion_never_applies_even_if_cron_did_not_run(): void
    {
        // status still 'active': promotions:sync hasn't expired it
        $this->promotion($this->jeans, ['starts_at' => now()->subDays(10), 'ends_at' => now()->subMinute()]);

        $this->assertFullPrice($this->getJson("/api/products/{$this->jeans->slug}")->json('data'), 'detail');
        $this->assertFullPrice($this->findIn($this->postJson('/api/products/by-ids', ['ids' => [$this->jeans->id]])->json(), $this->jeans->id), 'by-ids');

        $buy = $this->asCustomer()->postJson('/api/checkout/buy-now', $this->address() + ['product_id' => $this->jeans->id, 'quantity' => 1])
            ->assertCreated()->json();
        $this->assertEqualsWithDelta(106, $buy['total'], 0.0005);
    }

    public function test_promotion_stops_applying_the_moment_it_ends(): void
    {
        $this->promotion($this->jeans, ['ends_at' => now()->addMinutes(10)]);
        $this->assertPromoPriced($this->getJson("/api/products/{$this->jeans->slug}")->json('data'), 'before end');

        $this->travel(11)->minutes();
        $this->assertFullPrice($this->getJson("/api/products/{$this->jeans->slug}")->json('data'), 'after end');
    }

    public function test_future_promotion_applies_at_its_start_without_the_cron(): void
    {
        $this->promotion($this->jeans, ['status' => 'scheduled', 'starts_at' => now()->addHour(), 'ends_at' => now()->addDays(5)]);
        $this->assertFullPrice($this->getJson("/api/products/{$this->jeans->slug}")->json('data'), 'before start');

        $this->travel(61)->minutes();   // promotions:sync never ran: status is still 'scheduled'
        $this->assertPromoPriced($this->getJson("/api/products/{$this->jeans->slug}")->json('data'), 'after start');
    }

    public function test_flash_sale_wins_over_a_bigger_discount_and_carries_its_countdown(): void
    {
        $this->promotion($this->jeans, ['discount_value' => 70, 'priority' => 50]);
        $flash = $this->flashSale($this->jeans, ['discount_value' => 30]);

        $p = $this->getJson("/api/products/{$this->jeans->slug}")->json('data');
        $this->assertPromoPriced($p, 'flash detail', 68.6, 30, 'flash_sale');
        $this->assertSame($flash->ends_at->toISOString(), $p['ends_at']);
        $this->assertTrue($p['promotion']['is_flash_sale']);
    }

    public function test_paused_promotion_does_not_apply(): void
    {
        $this->promotion($this->jeans, ['status' => 'paused']);
        $this->assertFullPrice($this->getJson("/api/products/{$this->jeans->slug}")->json('data'), 'paused');
    }

    // ── Flash quota ────────────────────────────────────────────────────────

    public function test_flash_quota_is_consumed_and_then_the_flash_price_stops(): void
    {
        $flash = $this->flashSale($this->jeans, ['flash_stock' => 2]);

        $this->asCustomer()->postJson('/api/checkout/buy-now', $this->address() + ['product_id' => $this->jeans->id, 'quantity' => 2])
            ->assertCreated();
        $this->assertSame(2, $flash->fresh()->flash_stock_used);
        $this->assertSame(2, (int) DB::table('order_items')->where('promotion_id', $flash->id)->value('flash_reserved'));

        // Sold out: back to full price everywhere, including checkout
        $this->assertFullPrice($this->getJson("/api/products/{$this->jeans->slug}")->json('data'), 'after quota');
        $buy = $this->asCustomer()->postJson('/api/checkout/buy-now', $this->address() + ['product_id' => $this->jeans->id, 'quantity' => 1])
            ->assertCreated()->json();
        $this->assertEqualsWithDelta(106, $buy['total'], 0.0005);
        $this->assertSame(2, $flash->fresh()->flash_stock_used);
    }

    public function test_order_bigger_than_the_remaining_flash_quota_is_refused(): void
    {
        $flash = $this->flashSale($this->jeans, ['flash_stock' => 3, 'flash_stock_used' => 2]);
        Cart::create(['user_id' => $this->customer->id, 'product_id' => $this->jeans->id, 'quantity' => 2]);
        $orders = Order::count();

        $this->asCustomer()->postJson('/api/checkout', $this->address())
            ->assertStatus(422)
            ->assertJsonPath('code', 'flash_sold_out');

        $this->assertSame($orders, Order::count(), 'no order at the full price behind the customer\'s back');
        $this->assertSame(2, $flash->fresh()->flash_stock_used);
        $this->assertSame(30, $this->jeans->fresh()->stock, 'stock untouched (transaction rolled back)');
    }

    public function test_cancelling_an_order_releases_its_flash_units_once(): void
    {
        $flash = $this->flashSale($this->jeans, ['flash_stock' => 5]);
        $buy = $this->asCustomer()->postJson('/api/checkout/buy-now', $this->address() + ['product_id' => $this->jeans->id, 'quantity' => 2])
            ->assertCreated()->json();
        $this->assertSame(2, $flash->fresh()->flash_stock_used);

        $sellerOrder = SellerOrder::where('order_id', $buy['order_id'])->first();
        $sellerOrder->update(['status' => 'cancelled']);              // observer path
        $this->assertSame(0, $flash->fresh()->flash_stock_used);
        $this->assertSame(0, (int) DB::table('order_items')->where('order_id', $buy['order_id'])->value('flash_reserved'));

        // Second cancel / sweep: nothing more to give back
        $sellerOrder->update(['status' => 'pending']);
        $sellerOrder->update(['status' => 'cancelled']);
        app(PromotionService::class)->releaseCancelled();
        $this->assertSame(0, $flash->fresh()->flash_stock_used);
    }

    public function test_cancellation_written_without_the_observer_is_released_by_the_sweep(): void
    {
        $flash = $this->flashSale($this->jeans, ['flash_stock' => 5]);
        $buy = $this->asCustomer()->postJson('/api/checkout/buy-now', $this->address() + ['product_id' => $this->jeans->id, 'quantity' => 3])
            ->assertCreated()->json();

        DB::table('seller_orders')->where('order_id', $buy['order_id'])->update(['status' => 'cancelled']);
        $this->assertSame(3, $flash->fresh()->flash_stock_used);

        $this->artisan('promotions:sync')->assertExitCode(0);
        $this->assertSame(0, $flash->fresh()->flash_stock_used);
    }

    public function test_failed_payment_releases_units_and_a_later_success_takes_them_back(): void
    {
        $flash = $this->flashSale($this->jeans, ['flash_stock' => 5]);
        $buy = $this->asCustomer()->postJson('/api/checkout/buy-now', $this->address() + ['product_id' => $this->jeans->id, 'quantity' => 2, 'payment_method' => 'card'])
            ->assertCreated()->json();
        $promotions = app(PromotionService::class);

        $this->assertSame(2, $promotions->releaseForOrder($buy['order_id']));
        $this->assertSame(0, $flash->fresh()->flash_stock_used);
        $this->assertSame(0, $promotions->releaseForOrder($buy['order_id']), 'released once');

        $promotions->reclaimForOrder($buy['order_id']);
        $this->assertSame(2, $flash->fresh()->flash_stock_used);
    }

    public function test_abandoned_card_order_is_cancelled_after_30_minutes_and_gives_everything_back(): void
    {
        $flash  = $this->flashSale($this->jeans, ['flash_stock' => 5]);
        $coupon = \App\Models\Coupon::create([
            'seller_id' => $this->seller->id, 'code' => 'BACK' . Str::upper(Str::random(5)),
            'discount_type' => 'fixed', 'discount_value' => 5, 'is_active' => true,
        ]);
        // Coupons skip promoted products: it applies to the plain one in the same order
        $plain = $this->makeProduct("Plain {$this->token}", 40);
        $coupon->products()->attach($plain->id);
        Cart::create(['user_id' => $this->customer->id, 'product_id' => $this->jeans->id, 'quantity' => 2]);
        Cart::create(['user_id' => $this->customer->id, 'product_id' => $plain->id, 'quantity' => 1]);
        $card = $this->asCustomer()->postJson('/api/checkout', array_merge($this->address(), [
            'payment_method' => 'card', 'coupon_codes' => [$coupon->code],
        ]))->assertCreated()->json();
        $cod = $this->asCustomer()->postJson('/api/checkout/buy-now', $this->address() + [
            'product_id' => $this->jeans->id, 'quantity' => 1,
        ])->assertCreated()->json();
        $this->assertSame(3, $flash->fresh()->flash_stock_used);
        $this->assertSame(27, $this->jeans->fresh()->stock);
        $this->assertSame(1, $coupon->fresh()->usage_count);

        // 29 minutes: still waiting for the payment
        $this->travel(29)->minutes();
        $this->artisan('orders:cancel-abandoned-card')->assertExitCode(0);
        $this->assertSame('pending', Order::find($card['order_id'])->status);

        $this->travel(2)->minutes();
        $this->artisan('orders:cancel-abandoned-card')->assertExitCode(0);

        $order = Order::find($card['order_id']);
        $this->assertSame('cancelled', $order->status);
        $this->assertSame(['cancelled'], $order->sellerOrders->pluck('status')->unique()->values()->all());
        $this->assertSame(1, $flash->fresh()->flash_stock_used, 'card order units released, COD order keeps its unit');
        $this->assertSame(29, $this->jeans->fresh()->stock);
        $this->assertSame(30, $plain->fresh()->stock);
        $this->assertSame(0, $coupon->fresh()->usage_count);
        $this->assertSame('pending', Order::find($cod['order_id'])->status, 'cash on delivery is never auto-cancelled');

        // Running again changes nothing
        $this->artisan('orders:cancel-abandoned-card')->assertExitCode(0);
        $this->assertSame(29, $this->jeans->fresh()->stock);
    }

    public function test_partial_return_gives_the_returned_flash_units_back(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $flash = $this->flashSale($this->jeans, ['flash_stock' => 5]);
        $plain = $this->makeProduct("Plain {$this->token}", 40);
        Cart::create(['user_id' => $this->customer->id, 'product_id' => $this->jeans->id, 'quantity' => 2]);
        Cart::create(['user_id' => $this->customer->id, 'product_id' => $plain->id, 'quantity' => 1]);
        $res = $this->asCustomer()->postJson('/api/checkout', $this->address())->assertCreated()->json();
        $this->assertSame(2, $flash->fresh()->flash_stock_used);

        $sellerOrder = SellerOrder::where('order_id', $res['order_id'])->first();
        $sellerOrder->update(['status' => 'delivered']);
        $jeansLine = DB::table('order_items')->where('order_id', $res['order_id'])->where('product_id', $this->jeans->id)->first();

        // Only the jeans line comes back; the plain product stays sold
        $complaint = \App\Models\Complaint::create([
            'user_id' => $this->customer->id, 'order_id' => $res['order_id'], 'seller_id' => $this->seller->id,
            'order_item_ids' => [$jeansLine->id], 'complaint_type' => 'damaged', 'resolution_type' => 'return_refund',
            'description' => 'Wrong size', 'status' => 'approved',
        ]);
        $task = \App\Models\RefundDeliveryTask::create(['complaint_id' => $complaint->id, 'seller_id' => $this->seller->id, 'status' => 'completed']);
        (new \App\Listeners\MarkOrderRefunded())->handle(new \App\Events\RefundCompleted($task));

        $this->assertSame('delivered', $sellerOrder->fresh()->status, 'partial return keeps the seller order');
        $this->assertSame(0, $flash->fresh()->flash_stock_used);
        $this->assertSame(0, (int) DB::table('order_items')->where('id', $jeansLine->id)->value('flash_reserved'));
        $this->assertSame(30, $this->jeans->fresh()->stock);
    }

    // ── Delivery fee: sellers' custom fees, same rule for cart and buy-now ──

    private function cartTotalFor(array $lines): array
    {
        Cart::where('user_id', $this->customer->id)->delete();
        foreach ($lines as $product) {
            Cart::create(['user_id' => $this->customer->id, 'product_id' => $product->id, 'quantity' => 1]);
        }
        return $this->asCustomer()->postJson('/api/checkout', $this->address())->assertCreated()->json();
    }

    public function test_cart_checkout_charges_the_sellers_custom_delivery_fee(): void
    {
        $custom5  = $this->makeProduct("Custom5 {$this->token}", 20);
        $custom5->update(['delivery_fee' => 5]);
        $custom12 = $this->makeProduct("Custom12 {$this->token}", 30);
        $custom12->update(['delivery_fee' => 12]);
        $free     = $this->makeProduct("Free {$this->token}", 10);
        $free->update(['delivery_fee' => 0]);
        $default  = $this->makeProduct("Default {$this->token}", 15);

        $res = $this->cartTotalFor([$custom5]);
        $this->assertEqualsWithDelta(5, $res['shipping_fee'], 0.0005, 'custom fee, like buy-now');
        $this->assertEqualsWithDelta(25, $res['total'], 0.0005);

        $this->assertEqualsWithDelta(5,  $this->cartTotalFor([$custom5, $free])['shipping_fee'], 0.0005, 'free item adds nothing');
        $this->assertEqualsWithDelta(12, $this->cartTotalFor([$custom5, $custom12])['shipping_fee'], 0.0005, 'one shipment: highest fee');
        $this->assertEqualsWithDelta(8,  $this->cartTotalFor([$custom5, $default])['shipping_fee'], 0.0005, 'platform default counts');
        $this->assertEqualsWithDelta(0,  $this->cartTotalFor([$free])['shipping_fee'], 0.0005);

        // Buy-now uses the same rule
        $buy = $this->asCustomer()->postJson('/api/checkout/buy-now', $this->address() + ['product_id' => $custom12->id, 'quantity' => 1])
            ->assertCreated()->json();
        $this->assertEqualsWithDelta(42, $buy['total'], 0.0005);

        // The cart lines expose what the checkout page needs to show the same fee
        Cart::create(['user_id' => $this->customer->id, 'product_id' => $custom12->id, 'quantity' => 1]);
        $line = collect($this->asCustomer()->getJson('/api/cart')->json('data.items'))->firstWhere('product_id', $custom12->id);
        $this->assertEqualsWithDelta(12, $line['delivery_fee'], 0.0005);
        $this->assertFalse($line['is_free_delivery']);
    }

    // ── Paginated lists filter and sort on the final price, in SQL ─────────

    public function test_products_list_filters_and_sorts_on_the_final_price_with_pagination(): void
    {
        $this->promotion($this->jeans);                       // 98 → 53.90
        $cheap = $this->makeProduct("Cheap {$this->token}", 50);
        $mid   = $this->makeProduct("Mid {$this->token}", 60);
        $ids   = fn ($res) => collect($res->json('data.data'))->pluck('id')->all();
        $base  = "/api/products?seller_id={$this->seller->id}";

        $this->assertEqualsCanonicalizing([$this->jeans->id, $cheap->id], $ids($this->getJson("{$base}&price_max=55")));
        $this->assertSame([$mid->id], $ids($this->getJson("{$base}&price_min=55")));

        // Sorted by price paid, one per page: 50, 53.90, 60
        $page2 = $this->getJson("{$base}&sort=price_asc&per_page=1&page=2")->assertOk();
        $this->assertSame([$this->jeans->id], $ids($page2));
        $this->assertSame(3, $page2->json('data.total'));
        $this->assertSame([$mid->id], $ids($this->getJson("{$base}&sort=price_desc&per_page=1&page=1")));

        $cat = "/api/categories/{$this->category->slug}/products";
        $this->assertEqualsCanonicalizing([$this->jeans->id, $cheap->id], $ids($this->getJson("{$cat}?price_max=55")));
        $this->assertSame([$cheap->id, $this->jeans->id, $mid->id], $ids($this->getJson("{$cat}?sort=price&order=asc")));
    }

    public function test_sql_final_price_matches_php_pricing(): void
    {
        $flash   = $this->makeProduct("Flash {$this->token}", 80);
        $fixed   = $this->makeProduct("Fixed {$this->token}", 40);
        $soldOut = $this->makeProduct("SoldOut {$this->token}", 30);
        $raised  = $this->makeProduct("Raised {$this->token}", 100);
        $plain   = $this->makeProduct("Plain {$this->token}", 25);

        $this->promotion($this->jeans);
        $this->promotion($flash, ['discount_value' => 20]);
        $this->flashSale($flash, ['discount_value' => 35]);
        $this->promotion($fixed, ['discount_type' => 'fixed', 'discount_value' => 7.5]);
        $this->flashSale($soldOut, ['flash_stock' => 1, 'flash_stock_used' => 1]);
        // Price raised just before a promotion: discount computed from the 30-day lowest (100)
        $raised->update(['price' => 150]);
        $this->promotion($raised, ['discount_value' => 10]);

        [$sql, $bindings] = app(PromotionService::class)->finalPriceSql();
        $rows = Product::whereIn('id', [$this->jeans->id, $flash->id, $fixed->id, $soldOut->id, $raised->id, $plain->id])
            ->select('id', 'price')->selectRaw("({$sql}) AS sql_final", $bindings)->get();
        $php  = app(PromotionService::class)->priceMany($rows);

        foreach ($rows as $r) {
            $this->assertEqualsWithDelta($php[$r->id]['final_price'], (float) $r->sql_final, 0.0005, "product {$r->id}");
        }
        $this->assertEqualsWithDelta(90, (float) $rows->firstWhere('id', $raised->id)->sql_final, 0.0005);
        $this->assertEqualsWithDelta(52, (float) $rows->firstWhere('id', $flash->id)->sql_final, 0.0005);
    }

    // ── Chatbot uses the same pricing ─────────────────────────────────────

    public function test_chatbot_prices_and_filters_with_the_promo_price(): void
    {
        $this->promotion($this->jeans);

        $out = app(ProductRetriever::class)->search(['keywords' => [$this->token], 'max_price' => 60]);
        $hit = collect($out['products'])->firstWhere('id', $this->jeans->id);

        $this->assertNotNull($hit, 'max 60 DT finds the jeans at 53.90');
        $this->assertEqualsWithDelta(53.9, $hit['price'], 0.0005);
        $this->assertEqualsWithDelta(98, $hit['old_price'], 0.0005);
        $this->assertFalse($hit['flash_sale']);
    }
}
