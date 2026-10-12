<?php

namespace Tests\Feature\Intigo;

use App\Models\Coupon;
use App\Models\Product;
use App\Support\TunisianPhone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Prepared" sends one Intigo parcel per seller_order, whose COD price is
 * exactly what the client was shown for that shop; sizes and per-size prices.
 * Admin fees: client 8, agency 7, seller contribution 6 (every size) unless set apart.
 */
class IntigoSendPayloadTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
    }

    public function test_multi_seller_order_sends_one_parcel_per_shop_with_the_amount_the_client_saw(): void
    {
        // A: coupon −10 · B: flash sale −25% · C: free shipping offered by the shop
        $a = $this->sellerWithPickup(3);
        $b = $this->sellerWithPickup(4, 'red');
        $c = $this->sellerWithPickup(5, 'black');
        $pa = $this->product($a, 100);
        $coupon = Coupon::create(['seller_id' => $a->id, 'code' => 'IGO' . Str::upper(Str::random(5)), 'discount_type' => 'fixed', 'discount_value' => 10, 'is_active' => true]);
        $coupon->products()->attach($pa->id);
        $this->addToCart($pa, 1);
        $pb = $this->product($b, 80);
        $this->promotion($pb, 'flash_sale', 25);
        $this->addToCart($pb, 2);
        $this->addToCart($this->product($c, 120, true));

        $quote = $this->quote(['coupon_codes' => [$coupon->code]]);
        [, $orderId] = $this->placeAndConfirm(['coupon_codes' => [$coupon->code]]);

        $shown = collect($quote['parcels'])->keyBy('seller_id');
        $expected = [$a->id => 98.0, $b->id => 128.0, $c->id => 120.0];   // 100−10+8 · 2×60+8 · 120+0
        foreach ([$a, $b, $c] as $seller) {
            $so = $this->parcel($orderId, $seller);
            $this->prepare($so, $seller);
            $payload = $this->sentPayload($so);

            $this->assertNotNull($payload, "parcel of seller {$seller->id} sent");
            $this->assertEquals($expected[$seller->id], $payload['price']);
            $this->assertEquals($shown[$seller->id]['total'], $payload['price'], 'price = the total the client was shown for that shop');
            $this->assertEquals((float) $this->fresh($so)->cod_amount, $payload['price']);
            $this->assertSame('CT-SO-' . $so->id, $payload['cid']);
            $this->assertSame(1, $payload['package_size']);
            $this->assertSame((int) $seller->intigo_pickup_index, $payload['pickup_index']);
            $this->assertFalse($payload['can_open']);
            $this->assertArrayNotHasKey('is_exchange', $payload);
            // Order reference only: never the seller's name, phone or address
            $order = DB::table('orders')->find($orderId);
            $this->assertSame("{$order->order_number}-S{$so->id}", $payload['additional_info']);
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
            foreach (['55111222', '98000111', 'Route de Tunis', $seller->name] as $secret) {
                $this->assertStringNotContainsString($secret, $encoded);
            }
            $this->assertSame('Test Buyer', $payload['recipient_name']);
            $this->assertSame('22123456', $payload['phone1']);

            $fresh = $this->so($so);
            $this->assertSame('sent', $fresh->intigo_sync_status);
            $this->assertSame('FAKE-' . $so->id, $fresh->intigo_nid);
            $this->assertSame('confirmed', $fresh->status, 'prepared is not a status: Intigo reports the pickup');
            $this->assertNotNull($fresh->prepared_at);
        }
        $this->assertStringContainsString('2 × ', $this->sentPayload($this->parcel($orderId, $b))['description']);
    }

    public function test_parcel_size_is_the_largest_item_and_every_delivery_amount_follows_it(): void
    {
        $this->setSizeFees(['S' => [8, 7, 4, 6], 'L' => [12, 10, 6, 9], 'XL' => [20, 17, 9, 15]]);
        $seller = $this->sellerWithPickup();
        $small  = $this->product($seller, 30);
        $large  = $this->product($seller, 50);
        $large->update(['parcel_size' => 'L']);
        $this->addToCart($small, 2);
        $this->addToCart($large);

        $quote = $this->quote();
        $this->assertSame('L', $quote['parcels'][0]['parcel_size']);
        $this->assertEquals(12, $quote['parcels'][0]['delivery_fee']);

        [, $orderId] = $this->placeAndConfirm();
        $so = $this->parcel($orderId, $seller);
        $this->assertSame('L', $so->parcel_size);
        $this->assertSame('L', $so->parcel_size_computed);
        $this->assertParcel($so, ['items' => '110', 'delivery_fee' => '12', 'cod' => '122', 'agency' => '10', 'margin' => '2']);

        $this->prepare($so, $seller);
        $this->assertSame(4, $this->sentPayload($so)['package_size']);
        $this->assertEquals(122.0, $this->sentPayload($so)['price']);
    }

    public function test_free_shipping_on_an_xl_parcel_charges_the_xl_seller_contribution(): void
    {
        $this->setSizeFees(['S' => [8, 7, 4, 6], 'L' => [12, 10, 6, 9], 'XL' => [20, 17, 9, 15]]);
        $seller = $this->sellerWithPickup();
        $sofa = $this->product($seller, 300, true);
        $sofa->update(['parcel_size' => 'XL']);
        $this->addToCart($sofa);

        [, $orderId] = $this->placeAndConfirm();
        $so = $this->parcel($orderId, $seller);
        $this->assertParcel($so, ['delivery_fee' => '0', 'cod' => '300', 'contribution' => '15', 'agency' => '17', 'margin' => '-2', 'free' => true]);

        $this->prepare($so, $seller);
        $this->assertSame(5, $this->sentPayload($so)['package_size']);
        $this->assertEquals(300.0, $this->sentPayload($so)['price']);
    }

    public function test_a_pack_takes_the_size_of_its_largest_product(): void
    {
        $this->setSizeFees(['S' => [8, 7, 4, 6], 'L' => [12, 10, 6, 9], 'XL' => [20, 17, 9, 15]]);
        $seller = $this->sellerWithPickup();
        $p1 = $this->product($seller, 20);
        $p2 = $this->product($seller, 40);
        $p2->update(['parcel_size' => 'XL']);
        $this->addPack($this->pack($seller, 50, [[$p1, 1], [$p2, 1]]));

        $quote = $this->quote();
        $this->assertSame('XL', $quote['parcels'][0]['parcel_size']);
        $this->assertEquals(20, $quote['parcels'][0]['delivery_fee']);
    }

    public function test_a_parcel_with_nothing_to_collect_is_never_sent(): void
    {
        $client = $this->scripted();
        $seller = $this->sellerWithPickup();
        $product = $this->product($seller, 40, true);   // free shipping
        $coupon = Coupon::create(['seller_id' => $seller->id, 'code' => 'FREE' . Str::upper(Str::random(5)), 'discount_type' => 'percentage', 'discount_value' => 100, 'is_active' => true]);
        $coupon->products()->attach($product->id);
        $this->addToCart($product);

        [, $orderId] = $this->placeAndConfirm(['coupon_codes' => [$coupon->code]]);
        $so = $this->parcel($orderId, $seller);
        $this->assertSame(0, self::m($so->cod_amount));

        $this->prepare($so, $seller)->assertJsonPath('data.delivery.state', 'failed');
        $this->assertCount(0, $client->calls['create'], 'no API call');
        $fresh = $this->so($so);
        $this->assertSame('failed', $fresh->intigo_sync_status);
        $this->assertStringContainsString('cash-on-delivery amount is 0.000', $fresh->intigo_error);
        $this->assertTrue(DB::table('notifications')->where('notifiable_id', $this->admin->id)->where('data', 'like', '%intigo%')->exists(), 'admin notified');
    }

    public function test_a_seller_without_an_intigo_pickup_address_is_never_sent(): void
    {
        $client = $this->scripted();
        $seller = $this->seller();   // no pickup index
        $this->addToCart($this->product($seller, 40));
        [, $orderId] = $this->placeAndConfirm();
        $so = $this->parcel($orderId, $seller);

        $this->prepare($so, $seller);
        $this->assertCount(0, $client->calls['create']);
        $this->assertSame('failed', $this->so($so)->intigo_sync_status);
        $this->assertStringContainsString('seller has no Intigo pickup address', $this->so($so)->intigo_error);
    }

    public function test_prepared_is_always_saved_even_when_intigo_is_down(): void
    {
        $client = $this->scripted();
        $client->onCreate = array_fill(0, 3, ScriptedIntigoClient::fails(500, ['detail' => 'Internal Server Error']));
        $seller = $this->sellerWithPickup();
        $this->addToCart($this->product($seller, 40));
        [, $orderId] = $this->placeAndConfirm();
        $so = $this->parcel($orderId, $seller);

        $this->prepare($so, $seller)->assertJsonPath('success', true);
        $fresh = $this->so($so);
        $this->assertNotNull($fresh->prepared_at);
        $this->assertSame('pending', $fresh->intigo_sync_status, 'a 5xx is retried later');
        $this->assertNull($fresh->intigo_nid);
    }

    public function test_phone_numbers_are_normalized_to_8_digits(): void
    {
        foreach (['+216 22 123 456', '00216 22-123-456', '22 123 456', '22.123.456'] as $raw) {
            $this->assertSame('22123456', TunisianPhone::normalize($raw), $raw);
            $this->assertTrue(TunisianPhone::isValid($raw), $raw);
        }
        $this->assertFalse(TunisianPhone::isValid('2212345'));
        $this->assertFalse(TunisianPhone::isValid('+33 6 12 34 56 78'));

        $seller = $this->sellerWithPickup();
        $this->addToCart($this->product($seller, 40));
        $quote = $this->quote();
        $res = $this->as($this->customer)->postJson('/api/checkout', [
            'phone' => '+216 98 765 432', 'phone_secondary' => '00216 55-444-333',
        ] + $this->address() + ['payment_method' => 'cod', 'expected_total' => $quote['total']])->assertCreated()->json();
        $order = DB::table('orders')->find($res['order_id']);
        $this->assertSame('98765432', $order->phone);
        $this->assertSame('55444333', $order->phone_secondary);

        $this->as($this->admin)->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();
        $so = $this->parcel($order->id, $seller);
        $this->prepare($so, $seller);
        $this->assertSame('98765432', $this->sentPayload($so)['phone1']);
        $this->assertSame('55444333', $this->sentPayload($so)['phone2']);

        $this->as($this->customer)->postJson('/api/checkout', ['phone' => '+216 98 765'] + $this->address())->assertStatus(422);
    }

    public function test_can_open_follows_the_admin_setting(): void
    {
        app(\App\Services\Delivery\DeliverySettings::class)->update(['intigo_can_open' => true], $this->admin->id);
        $seller = $this->sellerWithPickup();
        $this->addToCart($this->product($seller, 40));
        [, $orderId] = $this->placeAndConfirm();
        $so = $this->parcel($orderId, $seller);
        $this->prepare($so, $seller);
        $this->assertTrue($this->sentPayload($so)['can_open']);
    }

    public function test_the_platform_own_products_use_the_platform_pickup_index(): void
    {
        config(['intigo.platform_pickup_index' => 7]);
        // CHOOSE'Tounsi's own products (no seller account)
        $product = Product::create([
            'seller_id' => null, 'name' => 'CT Product ' . Str::random(5), 'slug' => 'ct-' . Str::random(8),
            'price' => 25, 'stock' => 10, 'is_approved' => true, 'is_active' => true, 'is_platform_product' => true,
        ]);
        $this->addToCart($product);
        [, $orderId] = $this->placeAndConfirm();
        $so = $this->parcel($orderId, null);

        $result = app(\App\Services\Intigo\ParcelSender::class)->send($so->id);
        $this->assertSame('sent', $result['result'], $result['message']);
        $this->assertSame(7, $this->sentPayload($so)['pickup_index']);

        config(['intigo.platform_pickup_index' => null]);
        $this->assertSame(['seller has no Intigo pickup address'], app(\App\Services\Intigo\ParcelPayload::class)->build($this->so($so))['problems']);
    }
}
