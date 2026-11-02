<?php

namespace Tests\Feature\Orders;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Services\Orders\CheckoutGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\Delivery\DeliveryTestHelpers;
use Tests\TestCase;

/**
 * Order creation safety net (CheckoutGuard): one order per confirmation key,
 * no identical order seconds apart. Pricing, stock and coupons unchanged.
 *
 * Run:  php vendor/bin/phpunit tests/Feature/Orders/CheckoutIdempotencyTest.php
 */
class CheckoutIdempotencyTest extends TestCase
{
    use DatabaseTransactions, DeliveryTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('choosetounsi_test', DB::connection()->getDatabaseName());
        $this->setUpDelivery();
        Cache::flush();
    }

    /** Full request body as the storefront sends it: quote first, then the quoted total. */
    private function body(array $body = []): array
    {
        $quote = $this->quote(array_intersect_key($body, array_flip(['coupon_codes'])));
        return $this->address() + $body + ['payment_method' => 'cod', 'expected_total' => $quote['total']];
    }

    private function send(array $fullBody): \Illuminate\Testing\TestResponse
    {
        return $this->as($this->customer)->postJson('/api/checkout', $fullBody);
    }

    private function place(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->send($this->body($body));
    }

    private function ordersOfCustomer(): int
    {
        return Order::where('user_id', $this->customer->id)->count();
    }

    public function test_the_same_key_twice_creates_one_order_and_returns_it_again(): void
    {
        $product = $this->product($this->seller(), 30, false, 10);
        $this->addToCart($product, 2);
        $key = (string) Str::uuid();

        $body  = $this->body(['idempotency_key' => $key]);
        $first = $this->send($body)->assertCreated()->json();
        // Resent (double click, network retry): the cart is already empty, the order comes back
        $again = $this->send($body)->assertOk()->json();

        $this->assertSame($first, $again, 'same response as the first time');
        $this->assertSame(1, $this->ordersOfCustomer());
        $this->assertSame($key, Order::find($first['order_id'])->idempotency_key);
        $this->assertSame(8, $product->fresh()->stock, 'stock taken once');
    }

    public function test_repeated_key_after_the_cache_expired_still_returns_the_order(): void
    {
        $this->addToCart($this->product($this->seller(), 30));
        $key = (string) Str::uuid();
        $body  = $this->body(['idempotency_key' => $key]);
        $first = $this->send($body)->assertCreated()->json();
        Cache::flush();

        $again = $this->send($body)->assertOk()->json();
        $this->assertSame($first['order_number'], $again['order_number']);
        $this->assertSame($first['order_id'], $again['order_id']);
        $this->assertEquals($first['total'], $again['total']);
        $this->assertSame(1, $this->ordersOfCustomer());
    }

    public function test_an_order_already_written_with_the_key_wins_over_a_new_insert(): void
    {
        // Two requests with one key at the same moment: the second finds the first's order
        $this->addToCart($this->product($this->seller(), 30));
        $key = (string) Str::uuid();
        $existing = Order::create([
            'user_id' => $this->customer->id, 'order_number' => 'ORD-RACE' . Str::random(4), 'idempotency_key' => $key,
            'status' => 'pending', 'payment_status' => 'unpaid', 'payment_method' => 'cod',
            'subtotal' => 30, 'discount_amount' => 0, 'shipping_fee' => 8, 'total_amount' => 38,
            'recipient_name' => 'X', 'phone' => '22123456', 'wilaya' => 'Tunis', 'address' => 'x',
        ]);

        $res = $this->place(['idempotency_key' => $key])->assertOk()->json();
        $this->assertSame($existing->id, $res['order_id']);
        $this->assertSame(1, $this->ordersOfCustomer());
        $this->assertSame(1, \App\Models\Cart::where('user_id', $this->customer->id)->count(), 'cart untouched');
    }

    public function test_another_buyers_key_is_refused(): void
    {
        $this->addToCart($this->product($this->seller(), 30));
        $key = (string) Str::uuid();
        $first = $this->place(['idempotency_key' => $key])->assertCreated()->json();

        $intruder = $this->makeUser('client');
        $this->customer = $intruder;
        $this->addToCart($this->product($this->seller(), 30));
        $this->as($intruder)->postJson('/api/checkout', $this->address() + ['payment_method' => 'cod', 'idempotency_key' => $key])
            ->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict')
            ->assertJsonMissing(['order_number' => $first['order_number']]);
        $this->assertSame(0, Order::where('user_id', $intruder->id)->count());
    }

    public function test_without_a_key_it_still_works_and_is_logged(): void
    {
        Log::spy();
        $this->addToCart($this->product($this->seller(), 30));
        $this->place()->assertCreated();
        $this->assertSame(1, $this->ordersOfCustomer());
        $this->assertNull(Order::where('user_id', $this->customer->id)->first()->idempotency_key);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains($msg, 'without idempotency_key'))->once();
    }

    public function test_a_bad_key_format_is_a_validation_error(): void
    {
        $this->addToCart($this->product($this->seller(), 30));
        $this->place(['idempotency_key' => 'short'])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(0, $this->ordersOfCustomer());
    }

    public function test_identical_buy_now_seconds_apart_is_refused_then_allowed(): void
    {
        $product = $this->product($this->seller(), 30, false, 10);
        $buy = fn () => $this->as($this->customer)->postJson('/api/checkout/buy-now', $this->address() + [
            'payment_method' => 'cod', 'product_id' => $product->id, 'quantity' => 1, 'idempotency_key' => (string) Str::uuid(),
        ]);

        $buy()->assertCreated();
        // A different key, same product and buyer, a moment later: refused, nothing written
        $buy()->assertStatus(429)->assertJsonPath('code', 'duplicate_order');
        $this->assertSame(1, $this->ordersOfCustomer());
        $this->assertSame(9, $product->fresh()->stock);

        $this->travel(CheckoutGuard::RECENT_SECONDS + 1)->seconds();
        $buy()->assertCreated();
        $this->assertSame(2, $this->ordersOfCustomer());
    }

    public function test_a_different_product_right_after_is_allowed(): void
    {
        $seller = $this->seller();
        $buy = fn ($p) => $this->as($this->customer)->postJson('/api/checkout/buy-now', $this->address() + [
            'payment_method' => 'cod', 'product_id' => $p->id, 'quantity' => 1, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $buy($this->product($seller, 30))->assertCreated();
        $buy($this->product($seller, 40))->assertCreated();
        $this->assertSame(2, $this->ordersOfCustomer());
    }

    public function test_an_order_in_progress_for_the_buyer_refuses_a_second_one(): void
    {
        $this->addToCart($this->product($this->seller(), 30));
        $lock = Cache::lock('checkout:placing:u:' . $this->customer->id, 30);
        $this->assertTrue($lock->get());

        $this->place()->assertStatus(429)->assertJsonPath('code', 'duplicate_order');
        $this->assertSame(0, $this->ordersOfCustomer());
        $lock->release();
        $this->place()->assertCreated();
    }

    public function test_multi_seller_cart_with_coupon_and_flash_sale_charges_the_quote_and_redeems_once(): void
    {
        $sellerA = $this->seller();
        $sellerB = $this->seller();
        $flash   = $this->product($sellerA, 50, false, 10);
        $this->promotion($flash, 'flash_sale', 20);
        $plain   = $this->product($sellerB, 40);
        $coupon  = Coupon::create([
            'seller_id' => $sellerB->id, 'code' => 'IDEM' . strtoupper(Str::random(4)), 'discount_type' => 'fixed', 'discount_value' => 5,
            'is_active' => true,
        ]);
        $coupon->products()->attach($plain->id);
        $this->addToCart($flash, 2);
        $this->addToCart($plain, 1);

        $quote = $this->quote(['coupon_codes' => [$coupon->code]]);
        $body  = $this->body(['coupon_codes' => [$coupon->code], 'idempotency_key' => (string) Str::uuid()]);
        $res   = $this->send($body)->assertCreated()->json();

        // What the confirmation showed (the quote) is what is charged, parcel by parcel
        $this->assertEquals($quote['total'], $res['total']);
        $this->assertEquals($quote['parcels'], $res['parcels']);
        $this->assertSame(2, SellerOrder::where('order_id', $res['order_id'])->count());

        $this->send($body)->assertOk();   // resent: no second redemption, no second order
        $this->assertSame(1, $this->ordersOfCustomer());
        $this->assertSame(1, CouponRedemption::where('coupon_id', $coupon->id)->count());
        $this->assertSame(8, $flash->fresh()->stock);
    }
}
