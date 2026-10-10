<?php

namespace Tests\Feature\Delivery;

use App\Models\Coupon;
use App\Models\Sponsorship;
use App\Models\SponsorshipEvent;
use App\Services\Orders\DeliveryDocumentService;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per-parcel delivery fees at checkout, COD amounts and the frozen finance
 * snapshot. Admin values: client 8.000, agency 7.000, seller contribution 6.000.
 *
 * Run:  php -d extension=gd vendor/bin/phpunit tests/Feature/Delivery
 */
class CheckoutDeliveryFeesTest extends TestCase
{
    use DatabaseTransactions, DeliveryTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDelivery();
    }

    /** The delivery company's slip shows exactly the parcel's COD amount. */
    private function assertSlipsMatch(int $orderId): void
    {
        $docs  = app(DeliveryDocumentService::class);
        $order = $docs->query()->findOrFail($orderId);
        foreach ($docs->activeSellerOrders($order) as $so) {
            $this->assertSame(self::m($so->cod_amount), self::m($docs->money($order, $so)['cod']), "slip COD of parcel {$so->id}");
            $this->assertSame(self::m($so->delivery_fee), self::m($docs->money($order, $so)['shipping']));
        }
        $this->assertSame(self::m($order->total_amount), collect($docs->slipsFor($order))->sum(fn ($s) => self::m($s['money']['cod'])));
    }

    // 1 ─────────────────────────────────────────────────────────────────────
    public function test_1_single_product_paid_delivery(): void
    {
        $seller = $this->seller('free');
        $this->addToCart($this->product($seller, 50));

        $res = $this->checkout();
        $this->assertEquals(58.0, $res['total']);
        $this->assertEquals(8.0, $res['parcels'][0]['delivery_fee']);
        $this->assertFalse($res['parcels'][0]['is_free_delivery']);

        // 15% of 50 (free plan, ≤ 100 DT tier)
        $this->assertParcel($this->parcel($res['order_id'], $seller), [
            'items' => '50', 'coupon' => '0', 'delivery_fee' => '8', 'cod' => '58', 'commission' => '7.5',
            'contribution' => '0', 'payout' => '42.5', 'agency' => '7', 'margin' => '1', 'to_remit' => '51', 'free' => false,
        ]);
        $order = Order::find($res['order_id']);
        $this->assertTrue($order->shipping_per_parcel);
        $this->assertSame('customer', $order->getAttribute('shipping_paid_by'));
        $this->assertSlipsMatch($res['order_id']);
    }

    // 2 ─────────────────────────────────────────────────────────────────────
    public function test_2_single_product_free_delivery(): void
    {
        $seller = $this->seller('free');
        $this->addToCart($this->product($seller, 50, true));

        $res = $this->checkout();
        $this->assertEquals(50.0, $res['total']);
        $this->assertTrue($res['parcels'][0]['is_free_delivery']);

        // Seller pays the 6.000 contribution; platform margin 6 − 7 = −1
        $this->assertParcel($this->parcel($res['order_id'], $seller), [
            'items' => '50', 'delivery_fee' => '0', 'cod' => '50', 'commission' => '7.5',
            'contribution' => '6', 'payout' => '36.5', 'agency' => '7', 'margin' => '-1', 'to_remit' => '43', 'free' => true,
        ]);
        $this->assertSame('seller', Order::find($res['order_id'])->getAttribute('shipping_paid_by'));
        $this->assertSlipsMatch($res['order_id']);
    }

    // 3 ─────────────────────────────────────────────────────────────────────
    public function test_3_pack(): void
    {
        $seller = $this->seller('free');
        $pack = $this->pack($seller, 40, [[$this->product($seller, 30), 1], [$this->product($seller, 20), 1]]);
        $this->addPack($pack);

        $res = $this->checkout();
        $this->assertEquals(48.0, $res['total']);
        // Commission on the pack price (40 → 15%), never on the products' own prices
        $so = $this->parcel($res['order_id'], $seller);
        $this->assertParcel($so, [
            'items' => '40', 'delivery_fee' => '8', 'cod' => '48', 'commission' => '6',
            'payout' => '34', 'agency' => '7', 'margin' => '1', 'free' => false,
        ]);
        $this->assertSame(2, DB::table('order_items')->where('seller_order_id', $so->id)->count());
        $this->assertSlipsMatch($res['order_id']);
    }

    // 4 ─────────────────────────────────────────────────────────────────────
    public function test_4_product_with_a_discount(): void
    {
        $seller  = $this->seller('free');
        $product = $this->product($seller, 100);
        $this->promotion($product, 'discount', 20);
        $this->addToCart($product);

        $res = $this->checkout();
        $this->assertEquals(88.0, $res['total']);   // 80 + 8
        $this->assertParcel($this->parcel($res['order_id'], $seller), [
            'items' => '80', 'cod' => '88', 'commission' => '12', 'payout' => '68', 'margin' => '1',
        ]);
    }

    // 5 ─────────────────────────────────────────────────────────────────────
    public function test_5_flash_sale_product(): void
    {
        $seller  = $this->seller('black');
        $product = $this->product($seller, 80);
        $this->promotion($product, 'flash_sale', 25);
        $this->addToCart($product);

        $res = $this->checkout();
        $this->assertEquals(68.0, $res['total']);   // 60 + 8
        // black: 15 − 6 = 9% of 60
        $this->assertParcel($this->parcel($res['order_id'], $seller), [
            'items' => '60', 'cod' => '68', 'commission' => '5.4', 'payout' => '54.6', 'margin' => '1',
        ]);
        $this->assertSame(1, (int) DB::table('promotions')->whereIn('id', DB::table('promotion_products')->where('product_id', $product->id)->pluck('promotion_id'))->value('flash_stock_used'));
    }

    // 6 ─────────────────────────────────────────────────────────────────────
    public function test_6_sponsored_product_price_commission_and_payout_are_unaffected(): void
    {
        $seller    = $this->seller('red');
        $plain     = $this->product($seller, 90);
        $sponsored = $this->product($seller, 90);
        $campaign  = Sponsorship::create([
            'seller_id' => $seller->id, 'product_id' => $sponsored->id, 'status' => 'active',
            'pricing_model' => 'cpc', 'daily_budget' => 5, 'max_cpc' => 0.3,
        ]);
        SponsorshipEvent::create([
            'sponsorship_id' => $campaign->id, 'event' => SponsorshipEvent::CLICK, 'placement' => 'search', 'request_id' => (string) Str::uuid(), 'user_id' => $this->customer->id,
            'cost' => 0.3, 'billable' => true, 'countable' => true, 'created_at' => now()->subMinute(),
        ]);
        $walletBefore = DB::table('ad_wallets')->where('seller_id', $seller->id)->value('balance');

        $this->addToCart($sponsored);
        $res = $this->checkout();

        $this->assertEquals(98.0, $res['total']);   // same as an unsponsored 90 DT product
        // red: 15 − 3 = 12% of 90, exactly the plain product's figures
        $this->assertParcel($this->parcel($res['order_id'], $seller), [
            'items' => '90', 'cod' => '98', 'commission' => '10.8', 'payout' => '79.2', 'margin' => '1',
        ]);
        $line = DB::table('order_items')->where('order_id', $res['order_id'])->first();
        $this->assertSame(self::m('90'), self::m($line->unit_price));
        $this->assertSame(self::m($plain->price), self::m($line->unit_price));
        // The sale was credited to the campaign (the sponsored path really ran)...
        $this->assertTrue(DB::table('order_ad_attributions')->where('order_id', $res['order_id'])->where('sponsorship_id', $campaign->id)->exists());
        // ...but sponsoring is billed apart (ad wallet), never on the order
        $this->assertEquals($walletBefore, DB::table('ad_wallets')->where('seller_id', $seller->id)->value('balance'));
    }

    // 7 ─────────────────────────────────────────────────────────────────────
    public function test_7_seller_coupon(): void
    {
        $seller  = $this->seller('free');
        $product = $this->product($seller, 100);
        $coupon  = Coupon::create(['seller_id' => $seller->id, 'code' => 'DEL' . Str::upper(Str::random(5)), 'discount_type' => 'fixed', 'discount_value' => 10, 'is_active' => true]);
        $coupon->products()->attach($product->id);
        $this->addToCart($product);

        $res = $this->checkout(['coupon_codes' => [$coupon->code]]);
        $this->assertEquals(98.0, $res['total']);   // 100 − 10 + 8
        // Commission on (items − coupon) = 15% of 90, never on delivery
        $this->assertParcel($this->parcel($res['order_id'], $seller), [
            'items' => '100', 'coupon' => '10', 'cod' => '98', 'commission' => '13.5', 'payout' => '76.5', 'margin' => '1',
        ]);
        $this->assertSlipsMatch($res['order_id']);
    }

    // 8 ─────────────────────────────────────────────────────────────────────
    public function test_8_multi_seller_cart_per_parcel_amounts(): void
    {
        $a = $this->seller('free');
        $b = $this->seller('red');
        $c = $this->seller('black');
        $this->addToCart($this->product($a, 45), 2);
        $this->addToCart($this->product($b, 120, true));
        $flash = $this->product($c, 80);
        $this->promotion($flash, 'flash_sale', 25);
        $this->addToCart($flash);

        $quote = $this->quote();
        $this->assertSame(['8', '0', '8'], array_map(fn ($p) => rtrim(rtrim(number_format($p['delivery_fee'], 3, '.', ''), '0'), '.'), $quote['parcels']));
        $res = $this->checkout();
        $this->assertEquals(286.0, $res['total']);
        $this->assertEquals(16.0, $res['delivery_fee']);
        $this->assertCount(3, $res['parcels']);

        $this->assertParcel($pa = $this->parcel($res['order_id'], $a), [
            'items' => '90', 'cod' => '98', 'commission' => '13.5', 'payout' => '76.5', 'agency' => '7', 'margin' => '1', 'to_remit' => '91', 'free' => false,
        ]);
        $this->assertParcel($pb = $this->parcel($res['order_id'], $b), [
            'items' => '120', 'cod' => '120', 'commission' => '10.8', 'contribution' => '6', 'payout' => '103.2', 'agency' => '7', 'margin' => '-1', 'to_remit' => '113', 'free' => true,
        ]);
        $this->assertParcel($pc = $this->parcel($res['order_id'], $c), [
            'items' => '60', 'cod' => '68', 'commission' => '5.4', 'payout' => '54.6', 'agency' => '7', 'margin' => '1', 'to_remit' => '61', 'free' => false,
        ]);
        $this->assertSame('mixed', Order::find($res['order_id'])->getAttribute('shipping_paid_by'));
        $this->assertSlipsMatch($res['order_id']);

        // Delivered + remitted: the finance section reconciles to the millime
        foreach ([$pa, $pb, $pc] as $so) {
            $this->deliver($so);
            $this->confirmRemittance($so)->assertOk();
        }
        $kpis = $this->as($this->admin)->getJson('/api/admin/finance/overview?period=all&date_from=' . now()->toDateString())->assertOk()->json('data.delivery');
        // Other test data may exist in the period: compare against these three parcels via the seller filter
        $sum = ['cash' => 0, 'agency' => 0, 'remit' => 0, 'payout' => 0, 'commission' => 0, 'margin' => 0];
        foreach ([[$a, $pa], [$b, $pb], [$c, $pc]] as [$seller, $so]) {
            $k = $this->as($this->admin)->getJson("/api/admin/finance/overview?period=all&seller_id={$seller->id}")->assertOk()->json('data.delivery');
            $so = $this->fresh($so);
            $this->assertSame(self::m($so->cod_amount), self::m($k['cash_collected']));
            $this->assertSame(self::m($so->amount_to_remit), self::m($k['remitted_to_platform']));
            $this->assertSame(0, self::m($k['pending_at_delivery_company']));
            $this->assertSame(0, self::m($k['reconciliation']['difference']));
            $this->assertSame(self::m($so->seller_net_amount), self::m($k['seller_payouts_payable']));
            $sum['cash'] += self::m($k['cash_collected']);
            $sum['remit'] += self::m($k['remitted_to_platform']);
            $sum['agency'] += self::m($k['agency_fees']);
            $sum['payout'] += self::m($k['seller_payouts_payable']);
            $sum['commission'] += self::m($k['commission']);
            $sum['margin'] += self::m($k['platform_delivery_margin']);
        }
        $this->assertSame(286000, $sum['cash']);
        $this->assertSame(21000, $sum['agency']);
        $this->assertSame(265000, $sum['remit']);
        $this->assertSame(234300, $sum['payout']);
        $this->assertSame(29700, $sum['commission']);
        $this->assertSame(1000, $sum['margin']);
        $this->assertSame($sum['remit'], $sum['payout'] + $sum['commission'] + $sum['margin']);
        $this->assertSame(0, self::m($kpis['reconciliation']['difference']));
    }

    // 9 ─────────────────────────────────────────────────────────────────────
    public function test_9_quantities_and_several_items_of_one_seller_pay_one_shipment(): void
    {
        $seller = $this->seller('free');
        $this->addToCart($this->product($seller, 30), 3);
        $this->addToCart($this->product($seller, 20), 2);

        $res = $this->checkout();
        $this->assertCount(1, $res['parcels']);
        $this->assertEquals(138.0, $res['total']);   // 90 + 40 + ONE fee of 8
        // 15% of 90 + 15% of 40 (tier from each unit price)
        $this->assertParcel($this->parcel($res['order_id'], $seller), [
            'items' => '130', 'delivery_fee' => '8', 'cod' => '138', 'commission' => '19.5', 'payout' => '110.5', 'margin' => '1',
        ]);
    }

    // 10 ────────────────────────────────────────────────────────────────────
    public function test_10_changing_the_fees_never_touches_existing_orders(): void
    {
        $seller = $this->seller('free');
        $product = $this->product($seller, 50);
        $this->addToCart($product);
        $old = $this->checkout();
        $before = $this->parcel($old['order_id'], $seller);

        $this->setFees(10, 9, 9.5);
        $this->as($this->admin)->putJson('/api/admin/delivery-settings', [
            'client_delivery_fee' => 12, 'agency_delivery_cost' => 9, 'seller_free_delivery_contribution' => 9.5,
            'return_shipping_fee' => 8, 'refused_parcel_agency_fee' => 7, 'refused_parcel_fee_paid_by' => 'platform',
        ])->assertOk();

        $after = $this->parcel($old['order_id'], $seller);
        $this->assertEquals((array) $before, (array) $after);
        $this->assertSame(58000, self::m(Order::find($old['order_id'])->total_amount));
        $this->assertSame(58000, self::m(Order::find($old['order_id'])->moneySummary()['total']));

        // New orders use the new values
        $this->addToCart($product);
        $new = $this->checkout();
        $this->assertEquals(62.0, $new['total']);
        $this->assertParcel($this->parcel($new['order_id'], $seller), ['delivery_fee' => '12', 'agency' => '9', 'margin' => '3', 'cod' => '62']);
    }

    // 11 ────────────────────────────────────────────────────────────────────
    public function test_11_tampered_prices_and_fees_are_ignored(): void
    {
        $seller  = $this->seller('free');
        $product = $this->product($seller, 50);
        $this->addToCart($product);

        $fake = [
            'delivery_fee' => 0, 'shipping_fee' => 0, 'total' => 1, 'subtotal' => 1, 'price' => 1,
            'parcels' => [['seller_id' => $seller->id, 'delivery_fee' => 0, 'cod_amount' => 1]],
            'items' => [['product_id' => $product->id, 'price' => 1, 'unit_price' => 1]],
        ];
        // A fake expected total is refused with the real one
        $this->as($this->customer)->postJson('/api/checkout', $this->address() + $fake + ['payment_method' => 'cod', 'expected_total' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'price_changed')->assertJsonPath('data.total', 58);

        // Without one, the server's own prices are charged
        $res = $this->as($this->customer)->postJson('/api/checkout', $this->address() + $fake + ['payment_method' => 'cod'])->assertCreated()->json();
        $this->assertEquals(58.0, $res['total']);
        $this->assertParcel($this->parcel($res['order_id'], $seller), ['items' => '50', 'delivery_fee' => '8', 'cod' => '58']);

        // Buy now: same
        $this->as($this->customer)->postJson('/api/checkout/buy-now', $this->address() + $fake + [
            'product_id' => $product->id, 'quantity' => 1, 'payment_method' => 'cod',
        ])->assertCreated()->assertJsonPath('total', 58);
    }

    // 12 ────────────────────────────────────────────────────────────────────
    public function test_12_online_methods_are_coming_soon_and_rejected(): void
    {
        $seller  = $this->seller('free');
        $product = $this->product($seller, 50);
        $this->addToCart($product);

        $methods = $this->getJson('/api/checkout/payment-info')->assertOk()->json('data.payment_methods');
        $this->assertSame(['cod' => true, 'card' => false, 'd17' => false, 'wallet' => false], $methods);
        $this->assertSame($methods, $this->quote()['payment_methods']);

        foreach (['card', 'd17', 'wallet'] as $method) {
            $this->as($this->customer)->postJson('/api/checkout', $this->address() + ['payment_method' => $method])
                ->assertStatus(422)->assertJsonPath('code', 'payment_method_unavailable');
            $this->as($this->customer)->postJson('/api/checkout/buy-now', $this->address() + ['payment_method' => $method, 'product_id' => $product->id, 'quantity' => 1])
                ->assertStatus(422)->assertJsonPath('code', 'payment_method_unavailable');
        }
        $this->assertSame(0, Order::where('user_id', $this->customer->id)->count());

        // Switchable: the admin turns card on, it is accepted again
        $this->as($this->admin)->putJson('/api/admin/delivery-settings', [
            'client_delivery_fee' => 8, 'agency_delivery_cost' => 7, 'seller_free_delivery_contribution' => 6,
            'return_shipping_fee' => 8, 'refused_parcel_agency_fee' => 7, 'refused_parcel_fee_paid_by' => 'platform',
            'payment_methods' => ['card' => true],
        ])->assertOk()->assertJsonPath('data.payment_methods.card', true);
        \App\Models\PlatformSetting::flushCache();
        $res = $this->as($this->customer)->postJson('/api/checkout', $this->address() + ['payment_method' => 'card'])->assertCreated()->json();
        $this->assertSame(0, self::m($this->parcel($res['order_id'], $seller)->cod_amount));   // prepaid: nothing to collect
        $this->assertTrue(DB::table('delivery_setting_changes')->where('field', 'payment_method.card')->where('new_value', 'enabled')->exists());
    }
}
