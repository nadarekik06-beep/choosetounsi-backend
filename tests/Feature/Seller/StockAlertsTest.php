<?php

namespace Tests\Feature\Seller;

use App\Helpers\PlatformUser;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerApplication;
use App\Models\User;
use App\Notifications\LowStockNotification;
use App\Notifications\OutOfStockNotification;
use App\Services\Orders\OrderStock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Low-stock alerts: per variant, once per crossing, only from sales, grouped,
 * shop settings + per-product override; per-variant stock in the product views.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Seller/StockAlertsTest.php
 */
class StockAlertsTest extends TestCase
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

    private User $seller;
    private array $opt = [];
    private string $r;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.shipping_cost' => 8.0, 'stock.alert_group_window_minutes' => 10]);
        Notification::fake();

        if (!DB::table('users')->where('id', 1)->exists()) {
            DB::table('users')->insert([
                'id' => 1, 'name' => "CHOOSE'Tounsi", 'email' => 'platform-' . Str::random(6) . '@test.local',
                'password' => bcrypt('x'), 'role' => 'seller', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        PlatformUser::reset();

        $this->r      = Str::random(5);
        $this->seller = $this->makeUser('seller');
        SellerApplication::create([
            'user_id' => $this->seller->id, 'full_name' => 'Mohamed Trabelsi', 'phone_number' => '55111222',
            'business_name' => 'Atelier ' . $this->r, 'business_category' => 'crafts', 'business_description' => 'Test shop',
            'wilaya' => 'Sfax', 'city' => 'Sakiet Ezzit', 'pickup_address' => 'Route de Tunis km 5',
            'pickup_postal_code' => '3021', 'status' => 'approved',
        ]);

        $color = Attribute::firstOrCreate(['slug' => 'color'], ['name' => 'Color', 'name_ar' => 'Color', 'name_fr' => 'Couleur', 'type' => 'color']);
        foreach (['Rouge' => '#ff0000', 'Noir' => '#000000', 'Gris' => '#888888'] as $name => $hex) {
            $this->opt[$name] = AttributeOption::create(['attribute_id' => $color->id, 'value' => "{$name}{$this->r}", 'color_hex' => $hex])->id;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $role): User
    {
        return $this->withCompleteProfile(User::create([
            'name' => ucfirst($role) . ' ' . Str::random(5), 'email' => $role . '_' . Str::random(10) . '@test.local',
            'password' => bcrypt('secret-password'), 'role' => $role, 'is_active' => true, 'locale' => 'fr',
        ]));
    }

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken]);
    }

    /** Scarf in Rouge 5 / Noir 4 / Gris 4, products.stock left stale at 15 like the bug report. */
    private function scarf(): array
    {
        $name = 'Scarf ' . Str::random(6);
        $p = Product::create([
            'seller_id' => $this->seller->id, 'name' => $name, 'slug' => Str::slug($name),
            'price' => 25, 'stock' => 15, 'is_approved' => true, 'is_active' => true,
        ]);
        $v = [];
        foreach (['Rouge' => 5, 'Noir' => 4, 'Gris' => 4] as $color => $stock) {
            $v[$color] = ProductVariant::create(['product_id' => $p->id, 'stock' => $stock, 'is_active' => true]);
            $v[$color]->attributeOptions()->sync([$this->opt[$color]]);
        }
        return [$p, $v];
    }

    private function mug(int $stock): Product
    {
        $name = 'Mug ' . Str::random(6);
        return Product::create([
            'seller_id' => $this->seller->id, 'name' => $name, 'slug' => Str::slug($name),
            'price' => 12, 'stock' => $stock, 'is_approved' => true, 'is_active' => true,
        ]);
    }

    /** @param array<array{0: Product, 1: ?ProductVariant, 2: int}> $lines */
    private function buy(array $lines): int
    {
        $buyer = $this->makeUser('client');
        foreach ($lines as [$product, $variant, $qty]) {
            Cart::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant?->id, 'quantity' => $qty]);
        }
        return (int) $this->as($buyer)->postJson('/api/checkout', self::ADDRESS + ['payment_method' => 'cod'])
            ->assertCreated()->json('order_id');
    }

    private function sent(string $class): \Illuminate\Support\Collection
    {
        return Notification::sent($this->seller, $class);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_product_total_comes_from_active_variants(): void
    {
        [$p, $v] = $this->scarf();
        $this->assertSame(13, (int) $p->fresh()->stock);   // the stale 15 is fixed as soon as variants are saved

        $v['Gris']->update(['is_active' => false]);
        $this->assertSame(9, (int) $p->fresh()->stock);

        $data = $this->as($this->seller)->getJson("/api/seller/products/{$p->id}")->assertOk()->json('data');
        $this->assertSame(9, $data['stock']);
        $this->assertSame(9, $data['stock_breakdown']['total']);
        $this->assertSame(2, $data['stock_breakdown']['threshold']);
        $rows = collect($data['stock_breakdown']['variants'])->keyBy('id');
        $this->assertSame('inactive', $rows[$v['Gris']->id]['state']);
        $this->assertSame('#000000', $rows[$v['Noir']->id]['options'][0]['color_hex']);

        $this->buy([[$p, $v['Noir'], 1]]);
        $this->assertSame(8, (int) $p->fresh()->stock);
    }

    public function test_alerts_once_per_crossing_per_variant_and_rearms_on_restock(): void
    {
        [$p, $v] = $this->scarf();

        $this->buy([[$p, $v['Noir'], 1]]);   // 4 → 3: above the threshold (2)
        $this->assertCount(0, $this->sent(LowStockNotification::class));

        $this->buy([[$p, $v['Noir'], 2]]);   // 3 → 1: crossing
        $sent = $this->sent(LowStockNotification::class);
        $this->assertCount(1, $sent);
        $this->assertSame([$v['Noir']->id], array_column($sent->first()->items, 'variant_id'));
        $db = $sent->first()->toDatabase($this->seller);
        $this->assertStringContainsString("Noir{$this->r}", $db['title']);
        $this->assertStringContainsString('1', $db['body']);

        $this->buy([[$p, $v['Rouge'], 1]]);  // other variant, 5 → 4: nothing
        $this->assertCount(1, $this->sent(LowStockNotification::class));

        $this->buy([[$p, $v['Noir'], 1]]);   // 1 → 0: out of stock, separate notification
        $this->assertCount(1, $this->sent(LowStockNotification::class));
        $this->assertCount(1, $this->sent(OutOfStockNotification::class));

        // Restock above the threshold re-arms; the next sale crossing alerts again
        $v['Noir']->update(['stock' => 3]);
        $this->assertCount(1, $this->sent(LowStockNotification::class));
        $this->buy([[$p, $v['Noir'], 1]]);
        $this->assertCount(2, $this->sent(LowStockNotification::class));
    }

    public function test_no_alert_for_items_created_or_edited_low(): void
    {
        $mug = $this->mug(2);                 // created at the threshold
        $this->buy([[$mug, null, 1]]);
        $this->assertCount(0, $this->sent(LowStockNotification::class));

        $other = $this->mug(10);
        $other->update(['stock' => 1]);       // seller edit down: silent
        $this->assertCount(0, $this->sent(LowStockNotification::class));

        $this->buy([[$mug, null, 1]]);        // a sale to 0 always alerts
        $this->assertCount(1, $this->sent(OutOfStockNotification::class));
    }

    public function test_one_grouped_notification_per_order(): void
    {
        [$p, $v] = $this->scarf();
        $mug = $this->mug(3);

        $this->buy([[$p, $v['Noir'], 2], [$p, $v['Gris'], 3], [$mug, null, 2]]);

        $sent = $this->sent(LowStockNotification::class);
        $this->assertCount(1, $sent);
        $this->assertCount(3, $sent->first()->items);
        $db = $sent->first()->toDatabase($this->seller);
        $this->assertStringContainsString('3', $db['title']);
        $this->assertSame('/seller/products', $db['link']);
    }

    public function test_crossings_within_the_window_wait_for_one_flush(): void
    {
        [$p, $v] = $this->scarf();
        DB::table('stock_alert_events')->insert([
            'seller_id' => $this->seller->id, 'product_id' => $p->id, 'variant_id' => $v['Rouge']->id,
            'kind' => 'low', 'stock' => 2, 'threshold' => 2,
            'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2),
        ]);

        $this->buy([[$p, $v['Noir'], 3]]);   // a flush is already scheduled: nothing sent now
        $this->assertCount(0, $this->sent(LowStockNotification::class));

        app(\App\Services\StockAlertService::class)->flush($this->seller->id);
        $sent = $this->sent(LowStockNotification::class);
        $this->assertCount(1, $sent);
        $this->assertCount(2, $sent->first()->items);
    }

    public function test_shop_settings_and_product_override(): void
    {
        $this->as($this->seller)->getJson('/api/seller/stock-alerts')->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.threshold', 2)->assertJsonPath('data.channel', 'in_app');

        $mug = $this->mug(6);
        $this->as($this->seller)->putJson('/api/seller/stock-alerts', ['enabled' => true, 'threshold' => 5, 'channel' => 'in_app_email'])
            ->assertOk()->assertJsonPath('data.threshold', 5);

        $this->buy([[$mug, null, 1]]);       // 6 → 5 crosses the new shop threshold
        $sent = $this->sent(LowStockNotification::class);
        $this->assertCount(1, $sent);
        $this->assertSame(['database', 'mail'], $sent->first()->via($this->seller));

        // Raising the threshold doesn't alert items already below it
        $low = $this->mug(4);
        $this->as($this->seller)->putJson('/api/seller/stock-alerts', ['enabled' => true, 'threshold' => 8, 'channel' => 'in_app'])->assertOk();
        $this->buy([[$low, null, 1]]);
        $this->assertCount(1, $this->sent(LowStockNotification::class));

        // Per-product override wins
        $big = $this->mug(20);
        $big->update(['low_stock_threshold' => 15]);
        $this->buy([[$big, null, 5]]);
        $this->assertCount(2, $this->sent(LowStockNotification::class));

        // Disabled: no low-stock alert, out of stock still sent
        $this->as($this->seller)->putJson('/api/seller/stock-alerts', ['enabled' => false, 'threshold' => 2, 'channel' => 'in_app'])->assertOk();
        $last = $this->mug(3);
        $this->buy([[$last, null, 1]]);
        $this->assertCount(2, $this->sent(LowStockNotification::class));
        $this->buy([[$last, null, 2]]);
        $this->assertCount(1, $this->sent(OutOfStockNotification::class));

        $this->as($this->seller)->putJson('/api/seller/stock-alerts', ['enabled' => true, 'threshold' => 0, 'channel' => 'sms'])
            ->assertStatus(422)->assertJsonValidationErrors(['threshold', 'channel']);
    }

    public function test_cancelled_order_restock_rearms_and_updates_total(): void
    {
        [$p, $v] = $this->scarf();
        $orderId = $this->buy([[$p, $v['Noir'], 3]]);
        $this->assertCount(1, $this->sent(LowStockNotification::class));
        $this->assertSame(10, (int) $p->fresh()->stock);

        $ids = DB::table('seller_orders')->where('order_id', $orderId)->pluck('id')->all();
        app(OrderStock::class)->releaseForSellerOrders($ids);
        $this->assertSame(13, (int) $p->fresh()->stock);
        $this->assertNull($v['Noir']->fresh()->last_low_stock_notified_at);
    }

    public function test_admin_list_and_detail_carry_the_breakdown(): void
    {
        [$p, $v] = $this->scarf();
        $admin = $this->makeUser('admin');

        $detail = $this->as($admin)->getJson("/api/admin/products/{$p->id}")->assertOk()->json('data');
        $this->assertSame(13, $detail['stock']);
        $this->assertCount(3, $detail['stock_breakdown']['variants']);

        $list = collect($this->as($admin)->getJson("/api/admin/products?status=all&seller_id={$this->seller->id}")->assertOk()->json('data.data'));
        $row  = $list->firstWhere('id', $p->id);
        $this->assertSame(13, $row['stock_breakdown']['total']);
        $this->assertArrayNotHasKey('variants', $row);

        $review = $this->as($admin)->getJson("/api/admin/products/{$p->id}/review")->assertOk()->json('data.inventory');
        $this->assertSame(13, $review['total_stock']);
        $this->assertSame(2, $review['low_stock_threshold']);
    }
}
