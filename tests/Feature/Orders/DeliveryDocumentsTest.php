<?php

namespace Tests\Feature\Orders;

use App\Helpers\PlatformUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Models\User;
use App\Services\Orders\DeliveryDocumentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shipping address snapshot at checkout, seller pickup points, and the
 * admin-only delivery documents (slips per seller sub-order + internal summary).
 *
 * Run only this file:  php -d extension=gd vendor/bin/phpunit tests/Feature/Orders
 */
class DeliveryDocumentsTest extends TestCase
{
    use DatabaseTransactions;

    private const ADDRESS = [
        'recipient_name'  => 'سارة بن علي',          // Arabic names must survive end to end
        'phone'           => '+216 22 123 456',     // normalized to 22123456
        'phone_secondary' => '98 765 432',
        'wilaya'          => 'ben arous',           // normalized to "Ben Arous"
        'delegation'      => 'El Mourouj',
        'address'         => '12 rue de la Liberté, Résidence Yasmine',
        'postal_code'     => '2074',
        'notes'           => 'En face de la pharmacie',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.shipping_cost' => 8.0]);

        // config/platform.php names user #1 as the platform seller: make sure
        // that id is never one of the test sellers created below.
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
        return User::create([
            'name'      => ucfirst($role) . ' ' . Str::random(5),
            'email'     => $role . '_' . Str::random(10) . '@test.local',
            'password'  => bcrypt('secret-password'),
            'role'      => $role,
            'is_active' => true,
        ]);
    }

    private function makeSeller(bool $completePickup = true, string $shop = 'Atelier Test'): User
    {
        $seller = $this->makeUser('seller');
        SellerApplication::create([
            'user_id'              => $seller->id,
            'full_name'            => 'Mohamed Trabelsi',
            'phone_number'         => '55111222',
            'business_name'        => $shop,
            'business_category'    => 'crafts',
            'business_description' => 'Test shop',
            'wilaya'               => 'Sfax',
            'city'                 => 'Sakiet Ezzit',
            'pickup_address'       => $completePickup ? 'Route de Tunis km 5' : null,
            'pickup_postal_code'   => $completePickup ? '3021' : null,
            'status'               => 'approved',
        ]);
        return $seller;
    }

