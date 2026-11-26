<?php

namespace Tests\Feature\Delivery;

use App\Models\Cart;
use App\Models\Complaint;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCommissionOverride;
use App\Models\SellerAdjustment;
use App\Models\SellerOrder;
use App\Models\User;
use App\Notifications\Buyer\OrderStatusNotification;
use App\Services\Delivery\DeliveryMode;
use App\Services\Delivery\DeliverySettings;
use App\Services\Intigo\ParcelPayload;
use App\Services\Orders\DeliveryDocumentService;
use App\Services\Pricing\PricingService;
use App\Services\Returns\ReturnPolicy;
use App\Support\GuestCheckout;
use App\Support\Millimes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per-seller delivery fee mode. Client fee S 9 / L 11 / XL 17; Intigo S 7 / L 8 / XL 14.
 * Commission: free plan, 15 % up to 100 DT.
 *
 * Run:  php vendor/phpunit/phpunit/phpunit tests/Feature/Delivery/SeparateDeliveryModeTest.php
 */
class SeparateDeliveryModeTest extends TestCase
{
    use DatabaseTransactions, DeliveryTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('public');
        config(['intigo.mode' => 'fake']);
        $this->setUpDelivery();
        $sizes = [];
        foreach (['S' => [9, 7], 'L' => [11, 8], 'XL' => [17, 14]] as $size => [$client, $agency]) {
            $sizes[$size] = [
                'client_fee' => Millimes::of($client), 'agency_cost' => Millimes::of($agency), 'return_fee' => Millimes::of('4.280'),
                'after_sale_use_agency_cost' => false, 'after_sale_return_fee' => Millimes::of('4.280'), 'exchange_fee' => Millimes::of('9'),
            ];
        }
        app(DeliverySettings::class)->update(['sizes' => $sizes], $this->admin->id);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function item(User $seller, $price, string $size = 'S'): Product
    {
        $name = 'Mode Product ' . Str::random(8);
        return Product::create([
            'seller_id' => $seller->id, 'name' => $name, 'slug' => Str::slug($name), 'price' => $price,
            'parcel_size' => $size, 'stock' => 50, 'is_approved' => true, 'is_active' => true,
        ]);
    }

    private function separate(?User $seller = null): User
    {
        $seller ??= $this->seller('free');
        $this->switchMode([$seller->id], 'separate')->assertOk();
        return $seller;
    }

    private function switchMode(array $ids, string $mode): \Illuminate\Testing\TestResponse
    {
        return $this->as($this->admin)->postJson('/api/admin/sellers/delivery-mode', ['seller_ids' => $ids, 'mode' => $mode]);
    }

