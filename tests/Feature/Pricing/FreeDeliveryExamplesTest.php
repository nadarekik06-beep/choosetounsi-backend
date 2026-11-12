<?php

namespace Tests\Feature\Pricing;

use App\Models\Complaint;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerAdjustment;
use App\Models\SellerOrder;
use App\Models\User;
use App\Services\Delivery\DeliverySettings;
use App\Services\Intigo\ParcelPayload;
use App\Services\Pricing\PricingService;
use App\Support\GuestCheckout;
use App\Support\Millimes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Delivery\DeliveryTestHelpers;
use Tests\TestCase;

/**
 * "Free delivery everywhere": the worked examples of the brief, to the millime.
 *
 * Settings: client fee S 9.000 / L 11.000 / XL 17.000, Intigo cost the same,
 * Intigo return fee 4.280, after-sale return fee 4.280.
 * Commission: free plan, 15 % (unit price ≤ 100 DT).
 *
 * Run:  php vendor/phpunit/phpunit/phpunit tests/Feature/Pricing
 */
class FreeDeliveryExamplesTest extends TestCase
{
    use DatabaseTransactions, DeliveryTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('public');
        $this->setUpDelivery();
        $this->exampleFees();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function exampleFees(): void
    {
        $sizes = [];
        foreach (['S' => 9, 'L' => 11, 'XL' => 17] as $size => $fee) {
            $sizes[$size] = [
                'client_fee' => Millimes::of($fee), 'agency_cost' => Millimes::of($fee), 'return_fee' => Millimes::of('4.280'),
                'after_sale_use_agency_cost' => false, 'after_sale_return_fee' => Millimes::of('4.280'),
            ];
        }
        app(DeliverySettings::class)->update(['sizes' => $sizes], $this->admin->id);
    }

    private function item(User $seller, $price, string $size = 'S', int $stock = 50): Product
    {
        $name = 'Del Product ' . Str::random(8);
        return Product::create([
            'seller_id' => $seller->id, 'name' => $name, 'slug' => Str::slug($name), 'price' => $price,
            'parcel_size' => $size, 'stock' => $stock, 'is_approved' => true, 'is_active' => true,
        ]);
    }

    private function lines(int $orderId): \Illuminate\Support\Collection
    {
        return DB::table('order_items')->where('order_id', $orderId)->orderBy('id')->get()->keyBy('product_id');
    }

    /** Example A: T-shirt 30 (S) + shoes 80 (L) from one seller. Returns [seller, tshirt, shoes, checkout answer]. */
    private function exampleA(?User $seller = null): array
    {
        $seller ??= $this->seller('free');
        $tshirt = $this->item($seller, 30, 'S');
        $shoes  = $this->item($seller, 80, 'L');
        $this->addToCart($tshirt);
        $this->addToCart($shoes);
        return [$seller, $tshirt, $shoes, $this->checkout()];
    }

    private function delivered(int $orderId): Order
    {
        DB::table('seller_orders')->where('order_id', $orderId)->update(['status' => 'delivered']);
        DB::table('orders')->where('id', $orderId)->update(['status' => 'delivered', 'updated_at' => now()]);
        return Order::findOrFail($orderId);
    }

