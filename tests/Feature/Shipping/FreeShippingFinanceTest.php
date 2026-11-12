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
 * Delivery is free for every client and included in the displayed price
 * (App\Services\Pricing\PricingService). The agency still bills each parcel,
 * the platform keeps the parcel fee (client fee) and nothing is ever charged to
 * the seller for delivery. The old per-product "free delivery" flag changes
 * nothing. Admin values here: client fee 8, agency cost 7.
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

    public function test_normal_parcel_customer_pays_delivery_inside_the_price_and_seller_is_not_charged(): void
    {
        $seller   = $this->makeUser('seller');
        $customer = $this->makeUser('client');
        $res = $this->checkout($customer, [$this->makeProduct($seller, 100, false)]);

        // Shown as free: the 8.000 sits inside the displayed 108.000
        $this->assertEquals(0.0, $res['shipping_fee']);
        $this->assertEquals(100 + self::FEE, $res['total']);

        $order = DB::table('orders')->find($res['order_id']);
        $this->assertSame('customer', $order->shipping_paid_by);
        $this->assertSame('included', $order->pricing_model);
        $this->assertEquals(self::AGENCY, (float) $order->shipping_cost);

        $so = $this->sellerOrder($order->id, $seller->id);
        [$commission, $itemsNet, $base] = $this->itemsCommission($so->id);

        // Commission on the seller's price only, never on delivery
        $this->assertEquals(100.0, $base);
        $this->assertGreaterThan(0, $commission);
        $this->assertEqualsWithDelta(100 - $commission, $itemsNet, 0.001);

        $this->assertEquals(0.0, (float) $so->seller_shipping_charge);
        $this->assertEqualsWithDelta($itemsNet, (float) $so->seller_net_amount, 0.001);

        // Platform: commission + delivery margin (parcel fee 8 - Intigo 7)
        $this->assertEquals(self::FEE, (float) $so->delivery_fee);
        $this->assertEquals(self::AGENCY, (float) $so->shipping_cost);
        $this->assertEqualsWithDelta($commission + self::FEE - self::AGENCY, (float) $so->platform_profit, 0.001);
    }

    public function test_the_old_free_delivery_flag_charges_the_seller_nothing(): void
    {
        $seller   = $this->makeUser('seller');
        $customer = $this->makeUser('client');
        $res = $this->checkout($customer, [$this->makeProduct($seller, 100, true)]);

        $this->assertEquals(100 + self::FEE, $res['total']);
        $so = $this->sellerOrder($res['order_id'], $seller->id);
        $this->assertEquals(0.0, (float) $so->seller_shipping_charge);
        $this->assertEquals(self::FEE, (float) $so->delivery_fee);
        $this->assertEqualsWithDelta(self::FEE - self::AGENCY, (float) $so->platform_delivery_margin, 0.001);
        $this->assertSame('customer', DB::table('orders')->where('id', $res['order_id'])->value('shipping_paid_by'));
    }

    public function test_each_seller_has_its_own_parcel_with_its_own_fee(): void
    {
        $a = $this->makeUser('seller');
        $b = $this->makeUser('seller');
        $res = $this->checkout($this->makeUser('client'), [$this->makeProduct($a, 100, true), $this->makeProduct($b, 100, false)]);

        $this->assertEquals(216.0, $res['total']);   // 108 + 108
        foreach ([$a, $b] as $seller) {
            $so = $this->sellerOrder($res['order_id'], $seller->id);
            $this->assertEquals(self::FEE, (float) $so->delivery_fee);
            $this->assertEquals(0.0, (float) $so->seller_shipping_charge);
            $this->assertEquals(self::AGENCY, (float) $so->shipping_cost);
        }
    }

    public function test_admin_finance_accounts_for_each_parcel_once(): void
    {
        $seller   = $this->makeUser('seller');
        $customer = $this->makeUser('client');
        $admin    = $this->makeUser('admin');

        $first  = $this->checkout($customer, [$this->makeProduct($seller, 100, true)]);
        $second = $this->checkout($customer, [$this->makeProduct($seller, 100, false)]);

        $this->app['auth']->forgetGuards();
        $rows = collect($this->withHeaders(['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken])
            ->getJson("/api/admin/finance/orders?seller_id={$seller->id}")
            ->assertOk()
            ->json('data.data'))->keyBy('order_id');

        foreach ([$first['order_id'], $second['order_id']] as $orderId) {
            $r = $rows[$orderId];
            $this->assertEqualsWithDelta(self::AGENCY, (float) $r['shipping_cost'], 0.001);
            // margin = parcel fee collected - Intigo cost; per order: client paid, discount given
            $this->assertEqualsWithDelta((float) $r['delivery_fee'] - self::AGENCY, (float) $r['platform_delivery_margin'], 0.001);
            $this->assertEqualsWithDelta((float) $r['commission_amount'] + (float) $r['platform_delivery_margin'], (float) $r['platform_profit'], 0.001);
            $this->assertSame('included', $r['pricing_model']);
            $this->assertEqualsWithDelta(100 + self::FEE, (float) $r['client_paid'], 0.001);
            $this->assertEqualsWithDelta(0.0, (float) $r['multi_item_discount'], 0.001);
            $this->assertSame('customer', $r['shipping_paid_by']);
        }

        // Order detail: the seller's net is never reduced for delivery
        $summary = fn(int $id) => $this->getJson("/api/admin/orders/{$id}")->assertOk()->json('data.commission_summary');
        foreach ([$first['order_id'], $second['order_id']] as $orderId) {
            $s = $summary($orderId);
            $this->assertEqualsWithDelta(0, $s['seller_shipping'], 0.001);
            $this->assertEqualsWithDelta($s['total_seller'], $s['total_seller_net'], 0.001);
        }
    }
}
