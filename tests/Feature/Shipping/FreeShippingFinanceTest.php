<?php

namespace Tests\Feature\Shipping;

use App\Models\Cart;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\User;
use App\Services\Delivery\DeliverySettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Free delivery is a seller's marketing choice, not a free service: the
 * client pays 0 for that seller's parcel, the agency still bills it, and the
 * seller pays the admin's free-delivery contribution out of their earnings.
 * Admin values here: client fee 8, agency cost 7, seller contribution 6.
 *
 * Run only this folder:  php vendor/bin/phpunit tests/Feature/Shipping
 */
class FreeShippingFinanceTest extends TestCase
{
    use DatabaseTransactions;

    private const FEE          = 8.0;
    private const AGENCY       = 7.0;
    private const CONTRIBUTION = 6.0;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::flushCache();
        app(DeliverySettings::class)->update([
            'client_delivery_fee' => 8000, 'agency_delivery_cost' => 7000, 'seller_free_delivery_contribution' => 6000,
        ], null);
    }

    private function makeUser(string $role): User
    {
        return $this->withCompleteProfile(User::create([
            'name'      => 'Ship Test ' . Str::random(5),
            'email'     => 'ship_' . Str::random(10) . '@test.local',
            'password'  => bcrypt('secret-password'),
            'role'      => $role,
            'is_active' => true,
        ]));
    }

    private function makeProduct(User $seller, float $price, bool $free): Product
    {
        $name = 'Ship Product ' . Str::random(6);
        return Product::create([
            'seller_id'    => $seller->id,
            'name'         => $name,
            'slug'         => Str::slug($name),
            'price'        => $price,
            'delivery_fee' => $free ? 0 : null,
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
        return $this->withHeaders(['Authorization' => 'Bearer ' . $customer->createToken('t')->plainTextToken])
            ->postJson('/api/checkout', [
                'recipient_name' => 'Test Buyer',
                'wilaya'         => 'Tunis',
                'delegation'     => 'Bab Bhar',
                'address'        => '1 rue Test',
                'postal_code'    => '1000',
                'phone'          => '22123456',
                'payment_method' => 'cod',
            ])
            ->assertCreated()
            ->json();
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

    public function test_normal_parcel_customer_pays_delivery_and_seller_is_not_charged(): void
    {
        $seller   = $this->makeUser('seller');
        $customer = $this->makeUser('client');
        $res = $this->checkout($customer, [$this->makeProduct($seller, 100, false)]);

        $this->assertEquals(self::FEE, $res['shipping_fee']);
        $this->assertEquals(100 + self::FEE, $res['total']);

        $order = DB::table('orders')->find($res['order_id']);
        $this->assertSame('customer', $order->shipping_paid_by);
        $this->assertEquals(self::AGENCY, (float) $order->shipping_cost);

        $so = $this->sellerOrder($order->id, $seller->id);
        [$commission, $itemsNet, $base] = $this->itemsCommission($so->id);

        // Commission on the item price only, never on delivery
        $this->assertEquals(100.0, $base);
        $this->assertGreaterThan(0, $commission);
        $this->assertEqualsWithDelta(100 - $commission, $itemsNet, 0.001);

        $this->assertEquals(0.0, (float) $so->seller_shipping_charge);
        $this->assertEqualsWithDelta($itemsNet, (float) $so->seller_net_amount, 0.001);

        // Platform: commission + delivery margin (8 − 7)
        $this->assertEquals(self::FEE, (float) $so->delivery_fee);
        $this->assertEquals(self::AGENCY, (float) $so->shipping_cost);
        $this->assertEqualsWithDelta($commission + self::FEE - self::AGENCY, (float) $so->platform_profit, 0.001);
    }

    public function test_free_delivery_parcel_customer_pays_nothing_and_seller_pays_the_contribution(): void
    {
        $seller   = $this->makeUser('seller');
        $customer = $this->makeUser('client');
        $res = $this->checkout($customer, [$this->makeProduct($seller, 100, true)]);

        $this->assertEquals(0, $res['shipping_fee']);
        $this->assertEquals(100, $res['total']);

        $order = DB::table('orders')->find($res['order_id']);
        $this->assertSame('seller', $order->shipping_paid_by);

        $so = $this->sellerOrder($order->id, $seller->id);
        [$commission, , $base] = $this->itemsCommission($so->id);
        $this->assertEquals(100.0, $base);

        // Seller: sale − commission − contribution
        $net = 100 - $commission - self::CONTRIBUTION;
        $this->assertEquals(self::CONTRIBUTION, (float) $so->seller_shipping_charge);
        $this->assertEqualsWithDelta($net, (float) $so->seller_net_amount, 0.001);

        // Platform: commission + (contribution − agency cost)
        $this->assertEquals(0.0, (float) $so->delivery_fee);
        $this->assertEqualsWithDelta($commission + self::CONTRIBUTION - self::AGENCY, (float) $so->platform_profit, 0.001);

        // Seller-facing endpoints show the deduction and the cost of free delivery
        $this->app['auth']->forgetGuards();
        $token = $seller->createToken('t')->plainTextToken;
        $get = fn(string $url) => $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson($url)->assertOk();

        $overview = $get('/api/seller/earnings/overview?period=all');
        $this->assertEqualsWithDelta(self::CONTRIBUTION, $overview->json('data.kpis.total_shipping'), 0.001);
        $this->assertEqualsWithDelta($net, $overview->json('data.kpis.total_net'), 0.001);

        $detail = $get("/api/seller/orders/{$so->id}");
        $this->assertEqualsWithDelta(self::CONTRIBUTION, $detail->json('data.commission.shipping_paid_by_seller'), 0.001);
        $this->assertEqualsWithDelta($net, $detail->json('data.commission.net_after_shipping'), 0.001);

        $this->assertEqualsWithDelta(self::CONTRIBUTION, $get('/api/seller/shipping-cost')->json('data.free_delivery_contribution'), 0.001);

        // Customer never sees the internal cost split
        $this->assertArrayNotHasKey('shipping_paid_by', Order::find($order->id)->toArray());
    }

    public function test_each_free_delivery_seller_pays_its_own_parcel(): void
    {
        $sellerA  = $this->makeUser('seller');
        $sellerB  = $this->makeUser('seller');
        $customer = $this->makeUser('client');

        $res = $this->checkout($customer, [$this->makeProduct($sellerA, 60, true), $this->makeProduct($sellerB, 40, true)]);
        $this->assertEquals(0, $res['shipping_fee']);

        $a = $this->sellerOrder($res['order_id'], $sellerA->id);
        $b = $this->sellerOrder($res['order_id'], $sellerB->id);
        // Two parcels: each seller pays one contribution, the agency bills each parcel
        $this->assertEqualsWithDelta(self::CONTRIBUTION, (float) $a->seller_shipping_charge, 0.0005);
        $this->assertEqualsWithDelta(self::CONTRIBUTION, (float) $b->seller_shipping_charge, 0.0005);
        $this->assertEqualsWithDelta(self::AGENCY, (float) $a->shipping_cost, 0.0005);
        $this->assertEqualsWithDelta(self::AGENCY, (float) $b->shipping_cost, 0.0005);
    }

    public function test_a_parcel_mixing_free_and_paid_products_is_customer_paid(): void
    {
        $seller   = $this->makeUser('seller');
        $customer = $this->makeUser('client');
        $res = $this->checkout($customer, [$this->makeProduct($seller, 30, true), $this->makeProduct($seller, 20, false)]);

        $this->assertEquals(self::FEE, $res['shipping_fee']);
        $so = $this->sellerOrder($res['order_id'], $seller->id);
        $this->assertEquals(0.0, (float) $so->seller_shipping_charge);
        $this->assertSame('customer', DB::table('orders')->where('id', $res['order_id'])->value('shipping_paid_by'));
    }

    public function test_admin_finance_accounts_for_each_parcel_once(): void
    {
        $seller   = $this->makeUser('seller');
        $customer = $this->makeUser('client');
        $admin    = $this->makeUser('admin');

        $free   = $this->checkout($customer, [$this->makeProduct($seller, 100, true)]);
        $normal = $this->checkout($customer, [$this->makeProduct($seller, 100, false)]);

        $this->app['auth']->forgetGuards();
        $rows = collect($this->withHeaders(['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken])
            ->getJson("/api/admin/finance/orders?seller_id={$seller->id}")
            ->assertOk()
            ->json('data.data'))->keyBy('order_id');

        foreach ([$free['order_id'], $normal['order_id']] as $orderId) {
            $r = $rows[$orderId];
            $this->assertEqualsWithDelta(self::AGENCY, (float) $r['shipping_cost'], 0.001);
            // margin = what came in for delivery (client fee or seller contribution) − agency cost
            $this->assertEqualsWithDelta((float) $r['delivery_fee'] + (float) $r['seller_shipping_charge'] - self::AGENCY, (float) $r['platform_delivery_margin'], 0.001);
            $this->assertEqualsWithDelta((float) $r['commission_amount'] + (float) $r['platform_delivery_margin'], (float) $r['platform_profit'], 0.001);
        }
        $this->assertSame('seller', $rows[$free['order_id']]['shipping_paid_by']);
        $this->assertSame('customer', $rows[$normal['order_id']]['shipping_paid_by']);

        // Order detail modal: seller net after the contribution on the free order only
        $summary = fn(int $id) => $this->getJson("/api/admin/orders/{$id}")->assertOk()->json('data.commission_summary');
        $s = $summary($free['order_id']);
        $this->assertSame('seller', $s['shipping_paid_by']);
        $this->assertEqualsWithDelta(self::CONTRIBUTION, $s['seller_shipping'], 0.001);
        $this->assertEqualsWithDelta($s['total_seller'] - self::CONTRIBUTION, $s['total_seller_net'], 0.001);
        $n = $summary($normal['order_id']);
        $this->assertEqualsWithDelta(0, $n['seller_shipping'], 0.001);
        $this->assertEqualsWithDelta($n['total_seller'], $n['total_seller_net'], 0.001);
    }
}