    /** Files a return and brings it to the courier's cash refund at pick-up. */
    private function returned(Order $order, User $seller, array $payload, string $type): Complaint
    {
        $res = $this->as($this->customer)->post('/api/client/complaints', $payload + [
            'order_id' => $order->id, 'complaint_type' => $type,
            'description' => 'Detailed reason for this return request, long enough.',
            'images' => [UploadedFile::fake()->image('proof.jpg', 400, 300)],
        ], ['Accept' => 'application/json'])->assertCreated();
        $c = Complaint::findOrFail($res->json('data.id'));

        $this->as($seller)->patchJson("/api/seller/complaints/{$c->id}/approve", ['seller_note' => 'ok'])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/approve")->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/schedule-pickup", ['carrier' => 'Intigo'])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/picked-up")->assertOk();
        return $c->fresh();
    }

    // ── A. One seller, two items ──────────────────────────────────────────────

    public function test_a_one_seller_two_items(): void
    {
        $seller = $this->seller('free');
        $tshirt = $this->item($seller, 30, 'S');
        $shoes  = $this->item($seller, 80, 'L');
        $this->addToCart($tshirt);
        $this->addToCart($shoes);

        $quote  = $this->quote();
        $parcel = $quote['parcels'][0];
        $this->assertEquals([39.0, 91.0], array_column($parcel['lines'], 'unit_price'), 'display = base + fee of the size');
        $this->assertEquals(130.0, $quote['subtotal']);
        $this->assertEquals(9.0, $quote['multi_item_discount']);
        $this->assertEquals(9.0, $parcel['multi_item_discount']);
        $this->assertNull($parcel['upsell_min_saving'], 'two items: no upsell hint');
        $this->assertEquals(0.0, $quote['delivery_fee'], 'never a delivery amount');
        $this->assertTrue($parcel['is_free_delivery']);
        $this->assertTrue($quote['free_delivery']);
        $this->assertEquals(121.0, $quote['total']);
        $this->assertEquals(121.0, $parcel['cod_amount']);

        $res = $this->checkout();
        $this->assertEquals(121.0, $res['total']);
        $so = $this->parcel($res['order_id'], $seller);

        // Seller payout = 110 − commission on 30 and 80 (15 %); delivery: collected 11, Intigo 11, margin 0
        $this->assertParcel($so, [
            'items' => '110', 'coupon' => '0', 'delivery_fee' => '11', 'cod' => '121', 'commission' => '16.5',
            'contribution' => '0', 'payout' => '93.5', 'agency' => '11', 'margin' => '0', 'free' => false,
        ]);
        $this->assertSame('included', $so->pricing_model);
        $this->assertSame('L', $so->parcel_size, 'parcel size = the largest item');
        $this->assertSame(self::m('20'), self::m($so->included_fees_total));
        $this->assertSame(self::m('11'), self::m($so->parcel_fee));
        $this->assertSame(self::m('9'), self::m($so->multi_item_discount));
        $this->assertSame(self::m('121'), self::m($so->client_total));
        $this->assertEquals(['S' => '9.000', 'L' => '11.000', 'XL' => '17.000'], json_decode($so->fee_rates, true));

        // Per item snapshot: base price, included fee, display price
        $lines = $this->lines($res['order_id']);
        $this->assertSame([self::m('30'), self::m('9'), self::m('39')],
            [self::m($lines[$tshirt->id]->unit_price), self::m($lines[$tshirt->id]->included_fee), self::m($lines[$tshirt->id]->display_unit_price)]);
        $this->assertSame([self::m('80'), self::m('11'), self::m('91')],
            [self::m($lines[$shoes->id]->unit_price), self::m($lines[$shoes->id]->included_fee), self::m($lines[$shoes->id]->display_unit_price)]);
        $this->assertSame('S', $lines[$tshirt->id]->parcel_size);

        $order = Order::find($res['order_id']);
        $this->assertSame('included', $order->pricing_model);
        $this->assertSame(self::m('9'), self::m($order->multi_item_discount));
        $this->assertSame(self::m('121'), self::m($order->total_amount));

        // The COD sent to Intigo is the 121.000 the client was shown, as one L parcel
        $payload = app(ParcelPayload::class)->preview(SellerOrder::find($so->id))['payload'];
        $this->assertEquals(121.0, $payload['price']);
        $this->assertSame(4, $payload['package_size']);
    }

    // ── B. Quantity ───────────────────────────────────────────────────────────

    public function test_b_two_units_of_the_same_product(): void
    {
        $seller = $this->seller('free');
        $this->addToCart($this->item($seller, 30, 'S'), 2);

        $quote = $this->quote();
        $this->assertEquals(78.0, $quote['subtotal']);
        $this->assertEquals(9.0, $quote['multi_item_discount']);
        $this->assertEquals(69.0, $quote['total']);

        $res = $this->checkout();
        $this->assertEquals(69.0, $res['total']);
        // Payout = 60 − 15 %
        $this->assertParcel($this->parcel($res['order_id'], $seller), [
            'items' => '60', 'delivery_fee' => '9', 'cod' => '69', 'commission' => '9', 'payout' => '51', 'agency' => '9', 'margin' => '0',
        ]);
    }

    // ── C. Two sellers ────────────────────────────────────────────────────────

    public function test_c_two_sellers(): void
    {
        $a = $this->seller('free');
        $b = $this->seller('free');
        $this->addToCart($this->item($a, 30, 'S'));
        $this->addToCart($this->item($a, 80, 'L'));
        $this->addToCart($this->item($b, 50, 'S'));

        $quote   = $this->quote();
        $parcels = collect($quote['parcels'])->keyBy('seller_id');
        $this->assertEquals(180.0, $quote['total']);
        $this->assertEquals(9.0, $quote['multi_item_discount']);
        $this->assertEquals(121.0, $parcels[$a->id]['total']);
        $this->assertEquals(59.0, $parcels[$b->id]['total']);
        $this->assertEquals(0.0, $parcels[$b->id]['multi_item_discount'], 'one item: no discount');
        $this->assertEquals(9.0, $parcels[$b->id]['upsell_min_saving'], 'upsell hint = the S fee');
        $this->assertNull($parcels[$a->id]['upsell_min_saving']);

        $res = $this->checkout();
        $this->assertEquals(180.0, $res['total']);
        // Two Intigo parcels: 121.000 and 59.000
        $cods = [];
        foreach ([$a, $b] as $seller) {
            $so = SellerOrder::find($this->parcel($res['order_id'], $seller)->id);
            $cods[] = app(ParcelPayload::class)->preview($so)['payload']['price'];
        }
        $this->assertEquals([121.0, 59.0], $cods);
    }

    // ── D. Partial return ─────────────────────────────────────────────────────

    public function test_d_partial_return_refunds_the_item_as_if_never_ordered(): void
    {
        [$seller, $tshirt, , $res] = $this->exampleA();
        $order = $this->delivered($res['order_id']);
        $line  = $this->lines($order->id)[$tshirt->id];

        $c = $this->returned($order, $seller, ['items' => [['order_item_id' => $line->id, 'quantity' => 1]]], 'damaged_product');

        // Recomputed parcel = shoes alone = 91.000 → refund 121 − 91 = 30.000
        $this->assertSame(self::m('30'), self::m($c->refund_amount));
        $so = $this->parcel($order->id, $seller);
        $this->assertSame(self::m('91'), self::m($so->client_total));
        $this->assertSame(self::m('11'), self::m($so->included_fees_total));
        $this->assertSame(self::m('0'), self::m($so->multi_item_discount));
        $this->assertSame(self::m('11'), self::m($so->delivery_fee), 'the L parcel fee is still due');
        // Seller: payout and commission drop by the T-shirt's base share only (30 − 4.5)
        $this->assertSame(self::m('68'), self::m($so->seller_net_amount));
        $this->assertSame(self::m('12'), self::m($so->commission_amount));
        $this->assertSame(self::m('91'), self::m(Order::find($order->id)->total_amount));
        // Never more than what was paid
        $this->assertLessThanOrEqual(self::m('121'), self::m($c->refund_amount));
    }

    public function test_d_returning_the_largest_item_also_gives_back_the_fee_difference(): void
    {
        [$seller, , $shoes, $res] = $this->exampleA();
        $order = $this->delivered($res['order_id']);
        $line  = $this->lines($order->id)[$shoes->id];

        $c = $this->returned($order, $seller, ['items' => [['order_item_id' => $line->id, 'quantity' => 1]]], 'damaged_product');

        // The T-shirt alone would have cost 39.000 → refund 121 − 39 = 82.000
        $this->assertSame(self::m('82'), self::m($c->refund_amount));
        $so = $this->parcel($order->id, $seller);
        $this->assertSame(self::m('39'), self::m($so->client_total));
        // The seller still gets exactly the T-shirt's base price minus its commission
        $this->assertSame(self::m('25.5'), self::m($so->seller_net_amount));
        // Partial return: the fee difference stays with the platform, no parcel-fee charge
        $this->assertFalse(SellerAdjustment::where('complaint_id', $c->id)->where('type', SellerAdjustment::TYPE_RETURN_PARCEL_FEE)->exists());
    }

    // ── E. Fee change ─────────────────────────────────────────────────────────

    public function test_e_admin_fee_change_reprices_old_products_at_once_and_keeps_old_orders(): void
    {
        [$seller, $tshirt, , $res] = $this->exampleA();
        $before = $this->parcel($res['order_id'], $seller);

        $this->assertEquals(39.0, $this->getJson("/api/products/{$tshirt->slug}")->assertOk()->json('data.final_price'));

        // The admin sets S to 10.000 through the admin API (cache flushed on save)
        $current = $this->as($this->admin)->getJson('/api/admin/delivery-settings')->assertOk()->json('data');
        $sizes = [];
        foreach (['S', 'L', 'XL'] as $size) {
            $sizes[$size] = ['client_fee' => $current['sizes'][$size]['client_fee'], 'agency_cost' => $current['sizes'][$size]['agency_cost'], 'return_fee' => $current['sizes'][$size]['return_fee']];
        }
        $sizes['S']['client_fee'] = '10.000';
        $this->as($this->admin)->putJson('/api/admin/delivery-settings', ['sizes' => $sizes, 'refused_parcel_fee_paid_by' => 'seller'])->assertOk();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        // Product page, listing, price filter, cart: 40.000 everywhere
        $this->assertEquals(40.0, $this->getJson("/api/products/{$tshirt->slug}")->assertOk()->json('data.final_price'));
        $this->assertEquals(40.0, $this->getJson("/api/products/{$tshirt->slug}")->json('data.price'));
        $listed = collect($this->getJson("/api/products?seller_id={$seller->id}&price_min=40&price_max=40")->assertOk()->json('data.data'));
        $this->assertTrue($listed->contains('id', $tshirt->id), 'SQL price filter uses the display price');
        $this->assertEquals(40.0, $listed->firstWhere('id', $tshirt->id)['final_price']);
        $this->assertFalse(collect($this->getJson("/api/products?seller_id={$seller->id}&price_max=39.5")->json('data.data'))->contains('id', $tshirt->id));

        $this->addToCart($tshirt);
        $cart = $this->as($this->customer)->getJson('/api/cart')->assertOk()->json('data');
        $this->assertEquals(40.0, $cart['items'][0]['price']);

        // The earlier order keeps every amount
        $after = $this->parcel($res['order_id'], $seller);
        foreach (['cod_amount', 'client_total', 'delivery_fee', 'included_fees_total', 'multi_item_discount', 'seller_net_amount'] as $col) {
            $this->assertSame($before->{$col}, $after->{$col}, $col);
        }
        $this->assertSame(self::m('39'), self::m($this->lines($res['order_id'])[$tshirt->id]->display_unit_price));
    }

    // ── F. Full return, seller's fault ────────────────────────────────────────

    public function test_f_full_return_seller_fault(): void
    {
        [$seller, , , $res] = $this->exampleA();
        $order = $this->delivered($res['order_id']);

        $c = $this->returned($order, $seller, ['return_all' => 1], 'damaged_product');

        $this->assertSame('seller', $c->shipping_payer);
        $this->assertSame(self::m('121'), self::m($c->refund_amount), 'the client gets back everything paid');

        $so = $this->parcel($order->id, $seller);
        $this->assertSame(self::m('0'), self::m($so->seller_net_amount), 'no payout for these items');
        $this->assertSame(self::m('0'), self::m($so->commission_amount), 'commission reversed');
        $this->assertSame('cancelled', $so->payout_status);
        $this->assertSame(self::m('0'), self::m($so->client_total));

        // Charged to the seller: 11.000 (parcel fee) + 4.280 (return fee), on the next settlements
        $charges = SellerAdjustment::where('complaint_id', $c->id)->pluck('amount', 'type')->map(fn ($v) => self::m($v))->all();
        $this->assertSame(self::m('-11'), $charges[SellerAdjustment::TYPE_RETURN_PARCEL_FEE]);
        $this->assertSame(self::m('-4.280'), $charges[SellerAdjustment::TYPE_RETURN_SHIPPING]);
        $this->assertCount(2, $charges);

        // Visible in the seller's finance page
        $earnings = $this->as($seller)->getJson('/api/seller/earnings/overview?period=all')->assertOk()->json('data');
        $this->assertEquals(-15.28, $earnings['kpis']['pending_deductions']);
        $this->assertContains(SellerAdjustment::TYPE_RETURN_PARCEL_FEE, array_column($earnings['adjustments'], 'type'));
    }

    // ── G. Full return, changed mind ──────────────────────────────────────────

    public function test_g_full_return_changed_mind(): void
    {
        [$seller, , , $res] = $this->exampleA();
        $order = $this->delivered($res['order_id']);

        $c = $this->returned($order, $seller, ['return_all' => 1], 'changed_mind');

        $this->assertSame('client', $c->shipping_payer);
        $this->assertSame(self::m('116.720'), self::m($c->refund_amount), '121.000 − 4.280 return fee');

        $so = $this->parcel($order->id, $seller);
        $this->assertSame(self::m('0'), self::m($so->seller_net_amount));
        $this->assertSame(self::m('0'), self::m($so->commission_amount));
        $this->assertSame(0, SellerAdjustment::where('complaint_id', $c->id)->count(), 'nothing else charged to the seller');
    }

    // ── Seller debt when the next payout is too low ───────────────────────────

    public function test_seller_debt_is_carried_over_when_the_payout_cannot_cover_it(): void
    {
        [$seller, , , $res] = $this->exampleA();
        $order = $this->delivered($res['order_id']);
        $this->returned($order, $seller, ['return_all' => 1], 'damaged_product');   // owes 15.280

        // Next sale: a small one, payout 10 − 15 % = 8.500
        $this->travel(\App\Services\Orders\CheckoutGuard::RECENT_SECONDS + 1)->seconds();
        $this->addToCart($this->item($seller, 10, 'S'));
        $small = $this->checkout();
        $so = $this->parcel($small['order_id'], $seller);
        DB::table('seller_orders')->where('id', $so->id)->update([
            'status' => 'delivered', 'payout_status' => 'ready', 'money_received_at' => now(), 'cash_collected_at' => now(),
        ]);

        $batch = $this->as($this->admin)->postJson('/api/admin/settlements/create', [
            'seller_id' => $seller->id, 'batch_date' => now()->toDateString(),
        ])->assertOk()->json('data');

        // The payout never goes below 0: 8.500 of the debt is settled, 6.780 stays owed
        $this->assertSame(self::m('0'), self::m($batch['total_seller_payout']));
        $this->assertSame(self::m('-8.5'), self::m($batch['total_adjustments']));
        $pending = SellerAdjustment::where('seller_id', $seller->id)->whereNull('settlement_batch_id')->get();
        $this->assertSame(self::m('-6.780'), $pending->sum(fn ($a) => self::m($a->amount)));
        $this->assertSame(self::m('-15.280'), SellerAdjustment::where('seller_id', $seller->id)->get()->sum(fn ($a) => self::m($a->amount)), 'the debt total never changes');

        // Admin finance still shows what sellers owe
        $overview = $this->as($this->admin)->getJson('/api/admin/finance/overview?period=all')->assertOk()->json();
        $this->assertStringContainsString('"pending_seller_debits":-15.28', json_encode($overview));
    }

    // ── Promotions: discount on the base price, badge from display prices ─────

    public function test_promotion_applies_to_the_base_price_and_the_badge_is_computed_from_display_prices(): void
    {
        $seller  = $this->seller('free');
        $product = $this->item($seller, 30, 'S');
        $this->promotion($product, 'discount', 50);

        // 30 → 15 for the seller; the client sees 39 → 24: floor(15 / 39) = 38 %
        $data = $this->getJson("/api/products/{$product->slug}")->assertOk()->json('data');
        $this->assertEquals(39.0, $data['original_price']);
        $this->assertEquals(24.0, $data['final_price']);
        $this->assertSame(38, $data['discount_percent']);
        $this->assertSame(38.0, (float) $data['promotion']['discount_value'], 'never the seller\'s own 50 %');

        $this->addToCart($product);
        $res = $this->checkout();
        $this->assertEquals(24.0, $res['total']);
        $this->assertSame(38, $res['parcels'][0]['lines'][0]['discount_percent']);
        // Commission and payout on the discounted BASE price (15), never on delivery
        $this->assertParcel($this->parcel($res['order_id'], $seller), [
            'items' => '15', 'delivery_fee' => '9', 'cod' => '24', 'commission' => '2.25', 'payout' => '12.75',
        ]);

        // Promotion-level label: "up to −X %" from the real display prices
        $promo = collect($this->getJson('/api/discounts')->assertOk()->json('data'))->first(fn ($p) => collect($p['products'])->contains('id', $product->id));
        $this->assertSame(38, $promo['up_to_percent']);
        $this->assertStringContainsString('38', $promo['discount_label']);
    }

    public function test_variant_with_its_own_price_gets_the_fee_too(): void
    {
        $seller  = $this->seller('free');
        $product = $this->item($seller, 30, 'L');
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-' . Str::random(6), 'price_override' => 45, 'stock' => 5, 'is_active' => true]);

        $data = $this->getJson("/api/products/{$product->slug}")->assertOk()->json('data');
        $this->assertEquals(41.0, $data['price']);
        $shown = collect($data['variants'])->firstWhere('id', $variant->id);
        $this->assertEquals(56.0, $shown['price']);
        $this->assertEquals(56.0, $shown['final_price']);
        $this->assertEquals(56.0, $shown['price_override'], 'never the base override');
    }

    // ── Coupon + multi-item discount ──────────────────────────────────────────

    public function test_coupon_applies_to_the_base_part_and_the_multi_item_discount_stays(): void
    {
        $seller = $this->seller('free');
        $tshirt = $this->item($seller, 30, 'S');
        $shoes  = $this->item($seller, 80, 'L');
        $coupon = Coupon::create([
            'seller_id' => $seller->id, 'code' => 'FD' . Str::upper(Str::random(6)),
            'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true, 'usage_count' => 0,
        ]);
        $coupon->products()->attach([$tshirt->id, $shoes->id]);
        $this->addToCart($tshirt);
        $this->addToCart($shoes);

        // 10 % of the base 110 = 11.000 (not of 130); 130 − 11 − 9 = 110
        $res = $this->checkout(['coupon_codes' => [$coupon->code]]);
        $this->assertEquals(130.0, $res['subtotal']);
        $this->assertEquals(11.0, $res['discount_amount']);
        $this->assertEquals(9.0, $res['multi_item_discount']);
        $this->assertEquals(110.0, $res['total']);
        // Payout = (110 − 11) − 15 %, identical with or without the multi-item discount
        $this->assertParcel($this->parcel($res['order_id'], $seller), [
            'items' => '110', 'coupon' => '11', 'delivery_fee' => '11', 'cod' => '110', 'commission' => '14.85', 'payout' => '84.15',
        ]);
    }

    // ── Packs (Q1): one unit of the largest item ──────────────────────────────

    public function test_pack_counts_as_one_unit_of_its_largest_item(): void
    {
        $seller = $this->seller('free');
        $small  = $this->item($seller, 30, 'S');
        $large  = $this->item($seller, 20, 'L');
        $pack   = $this->pack($seller, 40, [[$small, 1], [$large, 1]]);

        // Shown: pack price + ONE fee (L), crossed-out value + the same fee
        $shown = $this->getJson("/api/packs/{$pack->slug}")->assertOk()->json('data');
        $this->assertEquals(51.0, $shown['pack_price']);
        $this->assertEquals(61.0, $shown['original_price']);
        $this->assertEquals(10.0, $shown['savings']);

        // Alone: no multi-item discount inside the pack
        $this->addPack($pack);
        $quote = $this->quote();
        $this->assertEquals(51.0, $quote['total']);
        $this->assertEquals(0.0, $quote['multi_item_discount']);
        $this->assertEquals(51.0, $quote['parcels'][0]['lines'][0]['unit_price']);
        $this->assertEquals(9.0, $quote['parcels'][0]['upsell_min_saving'], 'a pack alone is one unit');

        // Pack + another item of the same seller: the item's fee comes back
        $this->addToCart($this->item($seller, 30, 'S'));
        $res = $this->checkout();
        $this->assertEquals(90.0, $res['subtotal']);           // 51 + 39
        $this->assertEquals(9.0, $res['multi_item_discount']);
        $this->assertEquals(81.0, $res['total']);              // 40 + 30 + one L fee
        $so = $this->parcel($res['order_id'], $seller);
        $this->assertParcel($so, ['items' => '70', 'delivery_fee' => '11', 'cod' => '81']);
        $this->assertSame(self::m('20'), self::m($so->included_fees_total));
        // The pack's single fee is spread over its two lines
        $packFees = DB::table('order_items')->where('seller_order_id', $so->id)->whereNotNull('pack_group')->get();
        $this->assertSame(self::m('11'), $packFees->sum(fn ($l) => self::m($l->included_fee_total)));
        $this->assertSame([1, 1], $packFees->pluck('pack_units')->map(fn ($v) => (int) $v)->all());
    }

    // ── Guest checkout ────────────────────────────────────────────────────────

    public function test_guest_checkout_gets_the_same_totals_and_tracking_shows_free_delivery(): void
    {
        config(['guest_checkout.enabled' => true]);
        \App\Models\PlatformSetting::where('key', GuestCheckout::SETTING_KEY)->delete();
        \App\Models\PlatformSetting::flushCache();
        $seller = $this->seller('free');
        $tshirt = $this->item($seller, 30, 'S');
        $shoes  = $this->item($seller, 80, 'L');
        $token  = Str::random(40);
        $guest  = function () use ($token) {
            $this->flushHeaders();
            $this->app['auth']->forgetGuards();
            return $this->withHeaders([GuestCheckout::CART_HEADER => $token]);
        };

        $guest()->postJson('/api/cart', ['product_id' => $tshirt->id, 'quantity' => 1])->assertOk();
        $cart = $guest()->postJson('/api/cart', ['product_id' => $shoes->id, 'quantity' => 1])->assertOk()->json('data');
        $this->assertEquals(130.0, $cart['subtotal']);
        $this->assertEquals(9.0, $cart['summary']['multi_item_discount']);
        $this->assertEquals(121.0, $cart['summary']['total']);
        $this->assertEquals(9.0, $cart['summary']['shops'][0]['multi_item_discount']);

        $quote = $guest()->postJson('/api/checkout/quote', ['phone' => '22333444'])->assertOk()->json('data');
        $this->assertEquals(121.0, $quote['total']);
        $res = $guest()->postJson('/api/checkout', [
            'recipient_name' => 'Guest Buyer', 'wilaya' => 'Tunis', 'delegation' => 'Bab Bhar', 'address' => '1 rue Test',
            'postal_code' => '1000', 'phone' => '22333444', 'payment_method' => 'cod', 'expected_total' => $quote['total'],
        ])->assertCreated()->json();
        $this->assertEquals(121.0, $res['total']);
        $this->assertSame(self::m('121'), self::m($this->parcel($res['order_id'], $seller)->cod_amount));

        // Tracking page: display amounts, the discount, never a delivery amount or a base price
        $this->flushHeaders();
        $track = $this->getJson('/api/orders/track/' . $res['tracking_token'])->assertOk()->json('data');
        $this->assertEquals(130.0, $track['subtotal']);
        $this->assertEquals(9.0, $track['multi_item_discount']);
        $this->assertEquals(0.0, $track['shipping_fee']);
        $this->assertTrue($track['free_delivery']);
        $this->assertEquals(121.0, $track['total']);
        $this->assertEqualsCanonicalizing([39.0, 91.0], array_column($track['parcels'][0]['items'], 'total'));
    }

    // ── "Mes commandes": discount line, free delivery, display prices ─────────

    public function test_buyer_order_shows_display_prices_the_discount_and_free_delivery(): void
    {
        [, $tshirt, , $res] = $this->exampleA();

        $order = $this->as($this->customer)->getJson('/api/client/orders/' . $res['order_id'])->assertOk()->json('data');
        $this->assertEquals(130.0, $order['subtotal']);
        $this->assertEquals(9.0, $order['multi_item_discount']);
        $this->assertEquals(0.0, $order['shipping_fee']);
        $this->assertTrue($order['free_delivery']);
        $this->assertEquals(121.0, $order['total_amount']);
        $this->assertEquals(39.0, collect($order['items'])->firstWhere('product_id', $tshirt->id)['unit_price']);
        $group = $order['seller_groups'][0];
        $this->assertEquals([130.0, 9.0, 0.0, 121.0], [$group['subtotal'], $group['multi_item_discount'], $group['delivery_fee'], $group['total']]);
        $this->assertTrue($group['is_free_delivery']);
    }

    // ── Old orders keep their delivery amount (Q5) ────────────────────────────

    public function test_an_order_placed_before_the_change_still_shows_its_delivery_amount(): void
    {
        [$seller, $tshirt, , $res] = $this->exampleA();

        // Same order as it would have been stored before the change: no "included" snapshot
        DB::table('orders')->where('id', $res['order_id'])->update(['pricing_model' => null, 'included_fees_total' => null, 'multi_item_discount' => null]);
        DB::table('seller_orders')->where('order_id', $res['order_id'])->update([
            'pricing_model' => null, 'included_fees_total' => null, 'parcel_fee' => null, 'multi_item_discount' => null, 'client_total' => null, 'fee_rates' => null,
        ]);
        DB::table('order_items')->where('order_id', $res['order_id'])->update([
            'included_fee' => null, 'included_fee_total' => null, 'display_unit_price' => null, 'display_original_unit_price' => null,
        ]);

        $order = $this->as($this->customer)->getJson('/api/client/orders/' . $res['order_id'])->assertOk()->json('data');
        $this->assertEquals(110.0, $order['subtotal']);
        $this->assertEquals(11.0, $order['shipping_fee'], 'its real delivery amount');
        $this->assertFalse($order['free_delivery']);
        $this->assertEquals(0.0, $order['multi_item_discount']);
        $this->assertEquals(121.0, $order['total_amount']);
        $this->assertEquals(30.0, collect($order['items'])->firstWhere('product_id', $tshirt->id)['unit_price']);
        $this->assertEquals(11.0, $order['seller_groups'][0]['delivery_fee']);

        // And a return on it refunds the items only, as before (delivery never refunded)
        $order = $this->delivered($res['order_id']);
        $c = $this->returned($order, $seller, ['return_all' => 1], 'damaged_product');
        $this->assertSame(self::m('110'), self::m($c->refund_amount));
        $this->assertFalse(SellerAdjustment::where('complaint_id', $c->id)->where('type', SellerAdjustment::TYPE_RETURN_PARCEL_FEE)->exists());
    }

    // ── SQL price sort / filter agree with the PHP display price ──────────────

    public function test_sql_price_sort_and_filter_match_the_display_price(): void
    {
        $seller = $this->seller('free');
        $xl     = $this->item($seller, 20, 'XL');   // 37
        $s      = $this->item($seller, 30, 'S');    // 39
        $l      = $this->item($seller, 27, 'L');    // 38
        $promo  = $this->item($seller, 60, 'S');    // 60 − 50 % = 30 → 39… pushed to 45 below
        $this->promotion($promo, 'discount', 40);   // 36 + 9 = 45

        $asc = collect($this->getJson("/api/products?seller_id={$seller->id}&sort=price_asc")->assertOk()->json('data.data'));
        $this->assertSame([$xl->id, $l->id, $s->id, $promo->id], $asc->pluck('id')->all());
        $this->assertEquals([37.0, 38.0, 39.0, 45.0], $asc->pluck('final_price')->map(fn ($v) => (float) $v)->all());

        $pricing = app(PricingService::class)->priceMany([$xl, $s, $l, $promo]);
        foreach ($asc as $row) {
            $this->assertEquals($pricing[$row['id']]['final_price'], (float) $row['final_price']);
        }
        $between = collect($this->getJson("/api/products?seller_id={$seller->id}&price_min=38&price_max=39")->json('data.data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$l->id, $s->id], $between);
    }
}
