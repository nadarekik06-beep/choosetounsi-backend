<?php

namespace Tests\Feature\Orders;

use App\Exceptions\InsufficientStock;
use App\Helpers\PlatformUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerApplication;
use App\Models\SellerOrder;
use App\Models\User;
use App\Services\Orders\OrderStock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stock reserved at checkout goes back exactly once when the order (or one
 * seller's part of it) is cancelled, and stays out when it is confirmed.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Orders/OrderStockTest.php
 */
class OrderStockTest extends TestCase
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
        config(['platform.shipping_cost' => 8.0]);
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
        return User::create([
            'name'      => ucfirst($role) . ' ' . Str::random(5),
            'email'     => $role . '_' . Str::random(10) . '@test.local',
            'password'  => bcrypt('secret-password'),
            'role'      => $role,
            'is_active' => true,
            'locale'    => 'fr',
        ]);
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

    private function stockOf($model): int
    {
        return (int) $model->fresh()->stock;
    }

    // ── Admin confirm / cancel after calling the client ──────────────────────

    public function test_admin_cancel_gives_the_reserved_units_back_to_the_right_variant(): void
    {
        $seller  = $this->makeSeller();
        $product = $this->makeProduct($seller, 7);
        $red     = $this->makeVariant($product, 5);
        $blue    = $this->makeVariant($product, 9);
        $simple  = $this->makeProduct($seller, 4);

        $order = $this->checkout([[$product, $red, 2], [$simple, null, 3]]);
        $this->assertSame(3, $this->stockOf($red));
        $this->assertSame(1, $this->stockOf($simple));

        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'cancelled', 'admin_note' => 'Client injoignable'])
            ->assertOk();

        $this->assertSame(5, $this->stockOf($red));
        $this->assertSame(9, $this->stockOf($blue), 'other variants are untouched');
        $this->assertSame(7, $this->stockOf($product), 'the parent product of a variant line is untouched');
        $this->assertSame(4, $this->stockOf($simple));
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_admin_confirm_keeps_the_stock_decreased(): void
    {
        $seller  = $this->makeSeller();
        $product = $this->makeProduct($seller, 10);
        $order   = $this->checkout([[$product, null, 3]]);

        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();

        $this->assertSame(7, $this->stockOf($product));
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_cancelling_twice_never_adds_stock_twice(): void
    {
        $seller  = $this->makeSeller();
        $product = $this->makeProduct($seller, 10);
        $order   = $this->checkout([[$product, null, 4]]);

        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'cancelled'])->assertOk();
        $this->assertSame(10, $this->stockOf($product));

        // Same button again, the generic status endpoint, and the seller: all no-ops for stock
        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'cancelled'])->assertStatus(422);
        $this->admin()->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'cancelled'])->assertOk();
        $so = SellerOrder::where('order_id', $order->id)->firstOrFail();
        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'cancelled'])->assertOk();
        app(OrderStock::class)->releaseForSellerOrders([$so->id]);

        $this->assertSame(10, $this->stockOf($product));
    }

    public function test_cancelling_a_confirmed_order_gives_the_units_back(): void
    {
        $seller  = $this->makeSeller();
        $product = $this->makeProduct($seller, 10);
        $order   = $this->checkout([[$product, null, 2]]);

        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();
        $this->admin()->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'cancelled'])->assertOk();

        $this->assertSame(10, $this->stockOf($product));
    }

    // ── Partial cancellation ─────────────────────────────────────────────────

    public function test_seller_cancelling_their_sub_order_only_restores_their_items(): void
    {
        $sellerA  = $this->makeSeller();
        $sellerB  = $this->makeSeller();
        $productA = $this->makeProduct($sellerA, 10);
        $productB = $this->makeProduct($sellerB, 10);
        $order    = $this->checkout([[$productA, null, 2], [$productB, null, 3]]);

        $soA = SellerOrder::where('order_id', $order->id)->where('seller_id', $sellerA->id)->firstOrFail();
        $this->as($sellerA)->patchJson("/api/seller/orders/{$soA->id}/status", ['status' => 'cancelled'])->assertOk();

        $this->assertSame(10, $this->stockOf($productA));
        $this->assertSame(7, $this->stockOf($productB));
    }

    // ── Re-opening a cancelled sub-order ─────────────────────────────────────

    public function test_reopening_a_cancelled_order_reserves_the_units_again(): void
    {
        $seller  = $this->makeSeller();
        $product = $this->makeProduct($seller, 10);
        $order   = $this->checkout([[$product, null, 4]]);
        $so      = SellerOrder::where('order_id', $order->id)->firstOrFail();

        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(10, $this->stockOf($product));

        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->assertSame(6, $this->stockOf($product));

        // …and cancelling once more gives them back once more
        $this->admin()->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(10, $this->stockOf($product));
    }

    public function test_reopening_is_refused_when_the_units_were_sold_meanwhile(): void
    {
        $seller  = $this->makeSeller();
        $product = $this->makeProduct($seller, 5);
        $order   = $this->checkout([[$product, null, 4]]);

        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'cancelled'])->assertOk();
        $product->update(['stock' => 2]);

        $this->admin()->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(422);

        $this->assertSame(2, $this->stockOf($product));
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(['cancelled'], SellerOrder::where('order_id', $order->id)->pluck('status')->unique()->values()->all());
    }

    // ── Other cancel paths ───────────────────────────────────────────────────

    public function test_abandoned_card_order_restores_stock_once(): void
    {
        $seller  = $this->makeSeller();
        $product = $this->makeProduct($seller, 10);
        $red     = $this->makeVariant($this->makeProduct($seller, 0), 6);
        $order   = $this->checkout([[$product, null, 3], [$red->product, $red, 2]], 'card');
        $order->forceFill(['created_at' => now()->subHour()])->save();

        $this->artisan('orders:cancel-abandoned-card')->assertExitCode(0);
        $this->artisan('orders:cancel-abandoned-card')->assertExitCode(0);

        $this->assertSame(10, $this->stockOf($product));
        $this->assertSame(6, $this->stockOf($red));
    }

    public function test_returned_items_are_not_restored_again_when_the_sub_order_is_cancelled(): void
    {
        $seller  = $this->makeSeller();
        $kept    = $this->makeProduct($seller, 10);
        $back    = $this->makeProduct($seller, 10);
        $order   = $this->checkout([[$kept, null, 2], [$back, null, 3]]);
        $so      = SellerOrder::where('order_id', $order->id)->firstOrFail();
        $backLine = $so->items()->where('product_id', $back->id)->value('id');

        // Refund pickup (MarkOrderRefunded) returned one line
        app(OrderStock::class)->releaseForOrderItems([$backLine]);
        app(OrderStock::class)->releaseForOrderItems([$backLine]);
        $this->assertSame(10, $this->stockOf($back));

        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(10, $this->stockOf($back));
        $this->assertSame(10, $this->stockOf($kept));

        // Re-opening takes back only what the cancel released, not the returned line
        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->assertSame(8, $this->stockOf($kept));
        $this->assertSame(10, $this->stockOf($back));
    }

    // ── Checkout never oversells ─────────────────────────────────────────────

    public function test_reserve_refuses_more_than_what_is_left(): void
    {
        $product = $this->makeProduct($this->makeSeller(), 2);

        try {
            app(OrderStock::class)->reserve(null, $product->id, 3, 'X');
            $this->fail('expected InsufficientStock');
        } catch (InsufficientStock $e) {
            $this->assertSame(2, $e->available);
        }
        $this->assertSame(2, $this->stockOf($product));
    }
}