    private function public()
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        return $this;
    }

    private function cod(int $soId): float
    {
        return (float) app(ParcelPayload::class)->preview(SellerOrder::find($soId))['payload']['price'];
    }

    private function delivered(int $orderId): Order
    {
        DB::table('seller_orders')->where('order_id', $orderId)->update(['status' => 'delivered']);
        DB::table('orders')->where('id', $orderId)->update(['status' => 'delivered', 'updated_at' => now()]);
        return Order::findOrFail($orderId);
    }

    private function lines(int $orderId): \Illuminate\Support\Collection
    {
        return DB::table('order_items')->where('order_id', $orderId)->orderBy('id')->get()->keyBy('product_id');
    }

    private function fileReturn(Order $order, array $payload, string $type): \Illuminate\Testing\TestResponse
    {
        return $this->as($this->customer)->post('/api/client/complaints', $payload + [
            'order_id' => $order->id, 'complaint_type' => $type,
            'description' => 'Detailed reason for this return request, long enough.',
            'images' => [UploadedFile::fake()->image('proof.jpg', 400, 300), UploadedFile::fake()->image('proof2.jpg', 400, 300)],
        ], ['Accept' => 'application/json']);
    }

    /** Request → approved → courier refunds at pick-up. */
    private function returned(Order $order, User $seller, array $payload, string $type, array $approve = []): Complaint
    {
        $c = Complaint::findOrFail($this->fileReturn($order, $payload, $type)->assertCreated()->json('data.id'));
        $this->as($seller)->patchJson("/api/seller/complaints/{$c->id}/approve", ['seller_note' => 'ok'])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/approve", $approve)->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/schedule-pickup", ['carrier' => 'Intigo'])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/picked-up")->assertOk();
        return $c->fresh();
    }

    private function charges(Complaint $c): array
    {
        return SellerAdjustment::where('complaint_id', $c->id)->pluck('amount', 'type')->map(fn ($v) => self::m($v))->all();
    }

    /** One separate seller, T-shirt 30 (S) + shoes 80 (L) + table 90 (XL). */
    private function threeSizes(): array
    {
        $s = $this->separate();
        $items = [$this->item($s, 30, 'S'), $this->item($s, 80, 'L'), $this->item($s, 90, 'XL')];
        foreach ($items as $p) {
            $this->addToCart($p);
        }
        return [$s, ...$items];
    }

    // ── Admin switch ──────────────────────────────────────────────────────────

    public function test_every_seller_is_included_by_default_and_switches_are_logged(): void
    {
        $a = $this->seller('free');
        $b = $this->seller('free');
        $this->assertSame('included', DB::table('users')->where('id', $a->id)->value('delivery_fee_mode'));

        $res = $this->switchMode([$a->id, $b->id, $this->customer->id], 'separate')->assertOk();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $res->json('data.changed'), 'only sellers are switched');
        $this->switchMode([$a->id], 'separate')->assertOk()->assertJsonPath('data.changed', []);

        $log = DB::table('seller_delivery_mode_changes')->where('seller_id', $a->id)->get();
        $this->assertCount(1, $log);
        $this->assertSame(['included', 'separate', $this->admin->id], [$log[0]->old_mode, $log[0]->new_mode, (int) $log[0]->changed_by]);

        $show = $this->as($this->admin)->getJson("/api/admin/sellers/{$a->id}")->assertOk()->json('data');
        $this->assertSame('separate', $show['delivery_fee_mode']);
        $this->assertSame($this->admin->name, $show['delivery_mode_history'][0]['changed_by_name']);

        $ids = collect($this->as($this->admin)->getJson('/api/admin/sellers?delivery_mode=separate&per_page=100')->assertOk()->json('data.data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids->all());
    }

    public function test_only_an_admin_can_switch_a_seller(): void
    {
        $seller = $this->seller('free');
        $this->as($seller)->postJson('/api/admin/sellers/delivery-mode', ['seller_ids' => [$seller->id], 'mode' => 'separate'])->assertForbidden();
        $this->public()->postJson('/api/admin/sellers/delivery-mode', ['seller_ids' => [$seller->id], 'mode' => 'separate'])->assertUnauthorized();
        $this->switchMode([$seller->id], 'free')->assertStatus(422);
        // Not mass assignable
        $seller->fill(['delivery_fee_mode' => 'separate'])->save();
        $this->assertSame('included', DB::table('users')->where('id', $seller->id)->value('delivery_fee_mode'));
    }

    // ── Prices ────────────────────────────────────────────────────────────────

    public function test_separate_products_show_the_base_price_and_the_delivery_apart(): void
    {
        $inc = $this->seller('free');
        $sep = $this->separate();
        $i = $this->item($inc, 30, 'S');
        $s = $this->item($sep, 30, 'L');

        $shown = $this->public()->getJson("/api/products/{$i->slug}")->assertOk()->json('data');
        $this->assertEquals([39.0, true], [$shown['final_price'], $shown['free_delivery']]);
        $this->assertArrayNotHasKey('delivery_charge', $shown);

        $shown = $this->public()->getJson("/api/products/{$s->slug}")->assertOk()->json('data');
        $this->assertEquals([30.0, 30.0, false, 11.0], [$shown['price'], $shown['final_price'], $shown['free_delivery'], $shown['delivery_charge']]);

        // SQL filter and sort agree with the displayed prices: 30 (separate) < 39 (included)
        $list = fn (string $q) => collect($this->public()->getJson("/api/products?{$q}&per_page=50")->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertSame([$s->id, $i->id], array_values(array_intersect($list('sort=price_asc'), [$i->id, $s->id])));
        $this->assertContains($s->id, $list('price_max=30'));
        $this->assertNotContains($i->id, $list('price_max=38.999'));

        // Mode off again: back to the included price at once
        $this->switchMode([$sep->id], 'included')->assertOk();
        $this->assertEquals(41.0, $this->public()->getJson("/api/products/{$s->slug}")->json('data.final_price'));
    }

    public function test_pack_of_a_separate_seller_is_priced_without_the_fee(): void
    {
        $sep = $this->separate();
        $pack = $this->pack($sep, 90, [[$this->item($sep, 30, 'S'), 1], [$this->item($sep, 80, 'L'), 1]]);

        $shown = $this->public()->getJson("/api/packs/{$pack->slug}")->assertOk()->json('data');
        $this->assertEquals([90.0, 110.0, false, 11.0], [$shown['pack_price'], $shown['original_price'], $shown['free_delivery'], $shown['delivery_charge']]);

        $this->addPack($pack);
        $quote = $this->quote();
        $this->assertEquals([90.0, 11.0, 101.0, 0.0], [$quote['subtotal'], $quote['delivery_fee'], $quote['total'], $quote['multi_item_discount']]);
        $res = $this->checkout();
        $so = $this->parcel($res['order_id'], $sep);
        $this->assertParcel($so, ['items' => '90', 'delivery_fee' => '11', 'cod' => '101', 'agency' => '8', 'margin' => '3']);
        $this->assertSame(101.0, $this->cod($so->id));
    }

    // ── Included only: unchanged ──────────────────────────────────────────────

    public function test_an_included_order_is_unchanged_even_while_another_seller_is_separate(): void
    {
        $this->separate();   // somebody else is in separate mode
        $inc = $this->seller('free');
        $this->addToCart($this->item($inc, 30, 'S'));
        $this->addToCart($this->item($inc, 80, 'L'));

        $quote = $this->quote();
        $this->assertEquals([130.0, 9.0, 0.0, 121.0, true], [$quote['subtotal'], $quote['multi_item_discount'], $quote['delivery_fee'], $quote['total'], $quote['free_delivery']]);
        $res = $this->checkout();
        $so = $this->parcel($res['order_id'], $inc);
        $this->assertParcel($so, ['items' => '110', 'delivery_fee' => '11', 'cod' => '121', 'commission' => '16.5', 'payout' => '93.5', 'agency' => '8', 'margin' => '3']);
        $this->assertSame(['included', 'included'], [$so->pricing_model, $so->delivery_mode]);
        $this->assertSame([0, self::m('8')], [self::m($so->delivery_fee_charged), self::m($so->delivery_cost)]);
        $this->assertSame(121.0, $this->cod($so->id));
        $order = $this->as($this->customer)->getJson('/api/client/orders/' . $res['order_id'])->assertOk()->json('data');
        $this->assertEquals([130.0, 0.0, true, 121.0], [$order['subtotal'], $order['shipping_fee'], $order['free_delivery'], $order['total_amount']]);
    }

    // ── Separate: one fee per parcel ──────────────────────────────────────────

    public function test_separate_single_item(): void
    {
        $sep = $this->separate();
        $this->addToCart($this->item($sep, 30, 'S'));

        $quote = $this->quote();
        $parcel = $quote['parcels'][0];
        $this->assertEquals([30.0, 9.0, 39.0, 0.0, false], [$quote['subtotal'], $quote['delivery_fee'], $quote['total'], $quote['multi_item_discount'], $quote['free_delivery']]);
        $this->assertEquals([30.0, false, 9.0, 39.0, 39.0], [$parcel['lines'][0]['unit_price'], $parcel['is_free_delivery'], $parcel['delivery_fee'], $parcel['total'], $parcel['cod_amount']]);
        $this->assertNull($parcel['upsell_min_saving'], 'no multi-item hint in separate mode');

        $cart = $this->as($this->customer)->getJson('/api/cart')->assertOk()->json('data');
        $this->assertEquals([30.0, 9.0, 39.0, false], [$cart['summary']['subtotal'], $cart['summary']['delivery_fee'], $cart['summary']['total'], $cart['free_delivery']]);
        $this->assertEquals(9.0, $cart['items'][0]['delivery_charge']);

        $res = $this->checkout();
        $this->assertFalse($res['free_delivery']);
        $so = $this->parcel($res['order_id'], $sep);
        $this->assertParcel($so, ['items' => '30', 'delivery_fee' => '9', 'cod' => '39', 'commission' => '4.5', 'payout' => '25.5', 'agency' => '7', 'margin' => '2']);
        $this->assertSame(['separate', 'separate'], [$so->pricing_model, $so->delivery_mode]);
        $this->assertSame([self::m('9'), self::m('7')], [self::m($so->delivery_fee_charged), self::m($so->delivery_cost)]);
        $this->assertNull($so->included_fees_total);
        $this->assertSame(39.0, $this->cod($so->id));
    }

    public function test_three_sizes_of_one_seller_pay_one_fee_of_the_largest(): void
    {
        [$sep] = $this->threeSizes();

        $quote = $this->quote();
        $this->assertEquals([200.0, 17.0, 217.0, 0.0], [$quote['subtotal'], $quote['delivery_fee'], $quote['total'], $quote['multi_item_discount']]);
        $res = $this->checkout();
        $so = $this->parcel($res['order_id'], $sep);
        $this->assertSame('XL', $so->parcel_size);
        $this->assertParcel($so, ['items' => '200', 'delivery_fee' => '17', 'cod' => '217', 'commission' => '30', 'payout' => '170', 'agency' => '14', 'margin' => '3']);
        $this->assertSame(217.0, $this->cod($so->id));

        $order = $this->as($this->customer)->getJson('/api/client/orders/' . $res['order_id'])->assertOk()->json('data');
        $this->assertEquals([200.0, 17.0, false, 217.0, 0.0], [$order['subtotal'], $order['shipping_fee'], $order['free_delivery'], $order['total_amount'], $order['multi_item_discount']]);
        $this->assertEquals(17.0, $order['seller_groups'][0]['delivery_fee']);
    }

    // ── Mixed cart ────────────────────────────────────────────────────────────

    private function mixed(): array
    {
        $inc = $this->seller('free');
        $a = $this->separate();
        $b = $this->separate();
        foreach ([[$inc, 30, 'S'], [$inc, 80, 'L'], [$a, 50, 'S'], [$a, 60, 'L'], [$b, 40, 'XL']] as [$seller, $price, $size]) {
            $this->addToCart($this->item($seller, $price, $size));
        }
        return [$inc, $a, $b];
    }

    public function test_mixed_cart_included_and_two_separate_sellers(): void
    {
        [$inc, $a, $b] = $this->mixed();

        $cart = $this->as($this->customer)->getJson('/api/cart')->assertOk()->json('data.summary');
        // display 130 (included) + 110 + 40, minus the included multi-item 9, plus 11 + 17
        $this->assertEquals([280.0, 9.0, 28.0, 299.0], [$cart['subtotal'], $cart['multi_item_discount'], $cart['delivery_fee'], $cart['total']]);
        $shops = collect($cart['shops'])->keyBy('seller_id');
        $this->assertEquals([true, 0.0], [$shops[$inc->id]['free_delivery'], $shops[$inc->id]['delivery_fee']]);
        $this->assertEquals([false, 11.0], [$shops[$a->id]['free_delivery'], $shops[$a->id]['delivery_fee']]);
        $this->assertEquals([false, 17.0], [$shops[$b->id]['free_delivery'], $shops[$b->id]['delivery_fee']]);

        $quote = $this->quote();
        $this->assertEquals([280.0, 28.0, 299.0], [$quote['subtotal'], $quote['delivery_fee'], $quote['total']]);
        $res = $this->checkout();

        $expect = [$inc->id => [121.0, 'included', '93.5'], $a->id => [121.0, 'separate', '93.5'], $b->id => [57.0, 'separate', '34']];
        foreach ([$inc, $a, $b] as $seller) {
            $so = $this->parcel($res['order_id'], $seller);
            [$cod, $mode, $payout] = $expect[$seller->id];
            $this->assertSame($cod, $this->cod($so->id), 'COD per parcel');
            $this->assertSame($mode, $so->delivery_mode);
            $this->assertSame(self::m($payout), self::m($so->seller_net_amount), 'payout: items − commission in both modes');
            $this->assertBalanced($so);
        }
        $order = Order::find($res['order_id']);
        $this->assertSame(self::m('299'), self::m($order->total_amount));

        $shown = $this->as($this->customer)->getJson('/api/client/orders/' . $order->id)->assertOk()->json('data');
        $this->assertEquals([280.0, 28.0, 9.0, false, 299.0], [$shown['subtotal'], $shown['shipping_fee'], $shown['multi_item_discount'], $shown['free_delivery'], $shown['total_amount']]);
        $this->assertEquals($shown['total_amount'], $shown['subtotal'] - $shown['multi_item_discount'] + $shown['shipping_fee']);

        // Cancellation e-mail of one separate parcel includes its fee
        $soB = $this->parcel($order->id, $b);
        $this->moveTo($soB, 'cancelled');
        $amount = new \ReflectionMethod(OrderStatusNotification::class, 'cancelledAmount');
        $amount->setAccessible(true);
        $this->assertSame(57.0, $amount->invoke(new OrderStatusNotification($order->fresh(), 'cancelled', [$soB->id])));
        $shown = $this->as($this->customer)->getJson('/api/client/orders/' . $order->id)->assertOk()->json('data');
        $this->assertEquals([11.0, 242.0], [$shown['shipping_fee'], $shown['total_amount']]);
    }

    // ── Discounts and commission never touch delivery ─────────────────────────

    public function test_promotion_flash_coupon_and_special_commission_same_money_in_both_modes(): void
    {
        $run = function (string $mode): array {
            Cart::where('user_id', $this->customer->id)->delete();
            $seller = $this->seller('free');
            if ($mode === 'separate') {
                $this->separate($seller);
            }
            $promoted = $this->item($seller, 100, 'S');
            $flash    = $this->item($seller, 60, 'S');
            $couponed = $this->item($seller, 50, 'L');
            $special  = $this->item($seller, 80, 'S');
            $this->promotion($promoted, 'discount', 20);
            $this->promotion($flash, 'flash_sale', 50);
            $coupon = Coupon::create(['seller_id' => $seller->id, 'code' => 'M' . Str::upper(Str::random(7)), 'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true, 'usage_count' => 0]);
            $coupon->products()->attach([$couponed->id]);
            ProductCommissionOverride::create(['product_id' => $special->id, 'seller_id' => $seller->id, 'type' => 'fixed_rate', 'value' => 5,
                'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true, 'created_by' => $this->admin->id]);
            foreach ([$promoted, $flash, $couponed, $special] as $p) {
                $this->addToCart($p);
            }
            $res = $this->checkout(['coupon_codes' => [$coupon->code]]);
            return [$res, $this->parcel($res['order_id'], $seller)];
        };

        [$resI, $inc] = $run('included');
        $this->travel(2)->minutes();
        [$resS, $sep] = $run('separate');

        foreach (['subtotal', 'discount_amount', 'commission_amount', 'seller_net_amount', 'delivery_fee', 'cod_amount', 'shipping_cost', 'platform_delivery_margin'] as $col) {
            $this->assertSame(self::m($inc->{$col}), self::m($sep->{$col}), "{$col} is identical in both modes");
        }
        // 80 + 30 + 50 + 80 = 240; coupon 5; L fee 11
        $this->assertParcel($sep, ['items' => '240', 'coupon' => '5', 'delivery_fee' => '11', 'cod' => '246']);
        $this->assertEquals($resI['total'], $resS['total']);
        $this->assertEquals(11.0, $resS['delivery_fee']);
        // Special commission: 5 % of 80, never of the fee
        $line = DB::table('order_items')->where('seller_order_id', $sep->id)->whereNotNull('commission_override_id')->first();
        $this->assertSame(self::m('4'), self::m($line->commission_amount));
    }

    // ── Guest, stale cart ─────────────────────────────────────────────────────

    public function test_guest_checkout_charges_the_delivery_the_same_way(): void
    {
        config(['guest_checkout.enabled' => true]);
        \App\Models\PlatformSetting::where('key', GuestCheckout::SETTING_KEY)->delete();
        \App\Models\PlatformSetting::flushCache();
        $sep = $this->separate();
        $p = $this->item($sep, 30, 'S');
        $q = $this->item($sep, 80, 'L');
        $token = Str::random(40);
        $guest = fn () => $this->public()->withHeaders([GuestCheckout::CART_HEADER => $token]);

        $guest()->postJson('/api/cart', ['product_id' => $p->id, 'quantity' => 1])->assertOk();
        $cart = $guest()->postJson('/api/cart', ['product_id' => $q->id, 'quantity' => 1])->assertOk()->json('data');
        $this->assertEquals([110.0, 11.0, 121.0], [$cart['summary']['subtotal'], $cart['summary']['delivery_fee'], $cart['summary']['total']]);
        $quote = $guest()->postJson('/api/checkout/quote', ['phone' => '22333444'])->assertOk()->json('data');
        $res = $guest()->postJson('/api/checkout', [
            'recipient_name' => 'Guest Buyer', 'wilaya' => 'Tunis', 'delegation' => 'Bab Bhar', 'address' => '1 rue Test',
            'postal_code' => '1000', 'phone' => '22333444', 'payment_method' => 'cod', 'expected_total' => $quote['total'],
        ])->assertCreated()->json();
        $this->assertEquals([121.0, 11.0], [$res['total'], $res['delivery_fee']]);
        $this->assertSame(121.0, $this->cod($this->parcel($res['order_id'], $sep)->id));

        $track = $this->public()->getJson('/api/orders/track/' . $res['tracking_token'])->assertOk()->json('data');
        $this->assertEquals([110.0, 11.0, false, 121.0], [$track['subtotal'], $track['shipping_fee'], $track['free_delivery'], $track['total']]);
    }

    public function test_a_stale_cart_is_repriced_by_the_server(): void
    {
        $seller = $this->seller('free');
        $this->addToCart($this->item($seller, 30, 'S'));
        $this->assertEquals(39.0, $this->quote()['total']);

        $this->separate($seller);
        $quote = $this->quote();
        $this->assertEquals([30.0, 9.0, 39.0], [$quote['subtotal'], $quote['delivery_fee'], $quote['total']]);
        // A client figure is never charged
        $this->as($this->customer)->postJson('/api/checkout', $this->address() + ['payment_method' => 'cod', 'expected_total' => 30])
            ->assertStatus(409);
    }

    // ── Finance and documents ─────────────────────────────────────────────────

    public function test_finance_settlement_and_seller_payloads(): void
    {
        [$inc, $a, $b] = $this->mixed();
        $res = $this->checkout();
        $parcels = collect([$inc, $a, $b])->map(fn ($s) => $this->parcel($res['order_id'], $s));
        foreach ($parcels as $so) {
            $this->deliver($so);
            $this->confirmRemittance($so)->assertOk();
        }

        $kpis = $this->as($this->admin)->getJson('/api/admin/finance/overview?period=all&seller_id=' . $a->id)->assertOk()->json('data.kpis');
        $this->assertEquals(['parcels' => 1, 'fees_collected' => 11, 'charged_apart' => 11, 'intigo_cost' => 8, 'margin' => 3], $kpis['delivery_by_mode']['separate']);
        $kpis = $this->as($this->admin)->getJson('/api/admin/finance/overview?period=all&seller_id=' . $inc->id)->assertOk()->json('data.kpis');
        $this->assertArrayNotHasKey('delivery_by_mode', $kpis);

        $rows = collect($this->as($this->admin)->getJson('/api/admin/finance/orders?per_page=100')->assertOk()->json('data.data'))->keyBy('seller_id');
        $this->assertSame('separate', $rows[$a->id]['delivery_mode']);
        $this->assertEquals([11.0, 8.0], [$rows[$a->id]['delivery_fee_charged'], $rows[$a->id]['delivery_cost']]);
        $this->assertArrayNotHasKey('delivery_mode', $rows[$inc->id]);

        $details = $this->as($this->admin)->getJson("/api/admin/finance/orders/{$parcels[1]->id}/details")->assertOk()->json('data.financials');
        $this->assertSame('separate', $details['delivery_mode']);

        $admin = collect($this->as($this->admin)->getJson('/api/admin/orders/' . $res['order_id'])->assertOk()->json('data.seller_orders'))->keyBy('seller_id');
        $this->assertEquals(['separate', 17.0, 14.0], [$admin[$b->id]['delivery_mode'], $admin[$b->id]['delivery_fee_charged'], $admin[$b->id]['delivery_cost']]);
        $this->assertArrayNotHasKey('delivery_mode', $admin[$inc->id]);

        // Settlement: the seller is paid items − commission, and never sees a delivery fee
        $batch = $this->as($this->admin)->postJson('/api/admin/settlements/create', ['seller_id' => $a->id, 'batch_date' => now()->toDateString()])->assertOk()->json('data');
        $this->assertSame([self::m('93.5'), self::m('11')], [self::m($batch['total_seller_payout']), self::m($batch['total_delivery_fees'])]);
        foreach ([
            '/api/seller/earnings/orders', '/api/seller/earnings/history', '/api/seller/earnings/receipt',
            "/api/seller/earnings/settlement/{$batch['id']}", '/api/seller/orders', "/api/seller/orders/{$parcels[1]->id}",
            "/api/seller/earnings/orders/{$parcels[1]->id}/details", '/api/seller/earnings/overview?period=all',
        ] as $url) {
            $body = $this->as($a)->getJson($url)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/delivery_fee|delivery_cost|delivery_mode"/', $body, $url);
        }
    }

    public function test_order_summary_pdf_shows_the_mode_only_when_a_parcel_is_separate(): void
    {
        $html = function (int $orderId): string {
            $order = Order::find($orderId)->load('sellerOrders.items', 'sellerOrders.seller', 'exports');
            $captured = null;
            \Illuminate\Support\Facades\View::composer('pdf.order-summary', function ($view) use (&$captured) {
                $captured ??= $view->getData();
            });
            app(DeliveryDocumentService::class)->summaryPdf($order);
            return strip_tags(view('pdf.order-summary', $captured)->render());
        };

        $inc = $this->seller('free');
        $this->addToCart($this->item($inc, 30, 'S'));
        $this->assertStringNotContainsString('Delivery mode', $html($this->checkout()['order_id']));

        $this->travel(2)->minutes();
        $sep = $this->separate();
        $this->addToCart($this->item($sep, 30, 'S'));
        $text = $html($this->checkout()['order_id']);
        $this->assertStringContainsString('Delivery mode', $text);
        $this->assertStringContainsString('Separate', $text);
    }

    // ── Switching never touches past orders ───────────────────────────────────

    public function test_switching_modes_leaves_past_orders_alone(): void
    {
        $seller = $this->seller('free');
        $p = $this->item($seller, 30, 'S');
        $this->addToCart($p);
        $first = $this->checkout()['order_id'];
        $this->travel(2)->minutes();
        $this->separate($seller);
        $this->addToCart($p, 2);
        $second = $this->checkout()['order_id'];

        $snapshot = fn () => [
            (array) $this->parcel($first, $seller), (array) $this->parcel($second, $seller),
            $this->lines($first)->map(fn ($l) => (array) $l)->all(),
            $this->as($this->customer)->getJson("/api/client/orders/{$first}")->json('data.total_amount'),
        ];
        $before = $snapshot();
        $this->switchMode([$seller->id], 'included')->assertOk();
        $this->assertEquals($before, $snapshot());

        $this->assertSame('included', $this->parcel($first, $seller)->delivery_mode);
        $this->assertSame('separate', $this->parcel($second, $seller)->delivery_mode);
        $shown = $this->as($this->customer)->getJson("/api/client/orders/{$second}")->json('data');
        $this->assertEquals([60.0, 9.0, 69.0], [$shown['subtotal'], $shown['shipping_fee'], $shown['total_amount']]);
        $this->assertNull(app(PricingService::class)->recomputeParcel($this->parcel($second, $seller)->id));
    }

    // ── Returns ───────────────────────────────────────────────────────────────

    private function separateDelivered(): array
    {
        [$seller, $tshirt, $shoes, $table] = $this->threeSizes();
        $order = $this->delivered($this->checkout()['order_id']);
        return [$seller, $order, $this->lines($order->id), $tshirt, $shoes, $table];
    }

    public function test_one_item_of_a_separate_parcel_refunds_the_product_only(): void
    {
        foreach (['damaged_product', 'changed_mind'] as $type) {
            $this->travel(2)->minutes();
            [$seller, $order, $lines, , , $table] = $this->separateDelivered();
            $c = $this->returned($order, $seller, ['items' => [['order_item_id' => $lines[$table->id]->id, 'quantity' => 1]]], $type);

            // The XL item: 90, minus the client-paid return fee when it is their choice; never the delivery
            $expected = $type === 'changed_mind' ? '85.720' : '90';
            $this->assertSame(self::m($expected), self::m($c->refund_amount), $type);
            $so = $this->parcel($order->id, $seller);
            $this->assertSame(self::m('17'), self::m($so->delivery_fee), 'the fee stays');
            $this->assertSame(self::m('93.5'), self::m($so->seller_net_amount), '(30 + 80) − 15 %');
            $this->assertArrayNotHasKey(SellerAdjustment::TYPE_RETURN_PARCEL_FEE, $this->charges($c));
            $this->assertArrayNotHasKey(SellerAdjustment::TYPE_RETURN_ITEM_FEE, $this->charges($c));
        }
    }

    public function test_full_return_seller_fault_refunds_the_delivery_and_charges_the_seller(): void
    {
        [$seller, $order] = $this->separateDelivered();
        $c = $this->returned($order, $seller, ['return_all' => 1], 'damaged_product');

        $this->assertSame(self::m('217'), self::m($c->refund_amount), 'everything paid, delivery included');
        $this->assertSame(self::m('217'), self::m($c->items_amount));
        $so = $this->parcel($order->id, $seller);
        $this->assertSame([0, 0, 0], [self::m($so->delivery_fee), self::m($so->seller_net_amount), self::m($so->commission_amount)]);
        $this->assertSame(self::m('17'), self::m($so->delivery_fee_charged), 'the checkout snapshot stays');
        $this->assertSame(self::m('-14'), self::m($so->platform_delivery_margin), 'Intigo was paid');
        $this->assertSame(self::m('-17'), $this->charges($c)[SellerAdjustment::TYPE_RETURN_PARCEL_FEE]);
        $this->assertSame(self::m('-4.280'), $this->charges($c)[SellerAdjustment::TYPE_RETURN_SHIPPING]);
        $this->assertCount(2, $this->charges($c));
        $this->assertSame(0, self::m(Order::find($order->id)->total_amount));
    }

    public function test_full_return_change_of_mind_keeps_the_delivery(): void
    {
        [$seller, $order] = $this->separateDelivered();
        $c = $this->returned($order, $seller, ['return_all' => 1], 'changed_mind');

        $this->assertSame(self::m('195.720'), self::m($c->refund_amount), '200 − return fee 4.280');
        $this->assertSame(self::m('17'), self::m($this->parcel($order->id, $seller)->delivery_fee));
        $this->assertSame([], $this->charges($c));
    }

    public function test_admin_payer_override_at_approval_moves_the_delivery_refund(): void
    {
        [$seller, $order] = $this->separateDelivered();
        $c = $this->returned($order, $seller, ['return_all' => 1], 'changed_mind', ['shipping_payer' => 'seller']);
        $this->assertSame(self::m('217'), self::m($c->refund_amount));
        $this->assertSame(self::m('-17'), $this->charges($c)[SellerAdjustment::TYPE_RETURN_PARCEL_FEE]);

        $this->travel(2)->minutes();
        [$seller, $order] = $this->separateDelivered();
        $c = $this->returned($order, $seller, ['return_all' => 1], 'damaged_product', ['shipping_payer' => 'client']);
        $this->assertSame(self::m('195.720'), self::m($c->refund_amount));
        $this->assertSame(self::m('17'), self::m($this->parcel($order->id, $seller)->delivery_fee));
        $this->assertArrayNotHasKey(SellerAdjustment::TYPE_RETURN_PARCEL_FEE, $this->charges($c));
    }

    /** v2: "doesn't fit", exchange impossible → refund. The shop pays the return, but is not at fault. */
    private function doesntFitRefund(array $approve = []): array
    {
        app(ReturnPolicy::class)->update(['v2_enabled' => true], $this->admin->id);
        $this->travel(2)->minutes();
        $seller = $this->separate();
        $product = $this->item($seller, 60, 'L');
        $variants = collect(['A', 'B'])->map(fn ($v) => \App\Models\ProductVariant::create(
            ['product_id' => $product->id, 'sku' => $v . Str::random(6), 'stock' => 5, 'is_active' => true]));
        Cart::create(['user_id' => $this->customer->id, 'product_id' => $product->id, 'variant_id' => $variants[0]->id, 'quantity' => 1]);
        $order = $this->delivered($this->checkout()['order_id']);
        DB::table('seller_orders')->where('order_id', $order->id)->update(['delivery_confirmed_at' => now()]);
        $line = $this->lines($order->id)[$product->id];

        $c = Complaint::findOrFail($this->fileReturn($order, [
            'resolution_type' => 'exchange',
            'items' => [['order_item_id' => $line->id, 'quantity' => 1, 'replacement_variant_id' => $variants[1]->id]],
        ], 'doesnt_fit')->assertCreated()->json('data.id'));
        $this->as($seller)->patchJson("/api/seller/complaints/{$c->id}/exchange-impossible", ['reason' => 'No other size is left in stock.'])->assertOk();
        $this->as($this->customer)->patchJson("/api/client/complaints/{$c->id}/switch-to-refund")->assertOk();
        $this->assertSame('seller', $c->fresh()->shipping_payer, 'the policy bills the return to the shop');
        $this->assertSame(self::m('60'), self::m($c->fresh()->items_amount));

        $this->as($seller)->patchJson("/api/seller/complaints/{$c->id}/approve", ['seller_note' => 'ok'])->assertOk();
        $admin = $this->as($this->admin)->getJson("/api/admin/complaints/{$c->id}")->assertOk()->json('data.separate_delivery');
        $this->assertEquals(['fee' => 11, 'reason_is_fault' => false], $admin);
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/approve", $approve + ['shipping_payer' => 'seller'])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/schedule-pickup", ['carrier' => 'Intigo'])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/picked-up")->assertOk();
        $this->as($seller)->patchJson("/api/seller/complaints/{$c->id}/received")->assertOk();
        $this->as($seller)->post("/api/seller/complaints/{$c->id}/inspect", ['result' => Complaint::INSPECTION_CONFORM], ['Accept' => 'application/json'])->assertOk();

        return [$c->fresh(), $this->parcel($order->id, $seller)];
    }

    public function test_doesnt_fit_with_no_exchange_possible_is_not_a_seller_fault_for_delivery(): void
    {
        [$c, $so] = $this->doesntFitRefund();

        $this->assertSame(self::m('60'), self::m($c->refund_amount), 'the product only');
        $this->assertSame(self::m('11'), self::m($so->delivery_fee), 'the delivery fee stays');
        $this->assertArrayNotHasKey(SellerAdjustment::TYPE_RETURN_PARCEL_FEE, $this->charges($c));
        // The return parcel itself is still billed as the returns policy says
        $this->assertSame(self::m('-4.280'), $this->charges($c)[SellerAdjustment::TYPE_RETURN_SHIPPING]);
    }

    public function test_the_admin_can_refund_the_delivery_as_an_exception(): void
    {
        [$c, $so] = $this->doesntFitRefund(['refund_delivery' => true]);

        $this->assertSame(self::m('71'), self::m($c->refund_amount));
        $this->assertSame(0, self::m($so->delivery_fee));
        $this->assertSame(self::m('-11'), $this->charges($c)[SellerAdjustment::TYPE_RETURN_PARCEL_FEE]);
    }

    public function test_return_preview_matches_what_is_filed(): void
    {
        [, $order, $lines] = $this->separateDelivered();
        $all = $lines->map(fn ($l) => ['order_item_id' => $l->id, 'quantity' => 1])->values()->all();
        $preview = fn (string $type, array $items) => $this->as($this->customer)->postJson('/api/client/complaints/preview', [
            'order_id' => $order->id, 'complaint_type' => $type, 'items' => $items,
        ])->assertOk()->json('data');

        $this->assertEquals(217.0, $preview('damaged_product', $all)['items_amount']);
        $this->assertEquals(200.0, $preview('changed_mind', $all)['items_amount']);
        $this->assertEquals(30.0, $preview('damaged_product', [$all[0]])['items_amount']);
    }

    public function test_returns_policy_v2_full_return_refunds_delivery_at_pickup_and_balances_after_inspection(): void
    {
        app(ReturnPolicy::class)->update(['v2_enabled' => true], $this->admin->id);
        [$seller, $order] = $this->separateDelivered();
        DB::table('seller_orders')->where('order_id', $order->id)->update(['delivery_confirmed_at' => now()]);

        $c = $this->returned($order, $seller, ['return_all' => 1, 'resolution_type' => 'return_refund'], 'damaged_product');
        $this->assertSame(self::m('217'), self::m($c->refund_amount));
        $this->assertSame(self::m('217'), self::m($c->refund_amount_locked));
        $this->assertSame(self::m('17'), self::m($this->parcel($order->id, $seller)->delivery_fee), 'nothing reversed before inspection');

        $this->as($seller)->patchJson("/api/seller/complaints/{$c->id}/received")->assertOk();
        $this->as($seller)->post("/api/seller/complaints/{$c->id}/inspect", ['result' => Complaint::INSPECTION_CONFORM], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(0, self::m($this->parcel($order->id, $seller)->delivery_fee));
        $this->assertSame(self::m('-17'), $this->charges($c->fresh())[SellerAdjustment::TYPE_RETURN_PARCEL_FEE]);
        $this->assertSame(self::m('217'), self::m($c->fresh()->refund_amount));
        $this->assertDatabaseMissing('complaint_events', ['complaint_id' => $c->id, 'status' => 'refund_amount_drift']);
    }
}