    private function makeProduct(User $seller, float $price): Product
    {
        $name = 'Doc Product ' . Str::random(6);
        return Product::create([
            'seller_id'   => $seller->id,
            'name'        => $name,
            'slug'        => Str::slug($name),
            'price'       => $price,
            'stock'       => 50,
            'is_approved' => true,
            'is_active'   => true,
        ]);
    }

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken]);
    }

    /** Checkout through the real endpoint; returns the created Order. */
    private function checkout(User $customer, array $products, array $overrides = []): Order
    {
        foreach ($products as $product) {
            Cart::create(['user_id' => $customer->id, 'product_id' => $product->id, 'quantity' => 1]);
        }
        $res = $this->as($customer)
            ->postJson('/api/checkout', array_merge(self::ADDRESS, ['payment_method' => 'cod'], $overrides))
            ->assertCreated();

        return Order::findOrFail($res->json('order_id'));
    }

    private function slips(Order $order): array
    {
        $service = app(DeliveryDocumentService::class);
        return $service->slipsFor($service->query()->findOrFail($order->id));
    }

    // ── Checkout snapshot ────────────────────────────────────────────────────

    public function test_checkout_saves_a_normalized_address_snapshot(): void
    {
        $customer = $this->makeUser('client');
        $order    = $this->checkout($customer, [$this->makeProduct($this->makeSeller(), 50)]);

        $this->assertSame('سارة بن علي', $order->recipient_name);
        $this->assertSame('22123456', $order->phone);
        $this->assertSame('98765432', $order->phone_secondary);
        $this->assertSame('Ben Arous', $order->wilaya);
        $this->assertSame('El Mourouj', $order->delegation);
        $this->assertSame('2074', $order->postal_code);
        $this->assertSame('En face de la pharmacie', $order->notes);
        $this->assertTrue($order->hasStructuredAddress());

        // Editing the address book later never rewrites the order.
        $saved = $this->as($customer)->postJson('/api/addresses', array_merge(self::ADDRESS, ['label' => 'Home']))->assertCreated();
        $this->as($customer)->putJson('/api/addresses/' . $saved->json('data.id'), array_merge(self::ADDRESS, [
            'address' => '99 avenue Habib Bourguiba', 'postal_code' => '1000', 'wilaya' => 'Tunis',
        ]))->assertOk();

        $order->refresh();
        $this->assertSame('12 rue de la Liberté, Résidence Yasmine', $order->address);
        $this->assertSame('2074', $order->postal_code);
    }

    public function test_checkout_rejects_invalid_tunisian_addresses(): void
    {
        $customer = $this->makeUser('client');
        $product  = $this->makeProduct($this->makeSeller(), 50);
        Cart::create(['user_id' => $customer->id, 'product_id' => $product->id, 'quantity' => 1]);

        foreach ([
            'wilaya'          => 'Paris',
            'postal_code'     => '123',
            'phone'           => '71123456',   // landline / not a mobile
            'recipient_name'  => '',
            'delegation'      => '',
            'phone_secondary' => '22123456',   // same as phone
        ] as $field => $bad) {
            $this->as($customer)
                ->postJson('/api/checkout', array_merge(self::ADDRESS, [$field => $bad]))
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        $this->assertSame(0, Order::where('user_id', $customer->id)->count());
    }

    // ── Admin view ───────────────────────────────────────────────────────────

    public function test_admin_sees_the_shipping_address_and_pickup_points(): void
    {
        $complete   = $this->makeSeller(true, 'Atelier Complet');
        $incomplete = $this->makeSeller(false, 'Boutique Incomplète');
        $order      = $this->checkout($this->makeUser('client'), [$this->makeProduct($complete, 40), $this->makeProduct($incomplete, 60)]);

        $res = $this->as($this->makeUser('admin'))->getJson('/api/admin/orders/' . $order->id)->assertOk();

        $res->assertJsonPath('data.shipping_address.status', 'complete')
            ->assertJsonPath('data.shipping_address.recipient_name', 'سارة بن علي')
            ->assertJsonPath('data.shipping_address.postal_code', '2074')
            ->assertJsonPath('data.export_readiness.ready', false);

        $pickups = collect($res->json('data.seller_orders'))->keyBy(fn($so) => $so['pickup']['shop_name']);
        $this->assertTrue($pickups['Atelier Complet']['pickup']['complete']);
        $this->assertSame('Route de Tunis km 5, Sakiet Ezzit, 3021 Sfax', $pickups['Atelier Complet']['pickup']['formatted']);
        $this->assertFalse($pickups['Boutique Incomplète']['pickup']['complete']);
        $this->assertContains('postal code', $pickups['Boutique Incomplète']['pickup']['missing']);

        // The admin fixes the pickup address from the drawer → order becomes exportable.
        $this->as($this->makeUser('admin'))->putJson("/api/admin/sellers/{$incomplete->id}/pickup-address", [
            'full_name' => 'Leila Gharbi', 'phone_number' => '29 000 111', 'pickup_address' => 'Rue Ibn Khaldoun 4',
            'city' => 'Menzah 6', 'pickup_postal_code' => '2091', 'wilaya' => 'Ariana',
        ])->assertOk()->assertJsonPath('data.complete', true);

        $this->as($this->makeUser('admin'))->getJson('/api/admin/orders/' . $order->id)
            ->assertJsonPath('data.export_readiness.ready', true);
    }

    // ── COD maths ────────────────────────────────────────────────────────────

    public function test_cod_amount_per_sub_order_adds_up_to_the_order_total(): void
    {
        $a = $this->makeSeller(true, 'Seller A');
        $b = $this->makeSeller(true, 'Seller B');
        $order = $this->checkout($this->makeUser('client'), [$this->makeProduct($a, 100), $this->makeProduct($b, 45.5)]);

        $slips = $this->slips($order);
        $this->assertCount(2, $slips);

        $shipping = (float) $order->shipping_fee;
        $this->assertGreaterThan(0, $shipping);

        // Shipping is collected once, on the first sub-order only.
        $this->assertEquals($shipping, $slips[0]['money']['shipping']);
        $this->assertEquals(0.0, $slips[1]['money']['shipping']);
        $this->assertEqualsWithDelta(100 + $shipping, $slips[0]['money']['cod'], 0.0005);
        $this->assertEqualsWithDelta(45.5, $slips[1]['money']['cod'], 0.0005);

        $this->assertEqualsWithDelta((float) $order->fresh()->moneySummary()['total'], array_sum(array_map(fn($s) => $s['money']['cod'], $slips)), 0.0005);

        // First sub-order cancelled → shipping moves to the next one, nothing is lost.
        DB::table('seller_orders')->where('id', DB::table('seller_orders')->where('order_id', $order->id)->min('id'))->update(['status' => 'cancelled']);
        $slips = $this->slips($order);
        $this->assertCount(1, $slips);
        $this->assertEqualsWithDelta(45.5 + $shipping, $slips[0]['money']['cod'], 0.0005);
    }

    public function test_prepaid_orders_collect_nothing(): void
    {
        $customer = $this->makeUser('client');
        $customer->forceFill(['wallet_balance' => 1000])->save();
        $order = $this->checkout($customer, [$this->makeProduct($this->makeSeller(true, 'A'), 30), $this->makeProduct($this->makeSeller(true, 'B'), 20)], ['payment_method' => 'wallet']);

        foreach ($this->slips($order) as $slip) {
            $this->assertTrue($slip['prepaid']);
            $this->assertEquals(0.0, $slip['money']['cod']);
            $this->assertGreaterThan(0, $slip['money']['total']);
        }
    }

    // ── Exports ──────────────────────────────────────────────────────────────

    public function test_admin_downloads_slips_and_summary_and_exports_are_logged(): void
    {
        $seller = $this->makeSeller(true, 'Dar El Fann');
        $order  = $this->checkout($this->makeUser('client'), [$this->makeProduct($seller, 75)]);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'confirmed']);
        $admin    = $this->makeUser('admin');
        $soId     = DB::table('seller_orders')->where('order_id', $order->id)->value('id');
        $base     = Str::startsWith($order->order_number, 'CT-') ? $order->order_number : 'CT-' . $order->order_number;

        // Not exported yet → appears in the "needs slips" queue.
        $queue = $this->as($admin)->getJson('/api/admin/orders?needs_slips=1&per_page=100')->assertOk();
        $this->assertContains($order->id, array_column($queue->json('data.data'), 'id'));

        $res = $this->as($admin)->get("/api/admin/orders/{$order->id}/export/slips/{$soId}")->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString($base . '-seller-dar-el-fann.pdf', $res->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $res->streamedContent());

        $this->as($admin)->get("/api/admin/orders/{$order->id}/export/slips")->assertOk();
        $summary = $this->as($admin)->get("/api/admin/orders/{$order->id}/export/summary")->assertOk();
        $this->assertStringContainsString('INTERNAL', $summary->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $summary->streamedContent());

        $bulk = $this->as($admin)->post('/api/admin/orders/export/slips', ['order_ids' => [$order->id]])->assertOk();
        $this->assertStringStartsWith('%PDF', $bulk->streamedContent());

        $this->assertSame(['bulk', 'slip', 'slips', 'summary'], DB::table('order_exports')->where('order_id', $order->id)->orderBy('type')->pluck('type')->all());
        $this->assertSame($admin->id, (int) DB::table('order_exports')->where('order_id', $order->id)->value('exported_by'));

        // Exported → leaves the queue, and the drawer shows the history.
        $queue = $this->as($admin)->getJson('/api/admin/orders?needs_slips=1&per_page=100');
        $this->assertNotContains($order->id, array_column($queue->json('data.data'), 'id'));
        $this->as($admin)->getJson('/api/admin/orders/' . $order->id)
            ->assertJsonCount(4, 'data.export_history')
            ->assertJsonPath('data.export_history.0.exported_by', $admin->name);
    }

    public function test_non_admins_cannot_export_or_read_addresses(): void
    {
        $seller = $this->makeSeller();
        $order  = $this->checkout($this->makeUser('client'), [$this->makeProduct($seller, 20)]);
        $soId   = DB::table('seller_orders')->where('order_id', $order->id)->value('id');

        foreach ([$this->makeUser('client'), $seller] as $user) {
            $this->as($user)->getJson("/api/admin/orders/{$order->id}/export/slips/{$soId}")->assertForbidden();
            $this->as($user)->getJson("/api/admin/orders/{$order->id}/export/slips")->assertForbidden();
            $this->as($user)->getJson("/api/admin/orders/{$order->id}/export/summary")->assertForbidden();
            $this->as($user)->postJson('/api/admin/orders/export/slips', ['order_ids' => [$order->id]])->assertForbidden();
            $this->as($user)->getJson("/api/admin/orders/{$order->id}")->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson("/api/admin/orders/{$order->id}/export/slips")->assertUnauthorized();
        $this->assertSame(0, DB::table('order_exports')->where('order_id', $order->id)->count());
    }

    // ── Legacy orders ────────────────────────────────────────────────────────

    public function test_legacy_order_without_address_is_flagged_and_not_exportable(): void
    {
        $customer = $this->makeUser('client');
        $seller   = $this->makeSeller();
        $orderId  = DB::table('orders')->insertGetId([
            'user_id' => $customer->id, 'order_number' => 'ORD-LEGACY' . Str::upper(Str::random(4)),
            'status' => 'confirmed', 'payment_status' => 'unpaid', 'payment_method' => 'cod',
            'total_amount' => 58, 'shipping_fee' => 8, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('seller_orders')->insert([
            'order_id' => $orderId, 'seller_id' => $seller->id, 'status' => 'confirmed', 'payment_status' => 'unpaid',
            'subtotal' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = $this->makeUser('admin');

        $this->as($admin)->getJson('/api/admin/orders/' . $orderId)->assertOk()
            ->assertJsonPath('data.shipping_address.status', 'missing')
            ->assertJsonPath('data.shipping_address.recipient_name', $customer->name)
            ->assertJsonPath('data.export_readiness.ready', false);

        $this->as($admin)->getJson("/api/admin/orders/{$orderId}/export/slips")
            ->assertStatus(422)
            ->assertJsonPath('issues.0', 'Shipping address not recorded (legacy order) — call the customer and add it before shipping.');

        // The internal summary still works — it's the admin's record.
        $this->as($admin)->get("/api/admin/orders/{$orderId}/export/summary")->assertOk();

        // Legacy order WITH the old free-text address: shown as legacy, still shippable.
        DB::table('orders')->where('id', $orderId)->update(['wilaya' => 'Tunis', 'address' => 'Lafayette, rue de Palestine', 'phone' => '22333444']);
        $this->as($admin)->getJson('/api/admin/orders/' . $orderId)
            ->assertJsonPath('data.shipping_address.status', 'legacy')
            ->assertJsonPath('data.export_readiness.ready', true);
        $this->as($admin)->get("/api/admin/orders/{$orderId}/export/slips")->assertOk();
    }

    // ── Seller self-service ──────────────────────────────────────────────────

    public function test_seller_completes_their_pickup_address_without_re_review(): void
    {
        $seller = $this->makeSeller(false);

        $this->as($seller)->getJson('/api/seller/pickup-address')->assertOk()->assertJsonPath('data.complete', false);

        $this->as($seller)->putJson('/api/seller/pickup-address', [
            'full_name' => 'Mohamed Trabelsi', 'phone_number' => '55111222', 'pickup_address' => 'Zone industrielle, lot 12',
            'city' => 'Sakiet Ezzit', 'pickup_postal_code' => '30', 'wilaya' => 'Sfax',
        ])->assertStatus(422)->assertJsonValidationErrors('pickup_postal_code');

        $this->as($seller)->putJson('/api/seller/pickup-address', [
            'full_name' => 'Mohamed Trabelsi', 'phone_number' => '55111222', 'pickup_address' => 'Zone industrielle, lot 12',
            'city' => 'Sakiet Ezzit', 'pickup_postal_code' => '3021', 'wilaya' => 'sfax',
        ])->assertOk()->assertJsonPath('data.complete', true)->assertJsonPath('data.wilaya', 'Sfax');

        $this->assertSame('approved', SellerApplication::where('user_id', $seller->id)->value('status'));
    }
}
