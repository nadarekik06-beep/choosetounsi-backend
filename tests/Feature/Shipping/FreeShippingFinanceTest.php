<?php

namespace Tests\Feature\Shipping;

use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Free shipping is a seller's marketing choice, not a free service: the
 * customer pays 0 but the agency still bills the order, and that cost comes
 * off the seller's earnings. Normal orders must behave exactly as before for
 * the customer, with the fee passed through to the agency.
 *
 * Run only this folder:  php vendor/bin/phpunit tests/Feature/Shipping
 */
class FreeShippingFinanceTest extends TestCase
{
    use DatabaseTransactions;

    private const COST = 8.0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.shipping_cost' => self::COST]);
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'name'      => 'Ship Test ' . Str::random(5),
            'email'     => 'ship_' . Str::random(10) . '@test.local',
            'password'  => bcrypt('secret-password'),
            'role'      => $role,
            'is_active' => true,
        ]);
    }

    private function makeProduct(User $seller, float $price, ?float $deliveryFee): Product
    {
        $name = 'Ship Product ' . Str::random(6);
        return Product::create([
            'seller_id'    => $seller->id,
            'name'         => $name,
            'slug'         => Str::slug($name),
            'price'        => $price,
            'delivery_fee' => $deliveryFee,
            'stock'        => 50,
            'is_approved'  => true,
            'is_active'    => true,
        ]);
    }

    private function checkout(User $customer, array $products): array
    {
        foreach ($products as $product) {
            Cart::create(['user_id' => $customer->id, 'product_id' => $product->id, 'quantity' => 1]);
        }

        $this->app['auth']->forgetGuards();
        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $customer->createToken('t')->plainTextToken])
            ->postJson('/api/checkout', [
                'wilaya'         => 'Tunis',
                'address'        => '1 rue Test',
                'phone'          => '22123456',
                'payment_method' => 'cod',
            ])
            ->assertCreated();

        return $res->json();
    }

    private function sellerOrder(int $orderId, int $sellerId): object
    {
        return DB::table('seller_orders')->where('order_id', $orderId)->where('seller_id', $sellerId)->first();
    }

    private function itemsCommission(int $sellerOrderId): array
    {
        $row = DB::table('order_items')->where('seller_order_id', $sellerOrderId)
            ->selectRaw('SUM(commission_amount) c, SUM(seller_amount) s, SUM(net_total) n')->first();
        return [(float) $row->c, (float) $row->s, (float) $row->n];
    }

    public function test_normal_order_customer_pays_shipping_and_seller_is_not_charged(): void
    {
        $seller   = $this->makeUser('seller');
        $customer = $this->makeUser('client');
        $product  = $this->makeProduct($seller, 100, null);

        $res = $this->checkout($customer, [$product]);

        // Customer: unchanged — 100 + 8 shipping.
        $this->assertEquals(Product::DEFAULT_DELIVERY_FEE, $res['shipping_fee']);
        $this->assertEquals(100 + Product::DEFAULT_DELIVERY_FEE, $res['total']);

        $order = DB::table('orders')->find($res['order_id']);
        $this->assertSame('customer', $order->shipping_paid_by);
        $this->assertEquals(self::COST, (float) $order->shipping_cost);

        $so = $this->sellerOrder($order->id, $seller->id);
        [$commission, $itemsNet, $base] = $this->itemsCommission($so->id);

        // Commission still on the item price, not touched by shipping.
        $this->assertEquals(100.0, $base);
        $this->assertGreaterThan(0, $commission);
        $this->assertEqualsWithDelta(100 - $commission, $itemsNet, 0.001);

        // Seller: no shipping deducted.
        $this->assertEquals(0.0, (float) $so->seller_shipping_charge);
        $this->assertEqualsWithDelta($itemsNet, (float) $so->seller_net_amount, 0.001);

        // Platform: fee in, agency cost out → just the commission.
        $this->assertEquals(Product::DEFAULT_DELIVERY_FEE, (float) $so->delivery_fee);
        $this->assertEquals(self::COST, (float) $so->shipping_cost);
        $this->assertEqualsWithDelta($commission, (float) $so->platform_profit, 0.001);
    }

    public function test_free_shipping_order_customer_pays_nothing_and_seller_pays_the_agency(): void
    {
        $seller   = $this->makeUser('seller');
        $customer = $this->makeUser('client');
        $product  = $this->makeProduct($seller, 100, 0);

        $res = $this->checkout($customer, [$product]);

        // Customer: still free shipping.
        $this->assertEquals(0, $res['shipping_fee']);
        $this->assertEquals(100, $res['total']);

        $order = DB::table('orders')->find($res['order_id']);
        $this->assertSame('seller', $order->shipping_paid_by);
        $this->assertEquals(self::COST, (float) $order->shipping_cost);

        $so = $this->sellerOrder($order->id, $seller->id);
        [$commission, $itemsNet, $base] = $this->itemsCommission($so->id);

        // Commission base unchanged: the sale amount.
        $this->assertEquals(100.0, $base);
        $this->assertEqualsWithDelta(100 - $commission, $itemsNet, 0.001);

        // Seller: sale − commission − shipping = net.
        $this->assertEquals(self::COST, (float) $so->seller_shipping_charge);
        $this->assertEqualsWithDelta(100 - $commission - self::COST, (float) $so->seller_net_amount, 0.001);

        // Platform: charge in, agency cost out → just the commission.
        $this->assertEquals(0.0, (float) $so->delivery_fee);
        $this->assertEqualsWithDelta($commission, (float) $so->platform_profit, 0.001);

        // Seller-facing endpoints show the deduction.
        $this->app['auth']->forgetGuards();
        $token = $seller->createToken('t')->plainTextToken;

        $get = fn(string $url) => $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson($url)->assertOk();
        $net = 100 - $commission - self::COST;

        $overview = $get('/api/seller/earnings/overview?period=all');
        $this->assertEqualsWithDelta(self::COST, $overview->json('data.kpis.total_shipping'), 0.001);
        $this->assertEqualsWithDelta($net, $overview->json('data.kpis.total_net'), 0.001);

        $detail = $get("/api/seller/orders/{$so->id}");
        $this->assertEqualsWithDelta(self::COST, $detail->json('data.commission.shipping_paid_by_seller'), 0.001);
        $this->assertEqualsWithDelta($net, $detail->json('data.commission.net_after_shipping'), 0.001);

        $this->assertEqualsWithDelta(self::COST, $get('/api/seller/shipping-cost')->json('data.shipping_cost'), 0.001);

        // Customer never sees the internal cost split.
        $this->assertArrayNotHasKey('shipping_paid_by', \App\Models\Order::find($order->id)->toArray());
    }

    public function test_free_shipping_cost_is_split_between_sellers_of_one_order(): void
    {
        $sellerA  = $this->makeUser('seller');
        $sellerB  = $this->makeUser('seller');
        $customer = $this->makeUser('client');

        $res = $this->checkout($customer, [
            $this->makeProduct($sellerA, 60, 0),
            $this->makeProduct($sellerB, 40, 0),
        ]);
        $this->assertEquals(0, $res['shipping_fee']);

        $a = $this->sellerOrder($res['order_id'], $sellerA->id);
        $b = $this->sellerOrder($res['order_id'], $sellerB->id);

        // One shipment → charged once in total, never double counted.
        $this->assertEqualsWithDelta(self::COST, (float) $a->seller_shipping_charge + (float) $b->seller_shipping_charge, 0.0005);
        $this->assertEqualsWithDelta(self::COST, (float) $a->shipping_cost + (float) $b->shipping_cost, 0.0005);
        $this->assertEqualsWithDelta(self::COST / 2, (float) $b->seller_shipping_charge, 0.0005);
    }

    public function test_mixed_cart_is_customer_paid(): void
    {
        $seller   = $this->makeUser('seller');
        $customer = $this->makeUser('client');

        $res = $this->checkout($customer, [
            $this->makeProduct($seller, 30, 0),
            $this->makeProduct($seller, 20, null),
        ]);

        $this->assertEquals(Product::DEFAULT_DELIVERY_FEE, $res['shipping_fee']);
        $so = $this->sellerOrder($res['order_id'], $seller->id);
        $this->assertEquals(0.0, (float) $so->seller_shipping_charge);
        $this->assertSame('customer', DB::table('orders')->where('id', $res['order_id'])->value('shipping_paid_by'));
    }

    public function test_admin_finance_accounts_for_shipping_once(): void
    {
        $seller   = $this->makeUser('seller');
        $customer = $this->makeUser('client');
        $admin    = $this->makeUser('admin');

        $free   = $this->checkout($customer, [$this->makeProduct($seller, 100, 0)]);
        $normal = $this->checkout($customer, [$this->makeProduct($seller, 100, null)]);

        $this->app['auth']->forgetGuards();
        $rows = collect($this->withHeaders(['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken])
            ->getJson("/api/admin/finance/orders?seller_id={$seller->id}")
            ->assertOk()
            ->json('data.data'))->keyBy('order_id');

        foreach ([$free['order_id'], $normal['order_id']] as $orderId) {
            $r = $rows[$orderId];
            // Agency cost recovered exactly once: from the customer or the seller.
            $this->assertEqualsWithDelta(self::COST, (float) $r['shipping_cost'], 0.001);
            $this->assertEqualsWithDelta(self::COST, (float) $r['delivery_fee'] + (float) $r['seller_shipping_charge'], 0.001);
            $this->assertEqualsWithDelta((float) $r['commission_amount'], (float) $r['platform_profit'], 0.001);
        }
        $this->assertSame('seller', $rows[$free['order_id']]['shipping_paid_by']);
        $this->assertSame('customer', $rows[$normal['order_id']]['shipping_paid_by']);

        // Order detail modal: seller net after shipping on the free order only.
        $summary = fn(int $id) => $this->getJson("/api/admin/orders/{$id}")->assertOk()->json('data.commission_summary');
        $s = $summary($free['order_id']);
        $this->assertSame('seller', $s['shipping_paid_by']);
        $this->assertEqualsWithDelta(self::COST, $s['seller_shipping'], 0.001);
        $this->assertEqualsWithDelta($s['total_seller'] - self::COST, $s['total_seller_net'], 0.001);
        $n = $summary($normal['order_id']);
        $this->assertEqualsWithDelta(0, $n['seller_shipping'], 0.001);
        $this->assertEqualsWithDelta($n['total_seller'], $n['total_seller_net'], 0.001);
    }
}
