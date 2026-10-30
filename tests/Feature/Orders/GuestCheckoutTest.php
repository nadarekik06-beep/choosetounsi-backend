<?php

namespace Tests\Feature\Orders;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\Sponsorship;
use App\Models\SponsorshipEvent;
use App\Models\User;
use App\Notifications\Buyer\OrderPlacedNotification;
use App\Services\Intigo\ParcelPayload;
use App\Services\Orders\WhatsApp\SellerReminderPayload;
use App\Support\GuestCheckout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Feature\Intigo\IntigoTestHelpers;
use Tests\TestCase;

/**
 * Guest checkout (order without an account), end to end on choosetounsi_test.
 *
 * Run:  php -d extension=gd vendor/bin/phpunit tests/Feature/Orders/GuestCheckoutTest.php
 */
class GuestCheckoutTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    private string $cartToken;

    protected function setUp(): void
    {
        parent::setUp();
        // Never the main database (phpunit.xml forces choosetounsi_test)
        $this->assertSame('choosetounsi_test', DB::connection()->getDatabaseName());

        $this->setUpIntigo();
        config(['guest_checkout.enabled' => true]);
        PlatformSetting::where('key', GuestCheckout::SETTING_KEY)->delete();
        PlatformSetting::flushCache();
        Cache::flush(); // rate limiter counters (array store)
        $this->cartToken = Str::random(40);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** A request with no login (and no header left over from an earlier as()). */
    private function guest(?string $token = null)
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        return $this->withHeaders([GuestCheckout::CART_HEADER => $token ?? $this->cartToken]);
    }

    private function guestAdd($product, int $qty = 1, ?string $token = null): void
    {
        $this->guest($token)->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => $qty])->assertOk();
    }

    private function guestAddress(string $phone = '22333444'): array
    {
        return ['recipient_name' => 'Amel Guest', 'wilaya' => 'Tunis', 'delegation' => 'Bab Bhar',
            'address' => '5 rue de la Kasbah', 'postal_code' => '1000', 'phone' => $phone];
    }

    /** Quote then place, as the storefront does. */
    private function guestCheckout(array $body = [], string $phone = '22333444', ?string $token = null): \Illuminate\Testing\TestResponse
    {
        $quote = $this->guest($token)->postJson('/api/checkout/quote', ['phone' => $phone] + array_intersect_key($body, array_flip(['coupon_codes'])))
            ->assertOk()->json('data');
        return $this->guest($token)->postJson('/api/checkout', $this->guestAddress($phone) + $body + [
            'payment_method' => 'cod', 'expected_total' => $quote['total'],
        ]);
    }

    private function placedGuestOrder(array $body = [], string $phone = '22333444'): array
    {
        return $this->guestCheckout($body, $phone)->assertCreated()->json();
    }

    // ── switch ──────────────────────────────────────────────────────────────

    public function test_switched_off_everything_requires_login_as_before(): void
    {
        config(['guest_checkout.enabled' => false]);
        $product = $this->product($this->seller(), 30);

        $this->guest()->getJson('/api/cart')->assertStatus(401);
        $this->guest()->postJson('/api/cart', ['product_id' => $product->id])->assertStatus(401);
        $this->guest()->postJson('/api/checkout', $this->guestAddress())->assertStatus(401);
        $this->guest()->postJson('/api/checkout/quote', [])->assertStatus(401);
        $this->assertFalse($this->getJson('/api/checkout/payment-info')->json('data.guest_checkout'));

        // Signed-in checkout still works
        $this->addToCart($product);
        $res = $this->checkout();
        $this->assertNotNull(Order::find($res['order_id'])->user_id);
    }

    public function test_artisan_switch_overrides_the_env_value_instantly(): void
    {
        config(['guest_checkout.enabled' => true]);
        $this->artisan('guest-checkout', ['action' => 'off'])->assertExitCode(0);
        $this->assertFalse(GuestCheckout::enabled());
        $this->guest()->getJson('/api/cart')->assertStatus(401);

        $this->artisan('guest-checkout', ['action' => 'on'])->assertExitCode(0);
        PlatformSetting::flushCache();
        $this->assertTrue(GuestCheckout::enabled());
        $this->guest()->getJson('/api/cart')->assertOk();

        $this->artisan('guest-checkout', ['action' => 'reset'])->assertExitCode(0);
        $this->assertNull(GuestCheckout::override());
    }

    // ── cart ────────────────────────────────────────────────────────────────

    public function test_guest_cart_needs_a_token_and_is_private_to_it(): void
    {
        $product = $this->product($this->seller(), 30);

        $this->flushHeaders();
        $this->postJson('/api/cart', ['product_id' => $product->id])->assertStatus(422)->assertJsonPath('code', 'cart_token_missing');
        $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.count', 0);

        $this->guestAdd($product, 2);
        $this->guest()->getJson('/api/cart')->assertOk()->assertJsonPath('data.count', 2);
        // Another browser sees nothing, and cannot touch this cart line
        $other = Str::random(40);
        $this->guest($other)->getJson('/api/cart')->assertJsonPath('data.count', 0);
        $lineId = Cart::where('guest_token', $this->cartToken)->value('id');
        $this->guest($other)->deleteJson("/api/cart/{$lineId}")->assertStatus(404);
        $this->guest()->putJson("/api/cart/{$lineId}", ['quantity' => 3])->assertOk()->assertJsonPath('data.count', 3);
        $this->assertNull(Cart::find($lineId)->user_id);
    }

    public function test_guest_cart_merges_into_the_account_after_login(): void
    {
        $seller = $this->seller();
        $a = $this->product($seller, 30, false, 5);
        $b = $this->product($seller, 40);

        $this->guestAdd($a, 2);
        $this->guestAdd($b, 1);
        $this->addToCart($a, 4); // already in the account's cart (stock is 5)

        $this->flushHeaders();
        $res = $this->as($this->customer)->withHeaders([GuestCheckout::CART_HEADER => $this->cartToken])
            ->postJson('/api/cart/merge')->assertOk();

        $this->assertSame(0, Cart::where('guest_token', $this->cartToken)->count());
        $this->assertSame(5, (int) Cart::where('user_id', $this->customer->id)->where('product_id', $a->id)->value('quantity'), 'summed, capped at stock');
        $this->assertSame(1, (int) Cart::where('user_id', $this->customer->id)->where('product_id', $b->id)->value('quantity'));
        $this->assertSame(6, $res->json('data.count'));
    }

    // ── placing orders ──────────────────────────────────────────────────────

    public function test_guest_single_seller_order(): void
    {
        $seller  = $this->seller();
        $product = $this->product($seller, 50, false, 10);
        $this->guestAdd($product, 2);

        $res = $this->placedGuestOrder(['email' => 'Amel.Guest@Example.com', 'notes' => 'Près de la mosquée']);

        $this->assertTrue($res['is_guest']);
        $this->assertSame(40, strlen($res['tracking_token']));
        $this->assertEquals(108.0, $res['total']); // 2 × 50 + 8 delivery
        $order = Order::find($res['order_id']);
        $this->assertNull($order->user_id);
        $this->assertTrue($order->is_guest);
        $this->assertSame('Amel Guest', $order->guest_name);
        $this->assertSame('22333444', $order->guest_phone);
        $this->assertSame('amel.guest@example.com', $order->guest_email);
        $this->assertSame('5 rue de la Kasbah', $order->address);       // address snapshot
        $this->assertSame('Près de la mosquée', $order->notes);
        $this->assertSame('cod', $order->payment_method);
        $this->assertSame('pending', $order->status);

        $this->assertParcel($this->parcel($order->id, $seller), ['items' => '100', 'delivery_fee' => '8', 'cod' => '108', 'commission' => '15']);
        $this->assertSame(8, (int) $product->fresh()->stock, 'stock reserved');
        $this->assertSame(0, Cart::where('guest_token', $this->cartToken)->count(), 'cart emptied');
        $this->assertSlipsMatch($order->id);
    }

    public function test_guest_multi_seller_order_is_split_per_seller(): void
    {
        [$s1, $s2] = [$this->seller(), $this->seller()];
        $this->guestAdd($this->product($s1, 30));
        $this->guestAdd($this->product($s2, 60));

        $res = $this->placedGuestOrder();

        $this->assertCount(2, $res['parcels']);
        $this->assertEquals(106.0, $res['total']); // 30 + 60 + 2 × 8
        $this->assertNotNull($this->parcel($res['order_id'], $s1));
        $this->assertNotNull($this->parcel($res['order_id'], $s2));
        $this->assertSlipsMatch($res['order_id']);
    }

    public function test_guest_buy_now(): void
    {
        $product = $this->product($this->seller(), 25, false, 3);
        $quote = $this->guest()->postJson('/api/checkout/quote', ['product_id' => $product->id, 'quantity' => 2])->assertOk()->json('data');
        $res = $this->guest()->postJson('/api/checkout/buy-now', $this->guestAddress() + [
            'product_id' => $product->id, 'quantity' => 2, 'payment_method' => 'cod', 'expected_total' => $quote['total'],
        ])->assertCreated()->json();

        $this->assertTrue(Order::find($res['order_id'])->is_guest);
        $this->assertSame(1, (int) $product->fresh()->stock);
    }

    public function test_server_reprices_and_refuses_a_stale_total(): void
    {
        $this->guestAdd($this->product($this->seller(), 50));
        $this->guest()->postJson('/api/checkout', $this->guestAddress() + ['payment_method' => 'cod', 'expected_total' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'price_changed');
    }

    public function test_guest_coupon_once_per_phone(): void
    {
        $seller  = $this->seller();
        $product = $this->product($seller, 100);
        $coupon  = Coupon::create(['seller_id' => $seller->id, 'code' => 'GST' . Str::upper(Str::random(5)), 'discount_type' => 'fixed',
            'discount_value' => 10, 'is_active' => true, 'usage_limit_per_customer' => 1]);
        $coupon->products()->attach($product->id);

        $this->guestAdd($product);
        $this->guest()->postJson('/api/coupons/validate', ['code' => $coupon->code, 'phone' => '22333444'])->assertOk();
        $res = $this->placedGuestOrder(['coupon_codes' => [$coupon->code]]);
        $this->assertEquals(98.0, $res['total']);
        $this->assertSame('22333444', DB::table('coupon_redemptions')->where('order_id', $res['order_id'])->value('guest_phone'));

        // Same phone, another browser: refused
        $other = Str::random(40);
        $this->guestAdd($product, 1, $other);
        $this->guest($other)->postJson('/api/coupons/validate', ['code' => $coupon->code, 'phone' => '+216 22 333 444'])->assertStatus(422);
        $this->guest($other)->postJson('/api/checkout/quote', ['phone' => '22333444', 'coupon_codes' => [$coupon->code]])->assertStatus(422);

        // An account's earlier order to a phone also counts for a guest using that phone
        $this->addToCart($product);
        $this->checkout(['coupon_codes' => [$coupon->code]]); // customer's address phone: 22123456
        $third = Str::random(40);
        $this->guestAdd($product, 1, $third);
        $this->guest($third)->postJson('/api/checkout/quote', ['phone' => '22123456', 'coupon_codes' => [$coupon->code]])->assertStatus(422);
        // A fresh phone may still use it
        $this->guest($third)->postJson('/api/checkout/quote', ['phone' => '55666777', 'coupon_codes' => [$coupon->code]])->assertOk();
    }

    public function test_targeted_coupon_is_refused_to_guests(): void
    {
        $seller  = $this->seller();
        $product = $this->product($seller, 100);
        $coupon  = Coupon::create(['seller_id' => $seller->id, 'code' => 'AUD' . Str::upper(Str::random(5)), 'discount_type' => 'fixed', 'discount_value' => 10, 'is_active' => true]);
        $coupon->products()->attach($product->id);
        DB::table('coupon_audiences')->insert(['coupon_id' => $coupon->id, 'user_id' => $this->customer->id, 'created_at' => now()]);

        $this->guestAdd($product);
        $this->guest()->postJson('/api/checkout/quote', ['phone' => '22333444', 'coupon_codes' => [$coupon->code]])->assertStatus(422);
    }

    public function test_guests_pay_cash_on_delivery_only(): void
    {
        $this->guestAdd($this->product($this->seller(), 50));
        foreach (['wallet', 'card', 'd17'] as $method) {
            $res = $this->guest()->postJson('/api/checkout', $this->guestAddress() + ['payment_method' => $method]);
            $this->assertSame(422, $res->status(), $method);
            $this->assertContains($res->json('code'), ['guest_cod_only', 'payment_method_unavailable'], $method);
        }
        $this->assertSame(0, Order::where('is_guest', true)->where('guest_phone', '22333444')->count());
    }

    public function test_at_most_n_pending_guest_orders_per_phone(): void
    {
        config(['guest_checkout.max_pending_per_phone' => 2]);
        $product = $this->product($this->seller(), 20);
        for ($i = 0; $i < 2; $i++) {
            $this->guestAdd($product);
            $this->placedGuestOrder();
        }
        $this->guestAdd($product);
        $this->guestCheckout()->assertStatus(429)->assertJsonPath('code', 'guest_too_many_pending');

        // Once the team confirms one, the buyer may order again
        Order::where('guest_phone', '22333444')->orderBy('id')->first()->update(['status' => 'confirmed']);
        $this->guestCheckout()->assertCreated();
        // Another phone is not affected
        $this->guestAdd($product);
        $this->guestCheckout([], '55666777')->assertCreated();
    }

    public function test_rate_limit_per_phone(): void
    {
        config(['guest_checkout.orders_per_phone_per_hour' => 2, 'guest_checkout.max_pending_per_phone' => 99]);
        $product = $this->product($this->seller(), 20);
        $this->guestAdd($product);
        $this->guestCheckout()->assertCreated();
        $this->guestAdd($product);
        $this->guestCheckout()->assertCreated();
        $this->guestAdd($product);
        $this->guestCheckout()->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    }

    public function test_signed_in_checkout_unchanged_and_a_bad_token_is_401(): void
    {
        $this->addToCart($this->product($this->seller(), 50));
        $res = $this->checkout();
        $order = Order::find($res['order_id']);
        $this->assertSame($this->customer->id, (int) $order->user_id);
        $this->assertFalse($order->is_guest);
        $this->assertNull($order->tracking_token);
        $this->assertArrayNotHasKey('tracking_token', $res);

        // Expired / revoked token: 401 (never a silent guest order)
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer 999|not-a-real-token', GuestCheckout::CART_HEADER => $this->cartToken])
            ->postJson('/api/checkout', $this->guestAddress() + ['payment_method' => 'cod'])->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer 999|not-a-real-token'])->getJson('/api/cart')->assertStatus(401);
    }

    // ── after ordering ──────────────────────────────────────────────────────

    public function test_confirmation_email_goes_to_the_guest_address_only_if_given(): void
    {
        Notification::fake();
        $product = $this->product($this->seller(), 50);

        $this->guestAdd($product);
        $res = $this->placedGuestOrder(['email' => 'buyer@example.com']);
        Notification::assertSentOnDemand(OrderPlacedNotification::class, function ($n, $channels, $notifiable) use ($res) {
            $url = (string) ($n->toMail($notifiable)->viewData['url'] ?? '');
            return $notifiable->routes['mail'] === 'buyer@example.com'
                && $n->order->id === $res['order_id']
                && str_ends_with($url, '/order/track/' . $res['tracking_token']);
        });

        Notification::fake();
        $this->guestAdd($product);
        $this->placedGuestOrder([], '55666777');
        Notification::assertNotSentTo(new \Illuminate\Notifications\AnonymousNotifiable, OrderPlacedNotification::class);
    }

    public function test_tracking_by_token_and_by_number_and_phone(): void
    {
        $seller = $this->seller();
        $this->guestAdd($this->product($seller, 50));
        $res   = $this->placedGuestOrder(['email' => 'x@example.com']);
        $order = Order::find($res['order_id']);

        $data = $this->flushHeaders()->getJson("/api/orders/track/{$res['tracking_token']}")->assertOk()->json('data');
        $this->assertSame($order->order_number, $data['order_number']);
        $this->assertSame('Amel', $data['recipient']);
        $this->assertSame('** *** 444', $data['phone_masked']);
        $this->assertCount(1, $data['parcels']);
        $this->assertSame('placed', $data['parcels'][0]['step']);
        $this->assertFalse($data['can_request_return']);
        $this->assertNull($data['whatsapp']);
        $json = json_encode($data);
        foreach (['5 rue de la Kasbah', 'x@example.com', '22333444'] as $private) {
            $this->assertStringNotContainsString($private, $json, "tracking page leaks {$private}");
        }

        // Lost link: order number + phone (any format) → same order + the token
        $this->postJson('/api/orders/track', ['order_number' => strtolower($order->order_number), 'phone' => '+216 22-333-444'])
            ->assertOk()->assertJsonPath('data.tracking_token', $res['tracking_token']);
        $this->postJson('/api/orders/track', ['order_number' => $order->order_number, 'phone' => '22999999'])->assertStatus(404);
        $this->getJson('/api/orders/track/' . Str::random(40))->assertStatus(404);

        // Account orders are never shown publicly
        $this->addToCart($this->product($seller, 20));
        $mine = Order::find($this->checkout()['order_id']);
        $this->flushHeaders();
        $this->postJson('/api/orders/track', ['order_number' => $mine->order_number, 'phone' => '22123456'])->assertStatus(404);
    }

    public function test_return_whatsapp_button_only_once_delivered(): void
    {
        $seller = $this->sellerWithPickup();
        $this->guestAdd($this->product($seller, 50));
        $res = $this->placedGuestOrder();
        $so  = $this->parcel($res['order_id'], $seller);

        $this->as($this->admin)->patchJson("/api/admin/orders/{$res['order_id']}/confirm-order", ['action' => 'confirmed'])->assertOk();
        $this->deliver($so);

        $data = $this->flushHeaders()->getJson("/api/orders/track/{$res['tracking_token']}")->assertOk()->json('data');
        $this->assertSame('delivered', $data['parcels'][0]['step']);
        $this->assertTrue($data['can_request_return']);
        $this->assertNotEmpty($data['whatsapp']);
    }

    // ── admin & seller side ─────────────────────────────────────────────────

    public function test_admin_confirmation_whatsapp_pdf_and_intigo_with_guest_name_and_phone(): void
    {
        $seller = $this->sellerWithPickup();
        $this->guestAdd($this->product($seller, 40));
        $res     = $this->placedGuestOrder(['email' => 'g@example.com']);
        $orderId = $res['order_id'];
        $so      = $this->parcel($orderId, $seller);

        // Same confirmation flow as account orders
        $this->as($this->admin)->patchJson("/api/admin/orders/{$orderId}/confirm-order", ['action' => 'confirmed'])->assertOk();
        $this->assertSame('confirmed', Order::find($orderId)->status);

        // Admin drawer: guest badge data, e-mail, tracking link, WhatsApp "notify seller" block
        $show = $this->as($this->admin)->getJson("/api/admin/orders/{$orderId}")->assertOk()->json('data');
        $this->assertTrue($show['is_guest']);
        $this->assertNull($show['user']);
        $this->assertSame('Amel Guest', $show['guest_name']);
        $this->assertSame('g@example.com', $show['guest_email']);
        $this->assertStringContainsString('/order/track/' . $res['tracking_token'], $show['tracking_url']);
        $this->assertNotNull($show['seller_orders'][0]['whatsapp']);
        $wa = app(SellerReminderPayload::class)->forParcel($this->so($so));
        $this->assertNotNull($wa);

        // PDF: delivery slips + internal summary
        $slip = $this->as($this->admin)->get("/api/admin/orders/{$orderId}/export/slips/{$so->id}")->assertOk();
        $this->assertStringStartsWith('%PDF', $slip->streamedContent());
        $summary = $this->as($this->admin)->get("/api/admin/orders/{$orderId}/export/summary")->assertOk();
        $this->assertStringStartsWith('%PDF', $summary->streamedContent());
        $recipient = app(\App\Services\Orders\DeliveryDocumentService::class)->recipient(Order::find($orderId));
        $this->assertSame('Amel Guest', $recipient['name']);
        $this->assertSame('+216 22 333 444', $recipient['phone']);

        // Intigo: the seller marks it prepared → parcel sent with the guest's name and phone
        $preview = app(ParcelPayload::class)->preview($this->so($so));
        $this->assertSame('Amel Guest', $preview['payload']['recipient_name']);
        $this->assertSame('22333444', $preview['payload']['phone1']);
        $this->prepare($so, $seller);
        $sent = $this->sentPayload($so);
        $this->assertNotNull($sent, 'parcel sent to Intigo');
        $this->assertSame('Amel Guest', $sent['recipient_name']);
        $this->assertSame('22333444', $sent['phone1']);
    }

    public function test_admin_filter_and_seller_view_without_leaks(): void
    {
        $seller = $this->seller();
        $this->guestAdd($this->product($seller, 40));
        $guestOrder = $this->placedGuestOrder(['email' => 'secret@example.com'])['order_id'];
        $this->addToCart($this->product($seller, 30));
        $accountOrder = $this->checkout()['order_id'];

        $guests = array_column($this->as($this->admin)->getJson('/api/admin/orders?buyer_type=guest&per_page=200')->assertOk()->json('data.data'), 'id');
        $this->assertContains($guestOrder, $guests);
        $this->assertNotContains($accountOrder, $guests);
        $registered = array_column($this->as($this->admin)->getJson('/api/admin/orders?buyer_type=registered&per_page=200')->json('data.data'), 'id');
        $this->assertContains($accountOrder, $registered);
        $this->assertNotContains($guestOrder, $registered);
        $search = array_column($this->as($this->admin)->getJson('/api/admin/orders?search=secret@example')->json('data.data'), 'id');
        $this->assertSame([$guestOrder], $search);

        // Seller: guest badge + name, never the e-mail or the tracking token
        $so   = $this->parcel($guestOrder, $seller);
        $list = collect($this->as($seller)->getJson('/api/seller/orders?per_page=50')->assertOk()->json('data.data') ?? $this->as($seller)->getJson('/api/seller/orders')->json('data'));
        $row  = $list->firstWhere('id', $so->id);
        $this->assertTrue($row['is_guest']);
        $this->assertSame('Amel Guest', $row['customer_name']);
        $detail = $this->as($seller)->getJson("/api/seller/orders/{$so->id}")->assertOk();
        $this->assertSame('Amel Guest', $detail->json('data.order.customer.name'));
        $this->assertStringNotContainsString('secret@example.com', $detail->getContent());
        $this->assertStringNotContainsString((string) Order::find($guestOrder)->tracking_token, $detail->getContent());
        $drawer = $this->as($seller)->getJson("/api/seller/earnings/orders/{$so->id}/details")->assertOk();
        $this->assertSame('Amel Guest', $drawer->json('data.customer.name'));
    }

    // ── account after ordering ──────────────────────────────────────────────

    public function test_signup_after_a_guest_order_takes_it_over(): void
    {
        $this->guestAdd($this->product($this->seller(), 40));
        $res = $this->placedGuestOrder(['email' => 'newbuyer@example.com']);
        $this->assertFalse($res['has_account']);

        $this->flushHeaders();
        $this->postJson('/api/auth/register', [
            'name' => 'Amel Guest', 'email' => 'newbuyer@example.com', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
            'guest_tracking_token' => $res['tracking_token'],
        ])->assertStatus(201);
        $code = Cache::get('pending_reg:newbuyer@example.com')['code'];
        $verified = $this->postJson('/api/auth/verify-email', ['email' => 'newbuyer@example.com', 'code' => $code])->assertOk();

        $user  = User::where('email', 'newbuyer@example.com')->first();
        $order = Order::find($res['order_id']);
        $this->assertSame($user->id, (int) $order->user_id);
        $this->assertTrue($order->is_guest, 'still marked as placed without an account');

        // Now listed under the account's orders
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $mine = $this->withHeaders(['Authorization' => 'Bearer ' . $verified->json('token')])->getJson('/api/client/orders')->json();
        $this->assertStringContainsString($order->order_number, json_encode($mine));
    }

    public function test_existing_account_is_never_auto_attached(): void
    {
        $this->guestAdd($this->product($this->seller(), 40));
        $res = $this->placedGuestOrder(['email' => $this->customer->email], '22123456');

        $this->assertTrue($res['has_account']);
        $this->assertNull(Order::find($res['order_id'])->user_id);
        // Signing up again with that e-mail is impossible (it exists), so nothing gets attached
        $this->flushHeaders();
        $this->postJson('/api/auth/register', [
            'name' => 'X', 'email' => $this->customer->email, 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
            'guest_tracking_token' => $res['tracking_token'],
        ])->assertStatus(422);
        $this->assertNull(Order::find($res['order_id'])->user_id);
    }

    public function test_existing_account_adds_the_guest_order_only_on_request_and_only_if_it_matches(): void
    {
        $this->guestAdd($this->product($this->seller(), 40));
        $res = $this->placedGuestOrder([], '22123456'); // the customer's own phone
        $this->assertNull(Order::find($res['order_id'])->user_id, 'never automatic');

        // Someone else with the link: refused
        $stranger = $this->makeUser('client');
        $stranger->forceFill(['phone' => '55999888'])->save();
        $this->flushHeaders();
        $this->as($stranger)->postJson("/api/orders/track/{$res['tracking_token']}/claim")->assertStatus(403)->assertJsonPath('code', 'not_yours');
        $this->assertNull(Order::find($res['order_id'])->user_id);

        // The account whose phone it was: added
        $this->flushHeaders();
        $this->as($this->customer)->postJson("/api/orders/track/{$res['tracking_token']}/claim")->assertOk();
        $this->assertSame($this->customer->id, (int) Order::find($res['order_id'])->user_id);
        $this->flushHeaders();
        $this->getJson("/api/orders/track/{$res['tracking_token']}")->assertJsonPath('data.claimed', true);

        // Not signed in: no claim at all
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/orders/track/{$res['tracking_token']}/claim")->assertStatus(401);
    }

    // ── sponsoring ──────────────────────────────────────────────────────────

    public function test_ad_click_by_the_guest_session_is_credited(): void
    {
        $seller    = $this->seller();
        $sponsored = $this->product($seller, 50);
        $campaign  = Sponsorship::create([
            'seller_id' => $seller->id, 'product_id' => $sponsored->id, 'status' => 'active',
            'pricing_model' => 'cpc', 'daily_budget' => 5, 'max_cpc' => 0.3,
        ]);
        $sid = (string) Str::uuid();
        SponsorshipEvent::create([
            'sponsorship_id' => $campaign->id, 'event' => SponsorshipEvent::CLICK, 'placement' => 'search', 'request_id' => (string) Str::uuid(),
            'session_id' => $sid, 'cost' => 0.3, 'billable' => true, 'countable' => true, 'created_at' => now()->subMinute(),
        ]);

        $this->guestAdd($sponsored);
        $quote = $this->guest()->postJson('/api/checkout/quote', [])->json('data');
        $res = $this->guest()->withHeaders(['X-Session-Id' => $sid])->postJson('/api/checkout', $this->guestAddress() + [
            'payment_method' => 'cod', 'expected_total' => $quote['total'],
        ])->assertCreated()->json();

        $this->assertTrue(DB::table('order_ad_attributions')->where('order_id', $res['order_id'])->where('sponsorship_id', $campaign->id)->exists());
    }

    /** The delivery company's slip shows exactly the parcel's COD amount. */
    private function assertSlipsMatch(int $orderId): void
    {
        $docs  = app(\App\Services\Orders\DeliveryDocumentService::class);
        $order = $docs->query()->findOrFail($orderId);
        $this->assertSame(self::m($order->total_amount), collect($docs->slipsFor($order))->sum(fn ($s) => self::m($s['money']['cod'])));
    }
}
