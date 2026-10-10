<?php

namespace Tests\Feature\Delivery;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Packs in the one-seller-one-parcel model: merged into the seller's parcel,
 * priced at the current pack price × quantity, split by value between sellers.
 */
class PackParcelsTest extends TestCase
{
    use DatabaseTransactions, DeliveryTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDelivery();
    }

    public function test_two_packs_from_the_same_seller_are_one_parcel(): void
    {
        $seller = $this->seller('free');
        $this->addPack($this->pack($seller, 40, [[$this->product($seller, 30), 1], [$this->product($seller, 20), 1]]));
        $this->addPack($this->pack($seller, 25, [[$this->product($seller, 15), 2]]));

        $res = $this->checkout();
        $this->assertCount(1, $res['parcels']);
        $this->assertEquals(73.0, $res['total']);   // 40 + 25 + one fee of 8
        $this->assertSame(1, DB::table('seller_orders')->where('order_id', $res['order_id'])->count());
        // 15% of 40 + 15% of 25
        $this->assertParcel($this->parcel($res['order_id'], $seller), [
            'items' => '65', 'delivery_fee' => '8', 'cod' => '73', 'commission' => '9.75', 'payout' => '55.25', 'margin' => '1',
        ]);
    }

    public function test_pack_and_product_from_the_same_seller_are_one_parcel(): void
    {
        $seller = $this->seller('free');
        $this->addToCart($this->product($seller, 50));
        $this->addPack($this->pack($seller, 40, [[$this->product($seller, 30), 1], [$this->product($seller, 20), 1]]));

        $res = $this->checkout();
        $this->assertCount(1, $res['parcels']);
        $this->assertEquals(98.0, $res['total']);
        $this->assertParcel($this->parcel($res['order_id'], $seller), [
            'items' => '90', 'cod' => '98', 'commission' => '13.5', 'payout' => '76.5', 'margin' => '1',
        ]);
    }

    public function test_multi_seller_pack_is_split_by_value_with_the_remainder_on_the_last_seller(): void
    {
        $a = $this->seller('free');
        $b = $this->seller('free');
        // Normal prices 60 (A) and 2 × 20 = 40 (B) → 60 % / 40 % of 70.001
        $this->addPack($this->pack($a, '70.001', [[$this->product($a, 60), 1], [$this->product($b, 20), 2]]));

        $res = $this->checkout();
        $this->assertCount(2, $res['parcels']);
        $pa = $this->parcel($res['order_id'], $a);
        $pb = $this->parcel($res['order_id'], $b);
        // A: 70001 × 60 % = 42000.6 → 42.001 (half-up); B: the remainder 28.000
        $this->assertParcel($pa, ['items' => '42.001', 'delivery_fee' => '8', 'cod' => '50.001']);
        $this->assertParcel($pb, ['items' => '28', 'delivery_fee' => '8', 'cod' => '36']);
        $this->assertSame(70001, self::m($pa->subtotal) + self::m($pb->subtotal));
        $this->assertEquals(86.001, $res['total']);
    }

    public function test_pack_price_changed_since_add_to_cart_asks_the_client_to_confirm(): void
    {
        $seller = $this->seller('free');
        $pack   = $this->pack($seller, 45, [[$this->product($seller, 30), 1], [$this->product($seller, 20), 1]]);
        $row    = $this->addPack($pack, 1, 40);   // added when it cost 40

        // Old total (what the cart showed) → 409 with the new one
        $this->as($this->customer)->postJson('/api/checkout', $this->address() + ['payment_method' => 'cod', 'expected_total' => 48])
            ->assertStatus(409)->assertJsonPath('code', 'price_changed')->assertJsonPath('data.total', 53);
        // No expected total either → still refused, never charged silently
        Cart::whereKey($row->id)->update(['pack_price_snapshot' => 40]);
        $this->as($this->customer)->postJson('/api/checkout', $this->address() + ['payment_method' => 'cod'])
            ->assertStatus(409)->assertJsonPath('data.total', 53);
        // The cart now shows the new price
        $this->assertSame(45000, self::m(Cart::find($row->id)->pack_price_snapshot));

        $res = $this->checkout();   // confirms the quoted 53
        $this->assertEquals(53.0, $res['total']);
        $this->assertParcel($this->parcel($res['order_id'], $seller), ['items' => '45', 'cod' => '53']);
    }

    public function test_pack_quantity_is_honoured(): void
    {
        $seller = $this->seller('free');
        $p1 = $this->product($seller, 30, false, 10);
        $p2 = $this->product($seller, 20, false, 10);
        $this->addPack($this->pack($seller, 40, [[$p1, 1], [$p2, 2]]), 3);

        $res = $this->checkout();
        $this->assertEquals(128.0, $res['total']);   // 3 × 40 + 8
        $so = $this->parcel($res['order_id'], $seller);
        // Commission tier from one pack's price (40 → 15 %), on 120
        $this->assertParcel($so, ['items' => '120', 'cod' => '128', 'commission' => '18', 'payout' => '102']);
        $this->assertSame(7, Product::find($p1->id)->stock);   // 10 − 1 × 3
        $this->assertSame(4, Product::find($p2->id)->stock);   // 10 − 2 × 3
        $this->assertEquals([3, 6], DB::table('order_items')->where('seller_order_id', $so->id)->orderBy('id')->pluck('quantity')->all());
    }

    public function test_pack_quantity_beyond_stock_is_refused(): void
    {
        $seller = $this->seller('free');
        $this->addPack($this->pack($seller, 40, [[$this->product($seller, 30, false, 2), 1]]), 3);
        $this->as($this->customer)->postJson('/api/checkout/quote')->assertStatus(422);
    }

    public function test_free_pack_only_when_all_its_products_are_free(): void
    {
        $seller = $this->seller('free');
        $this->addPack($this->pack($seller, 40, [[$this->product($seller, 30, true), 1], [$this->product($seller, 20, true), 1]]));
        $res = $this->checkout();
        $this->assertParcel($this->parcel($res['order_id'], $seller), [
            'items' => '40', 'delivery_fee' => '0', 'cod' => '40', 'contribution' => '6', 'payout' => '28', 'margin' => '-1', 'free' => true,
        ]);

        // One paid product inside → the pack (and its parcel) pays delivery
        $this->addPack($this->pack($seller, 40, [[$this->product($seller, 30, true), 1], [$this->product($seller, 20), 1]]));
        $res = $this->checkout();
        $this->assertParcel($this->parcel($res['order_id'], $seller), ['delivery_fee' => '8', 'cod' => '48', 'contribution' => '0', 'free' => false]);
    }

    public function test_free_product_with_a_paid_product_makes_a_paid_parcel(): void
    {
        $seller = $this->seller('free');
        $this->addToCart($this->product($seller, 30, true));
        $this->addToCart($this->product($seller, 20));
        $res = $this->checkout();
        $this->assertParcel($this->parcel($res['order_id'], $seller), ['delivery_fee' => '8', 'contribution' => '0', 'cod' => '58', 'free' => false]);
    }
}
