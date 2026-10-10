<?php

namespace Tests\Feature\Orders;

use App\Helpers\PlatformUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerApplication;
use App\Models\SellerOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A cancelled order owes nothing: amount due 0 and no shipping, in every
 * payload (storefront, seller, admin), while the checkout amounts stay stored
 * as history. A partly cancelled order keeps shipping once for what is left.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Orders/CancelledOrderMoneyTest.php
 */
class CancelledOrderMoneyTest extends TestCase
{
    use DatabaseTransactions;

    private const ADDRESS = [
        'recipient_name' => 'Sami Ben Salah',
        'phone'          => '22 123 456',
        'wilaya'         => 'Ben Arous',
        'delegation'     => 'El Mourouj',
        'address'        => '12 rue de la Liberté',
        'postal_code'    => '2074',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Online methods are "Coming soon" by default; these flows exercise them
        \App\Models\PlatformSetting::flushCache();
        app(\App\Services\Payments\CheckoutPaymentMethods::class)->set(['card' => true, 'd17' => true, 'wallet' => true], null);
        Notification::fake();

        if (!DB::table('users')->where('id', 1)->exists()) {
            DB::table('users')->insert([
                'id' => 1, 'name' => "CHOOSE'Tounsi", 'email' => 'platform-' . Str::random(6) . '@test.local',
                'password' => bcrypt('x'), 'role' => 'seller', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        PlatformUser::reset();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $role): User
    {
        return $this->withCompleteProfile(User::create([
            'name'      => ucfirst($role) . ' ' . Str::random(5),
            'email'     => $role . '_' . Str::random(10) . '@test.local',
            'password'  => bcrypt('secret-password'),
            'role'      => $role,
            'is_active' => true,
            'locale'    => 'fr',
        ]));
    }

    private function makeSeller(): User
    {
        $seller = $this->makeUser('seller');
        SellerApplication::create([
            'user_id'              => $seller->id,
            'full_name'            => 'Mohamed Trabelsi',
            'phone_number'         => '55111222',
            'business_name'        => 'Atelier ' . Str::random(4),
            'business_category'    => 'crafts',
            'business_description' => 'Test shop',
            'wilaya'               => 'Sfax',
            'city'                 => 'Sakiet Ezzit',
            'pickup_address'       => 'Route de Tunis km 5',
            'pickup_postal_code'   => '3021',
            'status'               => 'approved',
        ]);
        return $seller;
    }

    private function makeProduct(User $seller, int $stock = 10): Product
    {
        $name = 'Stock Product ' . Str::random(6);
        return Product::create([
            'seller_id'   => $seller->id,
            'name'        => $name,
            'slug'        => Str::slug($name),
            'price'       => 30,
            'stock'       => $stock,
            'is_approved' => true,
            'is_active'   => true,
        ]);
    }

    private function makeVariant(Product $product, int $stock): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'SKU-' . Str::random(8),
            'stock'      => $stock,
            'is_active'  => true,
        ]);
    }

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken]);
    }

    private function admin(): self
    {
        return $this->as($this->makeUser('admin'));
    }

    /** @param array<array{0: Product, 1: ?ProductVariant, 2: int}> $lines */
    private function checkout(array $lines, string $method = 'cod'): Order
    {
        $customer = $this->makeUser('client');
        foreach ($lines as [$product, $variant, $qty]) {
            Cart::create(['user_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => $variant?->id, 'quantity' => $qty]);
        }
        $res = $this->as($customer)
            ->postJson('/api/checkout', self::ADDRESS + ['payment_method' => $method])
            ->assertCreated();

        return Order::findOrFail($res->json('order_id'));
    }

    public function test_admin_cancel_makes_the_amount_due_zero_everywhere_and_keeps_history(): void
    {
        $seller  = $this->makeSeller();
        $product = $this->makeProduct($seller);
        $order   = $this->checkout([[$product, null, 2]]);
        $stored  = $order->only(['subtotal', 'shipping_fee', 'total_amount']);
        $this->assertGreaterThan(0, (float) $order->shipping_fee);

        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'cancelled'])->assertOk();

        // History untouched
        $this->assertEquals($stored, $order->fresh()->only(['subtotal', 'shipping_fee', 'total_amount']));

        // Storefront
        $data = $this->as($order->user)->getJson("/api/client/orders/{$order->id}")->assertOk()->json('data');
        $this->assertEquals(0, $data['total_amount']);
        $this->assertEquals(0, $data['shipping_fee']);
        $this->assertEquals(0, $data['amount_due']);
        $this->assertTrue($data['is_cancelled']);
        $this->assertEquals((float) $stored['total_amount'], $data['original_amounts']['total']);

        $recent = collect($this->as($order->user)->getJson('/api/profile/overview')->assertOk()->json('data.recent_orders'))->firstWhere('id', $order->id);
        $this->assertEquals(0, $recent['total_amount']);
        $this->assertTrue($recent['is_cancelled']);

        // Admin
        $row = collect($this->admin()->getJson('/api/admin/orders?search=' . $order->order_number)->assertOk()->json('data.data'))->firstWhere('id', $order->id);
        $this->assertEquals(0, $row['total_amount']);
        $this->assertTrue($row['is_cancelled']);
        $detail = $this->admin()->getJson("/api/admin/orders/{$order->id}")->assertOk()->json('data');
        $this->assertEquals(0, $detail['total_amount']);
        $this->assertEquals(0, $detail['commission_summary']['total_commission']);

        // Seller: 0 due / earned, payout cancelled
        $so = SellerOrder::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('cancelled', $so->payout_status);
        $list = collect($this->as($seller)->getJson('/api/seller/orders')->assertOk()->json('data.data'))->firstWhere('id', $so->id);
        $this->assertEquals(0, $list['total_amount']);
        $this->assertGreaterThan(0, $list['original_total']);
        $show = $this->as($seller)->getJson("/api/seller/orders/{$so->id}")->assertOk()->json('data');
        $this->assertEquals(0, $show['seller_total']);
        $this->assertEquals(0, $show['commission']['total_commission_amount'] ?? 0);
        $earn = collect($this->as($seller)->getJson('/api/seller/earnings/orders')->assertOk()->json('data.data'))->firstWhere('id', $so->id);
        $this->assertEquals(0, $earn['net_earnings']);
        $this->assertEquals(0, $earn['commission_amount']);
    }

    public function test_partial_cancel_drops_the_cancelled_parcels_delivery_fee(): void
    {
        $sellerA = $this->makeSeller();
        $sellerB = $this->makeSeller();
        $order   = $this->checkout([[$this->makeProduct($sellerA), null, 1], [$this->makeProduct($sellerB), null, 2]]);
        $soA     = SellerOrder::where('order_id', $order->id)->where('seller_id', $sellerA->id)->firstOrFail();
        $soB     = SellerOrder::where('order_id', $order->id)->where('seller_id', $sellerB->id)->firstOrFail();

        $this->as($sellerA)->patchJson("/api/seller/orders/{$soA->id}/status", ['status' => 'cancelled'])->assertOk();

        $money = $order->fresh()->moneySummary();
        $this->assertFalse($money['is_cancelled']);
        $this->assertEquals((float) $soB->subtotal, $money['subtotal']);
        // Delivery is per parcel: only B's own fee is still owed
        $this->assertEquals((float) $soB->getAttribute('delivery_fee'), $money['shipping_fee']);
        $this->assertEquals(round((float) $soB->subtotal - (float) $soB->discount_amount + (float) $soB->getAttribute('delivery_fee'), 3), $money['total']);
        $this->assertSame('cancelled', $soA->fresh()->payout_status);
        $this->assertSame('pending', $soB->fresh()->payout_status);

        // B cancelled too → the whole order owes nothing, shipping included
        $this->as($sellerB)->patchJson("/api/seller/orders/{$soB->id}/status", ['status' => 'cancelled'])->assertOk();
        $money = $order->fresh()->moneySummary();
        $this->assertTrue($money['is_cancelled']);
        $this->assertEquals(0, $money['total']);
        $this->assertEquals(0, $money['shipping_fee']);
    }

    public function test_reopening_a_cancelled_order_brings_the_money_and_payout_back(): void
    {
        $seller = $this->makeSeller();
        $order  = $this->checkout([[$this->makeProduct($seller), null, 1]]);

        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'cancelled'])->assertOk();
        $this->admin()->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();

        $this->assertSame('pending', SellerOrder::where('order_id', $order->id)->value('payout_status'));
        $this->assertEquals(round((float) $order->total_amount, 3), $order->fresh()->moneySummary()['total']);
    }
}
