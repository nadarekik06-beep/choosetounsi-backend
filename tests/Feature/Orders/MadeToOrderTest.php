<?php

namespace Tests\Feature\Orders;

use App\Helpers\PlatformUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Models\SellerOrder;
use App\Models\SellerOrderReminder;
use App\Models\User;
use App\Services\Delivery\DeliveryTimeSettings;
use App\Support\BusinessDays;
use App\Support\MadeToOrder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Sur commande" products: business-day maths, delivery-time settings endpoint,
 * server-side field normalisation and the shifted seller WhatsApp reminders.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Orders/MadeToOrderTest.php
 */
class MadeToOrderTest extends TestCase
{
    use DatabaseTransactions;

    private const ADDRESS = [
        'recipient_name' => 'Sara Ben Ali', 'phone' => '22 123 456', 'wilaya' => 'Ben Arous',
        'delegation' => 'El Mourouj', 'address' => '12 rue de la Liberté', 'postal_code' => '2074',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (!DB::table('users')->where('id', 1)->exists()) {
            DB::table('users')->insert([
                'id' => 1, 'name' => "CHOOSE'Tounsi", 'email' => 'platform-' . Str::random(6) . '@test.local',
                'password' => bcrypt('x'), 'role' => 'seller', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        PlatformUser::reset();
        PlatformSetting::flushCache();
        Notification::fake();
        config(['app.frontend_url' => 'https://choosetounsi.tn']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PlatformSetting::flushCache();
        parent::tearDown();
    }

    // ── Business days ───────────────────────────────────────────────────────

    private function settings(): array
    {
        return ['working_days' => [1, 2, 3, 4, 5], 'holidays' => ['2026-12-17']] + DeliveryTimeSettings::defaults(Carbon::parse('2026-11-19'));
    }

    public function test_business_day_arithmetic_skips_weekends_and_holidays(): void
    {
        $add = fn (string $from, int $k) => BusinessDays::add(Carbon::parse($from, 'Africa/Tunis'), $k, $this->settings())->toDateString();
        $this->assertSame('2026-11-19', $add('2026-11-19', 0));
        $this->assertSame('2026-11-23', $add('2026-11-20', 1));   // Fri → Mon
        $this->assertSame('2026-11-23', $add('2026-11-21', 1));   // Sat → Mon
        $this->assertSame('2026-11-26', $add('2026-11-23', 3));
        $this->assertSame('2026-12-21', $add('2026-12-16', 2));   // Thu 17 holiday, Fri 18 = 1, Mon 21 = 2
    }

    public function test_default_holidays_are_the_fixed_dates_of_the_next_12_months(): void
    {
        $h = DeliveryTimeSettings::fixedHolidaysFrom(Carbon::parse('2026-11-19'));
        $this->assertSame(['2026-12-17', '2027-01-01', '2027-03-20', '2027-04-09', '2027-05-01', '2027-07-25', '2027-08-13', '2027-10-15'], $h);
    }

    // ── Settings endpoint ───────────────────────────────────────────────────

    public function test_public_delivery_time_endpoint_returns_defaults_and_admin_can_edit(): void
    {
        $d = $this->getJson('/api/delivery-time')->assertOk()->json('data');
        $this->assertSame(1, $d['pickup_delay_min_business_days']);
        $this->assertSame(2, $d['pickup_delay_max_business_days']);
        $this->assertSame(1, $d['transit_business_days']);
        $this->assertSame([1, 2, 3, 4, 5], $d['working_days']);
        $this->assertSame('23:59', $d['order_cutoff_time']);

        $this->putJson('/api/admin/delivery-time', [])->assertUnauthorized();
        $this->as($this->makeUser('seller'))->putJson('/api/admin/delivery-time', [])->assertForbidden();

        $admin   = $this->makeUser('admin');
        $payload = ['pickup_delay_min_business_days' => 2, 'pickup_delay_max_business_days' => 3, 'transit_business_days' => 1,
            'working_days' => [1, 2, 3, 4, 5], 'holidays' => ['2026-12-17', '2027-03-10'], 'order_cutoff_time' => '16:00'];
        $this->as($admin)->putJson('/api/admin/delivery-time', array_merge($payload, ['pickup_delay_max_business_days' => 1]))->assertStatus(422);
        $this->as($admin)->putJson('/api/admin/delivery-time', $payload)->assertOk();
        $this->assertSame(['2026-12-17', '2027-03-10'], $this->getJson('/api/delivery-time')->json('data.holidays'));
        $this->assertSame('16:00', $this->getJson('/api/delivery-time')->json('data.order_cutoff_time'));
    }

    // ── Server-side normalisation ───────────────────────────────────────────

    public function test_made_to_order_attributes_are_forced_server_side(): void
    {
        $this->assertSame(['is_made_to_order' => false, 'preparation_days' => null], MadeToOrder::attributes(['is_made_to_order' => false, 'preparation_days' => 9]));
        $this->assertSame(['is_made_to_order' => false, 'preparation_days' => null], MadeToOrder::attributes([]));
        $this->assertSame(['is_made_to_order' => true, 'preparation_days' => 3], MadeToOrder::attributes(['is_made_to_order' => '1', 'preparation_days' => '3']));
        $this->assertSame([], MadeToOrder::attributes(['name' => 'x'], true));   // partial update: untouched
    }

    public function test_validation_rules(): void
    {
        $check = fn (array $in) => validator($in, MadeToOrder::rules())->errors()->keys();
        $this->assertSame([], $check(['is_made_to_order' => true, 'preparation_days' => 3]));
        $this->assertSame([], $check(['is_made_to_order' => '1', 'preparation_days' => '30']));
        $this->assertSame([], $check(['is_made_to_order' => false]));
        $this->assertSame(['preparation_days'], $check(['is_made_to_order' => true]));
        $this->assertSame(['preparation_days'], $check(['is_made_to_order' => 'true']));
        $this->assertSame(['preparation_days'], $check(['is_made_to_order' => true, 'preparation_days' => 0]));
        $this->assertSame(['preparation_days'], $check(['is_made_to_order' => true, 'preparation_days' => 31]));
    }

    public function test_admin_quick_update_sets_and_clears_the_fields(): void
    {
        $seller  = $this->makeSeller();
        $product = $this->makeProduct($seller);
        $admin   = $this->makeUser('admin');

        $this->as($admin)->putJson("/api/admin/products/{$product->id}", ['is_made_to_order' => true])->assertStatus(422)->assertJsonValidationErrors('preparation_days');
        $this->as($admin)->putJson("/api/admin/products/{$product->id}", ['is_made_to_order' => true, 'preparation_days' => 4])->assertOk();
        $this->assertSame([true, 4], [$product->fresh()->is_made_to_order, $product->fresh()->preparation_days]);

        $this->as($admin)->putJson("/api/admin/products/{$product->id}", ['stock' => 7])->assertOk();   // unrelated edit keeps them
        $this->assertSame([true, 4], [$product->fresh()->is_made_to_order, $product->fresh()->preparation_days]);

        $this->as($admin)->putJson("/api/admin/products/{$product->id}", ['is_made_to_order' => false, 'preparation_days' => 9])->assertOk();
        $this->assertSame([false, null], [$product->fresh()->is_made_to_order, $product->fresh()->preparation_days]);
    }

    public function test_public_product_detail_exposes_the_fields(): void
    {
        $seller  = $this->makeSeller();
        $product = $this->makeProduct($seller, 3);
        $this->getJson("/api/products/{$product->slug}")->assertOk()
            ->assertJsonPath('data.is_made_to_order', true)->assertJsonPath('data.preparation_days', 3);
    }

    // ── WhatsApp reminders ──────────────────────────────────────────────────

    public function test_regular_parcel_keeps_the_2h_4h_timing(): void
    {
        $this->clock('2026-11-23 10:00');
        $seller = $this->makeSeller();
        $order  = $this->checkout([$this->makeProduct($seller)]);
        $admin  = $this->makeUser('admin');
        $this->as($admin)->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();
        $so = $this->subOrderOf($order, $seller);
        $this->assertReminders($so, [0 => ['due', '2026-11-23 10:00'], 1 => ['pending', '2026-11-23 12:00']]);

        $this->clock('2026-11-23 15:00');
        $this->as($admin)->postJson("/api/admin/seller-orders/{$so->id}/whatsapp-sent", ['attempt' => 1])->assertOk();
        $this->clock('2026-11-23 15:30');
        $this->as($admin)->postJson("/api/admin/seller-orders/{$so->id}/whatsapp-sent", ['attempt' => 2])->assertOk();
        $this->assertSame('2026-11-23 19:30', $so->fresh()->overdue_at->timezone('Africa/Tunis')->format('Y-m-d H:i'));
    }

    public function test_made_to_order_parcel_shifts_reminder_1_and_overdue(): void
    {
        $this->clock('2026-11-23 10:00');   // Monday
        $seller = $this->makeSeller();
        $order  = $this->checkout([$this->makeProduct($seller, 3)]);
        $admin  = $this->makeUser('admin');
        $this->as($admin)->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();
        $so = $this->subOrderOf($order, $seller);

        // Initial notice still goes out now; reminder 1 waits for Thursday (3 business days) + 2h
        $this->assertReminders($so, [0 => ['due', '2026-11-23 10:00'], 1 => ['pending', '2026-11-26 10:00']]);

        // Reminders sent early by the admin: the overdue flag still waits for the ready day
        $this->clock('2026-11-24 15:00');
        $this->as($admin)->postJson("/api/admin/seller-orders/{$so->id}/whatsapp-sent", ['attempt' => 1])->assertOk();
        $this->as($admin)->postJson("/api/admin/seller-orders/{$so->id}/whatsapp-sent", ['attempt' => 2])->assertOk();
        $this->assertSame('2026-11-26 12:00', $so->fresh()->overdue_at->timezone('Africa/Tunis')->format('Y-m-d H:i'));
    }

    public function test_mixed_parcel_uses_the_longest_preparation_time_and_skips_holidays(): void
    {
        $this->clock('2026-12-15 10:00');   // Tuesday; Thu 17 Dec is a holiday
        $seller = $this->makeSeller();
        $order  = $this->checkout([$this->makeProduct($seller), $this->makeProduct($seller, 1), $this->makeProduct($seller, 2)]);
        $this->as($this->makeUser('admin'))->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();
        $so = $this->subOrderOf($order, $seller);

        // Longest = 2 days: Wed 16, (Thu 17 holiday), Fri 18 → 10:00
        $this->assertReminders($so, [0 => ['due', '2026-12-15 10:00'], 1 => ['pending', '2026-12-18 10:00']]);
    }

    private function clock(string $tunis): void
    {
        Carbon::setTestNow(Carbon::parse($tunis, 'Africa/Tunis')->utc());
    }

    /** @param array<int, array{0:string, 1:string}> $expected attempt => [status, due (Tunis time)] */
    private function assertReminders(SellerOrder $so, array $expected): void
    {
        $actual = SellerOrderReminder::where('seller_order_id', $so->id)->orderBy('attempt')->get()
            ->mapWithKeys(fn ($r) => [$r->attempt => [$r->status, $r->due_at->timezone('Africa/Tunis')->format('Y-m-d H:i')]])
            ->all();
        $this->assertSame($expected, $actual);
    }

    private function makeUser(string $role, array $attributes = []): User
    {
        return $this->withCompleteProfile(User::create($attributes + [
            'name'      => ucfirst($role) . ' ' . Str::random(5),
            'email'     => $role . '_' . Str::random(10) . '@test.local',
            'password'  => bcrypt('secret-password'),
            'role'      => $role,
            'is_active' => true,
        ]));
    }

    private function makeSeller(array $attributes = []): User
    {
        $seller = $this->makeUser('seller', $attributes);
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

    private function makeProduct(User $seller, ?int $prepDays = null): Product
    {
        $name = 'WA Product ' . Str::random(6);
        return Product::create([
            'seller_id' => $seller->id, 'name' => $name, 'slug' => Str::slug($name),
            'price' => 40, 'stock' => 50, 'is_approved' => true, 'is_active' => true,
            'is_made_to_order' => $prepDays !== null, 'preparation_days' => $prepDays,
        ]);
    }

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken]);
    }

    private function checkout(array $products): Order
    {
        $customer = $this->makeUser('client');
        foreach ($products as $product) {
            Cart::create(['user_id' => $customer->id, 'product_id' => $product->id, 'quantity' => 2]);
        }
        $res = $this->as($customer)->postJson('/api/checkout', self::ADDRESS + ['payment_method' => 'cod'])->assertCreated();
        return Order::findOrFail($res->json('order_id'));
    }

    private function subOrderOf(Order $order, User $seller): SellerOrder
    {
        return SellerOrder::where('order_id', $order->id)->where('seller_id', $seller->id)->firstOrFail();
    }
}
