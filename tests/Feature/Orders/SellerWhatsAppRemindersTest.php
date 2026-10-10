<?php

namespace Tests\Feature\Orders;

use App\Helpers\PlatformUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Models\SellerOrder;
use App\Models\SellerOrderReminder;
use App\Models\User;
use App\Services\Orders\WhatsApp\ReminderSchedule;
use App\Support\TunisianPhone;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Manual WhatsApp seller notices: admin confirms → initial notice + timed
 * reminders (Tunis working hours) → seller marks prepared → reminders stop.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Orders/SellerWhatsAppRemindersTest.php
 */
class SellerWhatsAppRemindersTest extends TestCase
{
    use DatabaseTransactions;

    private const ADDRESS = [
        'recipient_name' => 'Sara Ben Ali',
        'phone'          => '22 123 456',
        'wilaya'         => 'Ben Arous',
        'delegation'     => 'El Mourouj',
        'address'        => '12 rue de la Liberté',
        'postal_code'    => '2074',
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
        Notification::fake();
        config(['app.frontend_url' => 'https://choosetounsi.tn']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Timing ──────────────────────────────────────────────────────────────

    /** Tunis wall-clock time → due time, as Tunis wall-clock time. */
    private function due(string $tunis, int $hours): string
    {
        return ReminderSchedule::dueAfter(Carbon::parse($tunis, 'Africa/Tunis'), $hours)
            ->timezone('Africa/Tunis')->format('Y-m-d H:i');
    }

    public function test_reminder_timing_follows_tunis_working_hours(): void
    {
        $this->assertSame('2026-10-12 12:00', $this->due('2026-10-12 10:00', 2));   // working hours: +2h
        $this->assertSame('2026-10-12 20:00', $this->due('2026-10-12 18:00', 2));   // ends exactly at 20:00: kept
        $this->assertSame('2026-10-13 08:30', $this->due('2026-10-12 18:30', 2));   // would end after 20:00
        $this->assertSame('2026-10-13 08:30', $this->due('2026-10-12 21:15', 2));   // evening
        $this->assertSame('2026-10-12 08:30', $this->due('2026-10-12 03:00', 2));   // night, after midnight
        $this->assertSame('2026-10-12 08:30', $this->due('2026-10-12 07:59', 4));
        $this->assertSame('2026-10-12 12:00', $this->due('2026-10-12 08:00', 4));   // opening time counts as working hours
        $this->assertSame('2026-10-13 08:30', $this->due('2026-10-12 17:00', 4));

        config(['seller_whatsapp.working_hours.end' => '22:00']);
        $this->assertSame('2026-10-12 21:00', $this->due('2026-10-12 19:00', 2));   // configurable
    }

    public function test_numbers_are_normalized_to_international_format(): void
    {
        foreach (['22 345 678', '+216 22 345 678', '0021622345678', '216-22-345-678', '22.345.678'] as $input) {
            $this->assertSame('21622345678', TunisianPhone::toInternational($input), $input);
        }
        foreach (['12 345 678', '2234567', '+33 6 12 34 56 78', '', '216223456789'] as $input) {
            $this->assertNull(TunisianPhone::toInternational($input), $input);
        }
    }

    // ── Flow ────────────────────────────────────────────────────────────────

    public function test_full_flow_confirm_remind_overdue(): void
    {
        $this->clock('2026-10-12 10:00');
        $seller = $this->makeSeller(['whatsapp_number' => '21698765432']);
        $order  = $this->checkout([$this->makeProduct($seller)]);
        $admin  = $this->makeUser('admin');

        $this->as($admin)->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();
        $so = $this->subOrderOf($order, $seller);

        // a) initial notice due now, b) reminder 1 at 12:00
        $this->assertReminders($so, [0 => ['due', '2026-10-12 10:00'], 1 => ['pending', '2026-10-12 12:00']]);

        // Order drawer: one-click link for the initial notice
        $wa = collect($this->as($admin)->getJson("/api/admin/orders/{$order->id}")->assertOk()->json('data.seller_orders'))
            ->firstWhere('id', $so->id)['whatsapp'];
        $this->assertSame(0, $wa['current']['attempt']);
        $this->assertStringStartsWith('https://wa.me/21698765432?text=', $wa['current']['url']);
        $text = rawurldecode(Str::after($wa['current']['url'], '?text='));
        $this->assertStringContainsString($order->order_number, $text);
        $this->assertStringContainsString('2 articles', $text);
        $this->assertStringContainsString("https://choosetounsi.tn/seller/orders?order={$so->id}", $text);
        $this->assertStringContainsString('Mohamed', $text);

        // Reminders page + badge
        $this->assertSame(1, $this->as($admin)->getJson('/api/admin/whatsapp-reminders/count')->json('data.due'));
        $this->as($admin)->postJson("/api/admin/seller-orders/{$so->id}/whatsapp-sent", ['attempt' => 0])->assertOk()
            ->assertJsonPath('data.history.0.status', 'sent')
            ->assertJsonPath('data.history.0.sent_by.id', $admin->id);
        $this->as($admin)->postJson("/api/admin/seller-orders/{$so->id}/whatsapp-sent", ['attempt' => 0])->assertOk(); // double click
        $this->assertSame(0, $this->as($admin)->getJson('/api/admin/whatsapp-reminders/count')->json('data.total'));

        // 11:55: not yet. 12:00: reminder 1 due
        $this->clock('2026-10-12 11:55');
        $this->artisan('orders:whatsapp-reminders')->assertExitCode(0);
        $this->assertSame([], $this->as($admin)->getJson('/api/admin/whatsapp-reminders')->json('data.due'));
        $this->clock('2026-10-12 12:00');
        $this->artisan('orders:whatsapp-reminders')->assertExitCode(0);
        $due = $this->as($admin)->getJson('/api/admin/whatsapp-reminders')->assertOk()->json('data.due');
        $this->assertCount(1, $due);
        $this->assertSame(1, $due[0]['reminder']['attempt']);
        $this->assertSame($order->order_number, $due[0]['order']['order_number']);
        $this->assertStringContainsString('pas encore marquée comme préparée', rawurldecode($due[0]['reminder']['url']));

        // Reminder 1 sent at 12:10 → reminder 2 at 16:10
        $this->clock('2026-10-12 12:10');
        $this->as($admin)->postJson("/api/admin/seller-orders/{$so->id}/whatsapp-sent", ['attempt' => 1])->assertOk();
        $this->assertReminders($so, [0 => ['sent', '2026-10-12 10:00'], 1 => ['sent', '2026-10-12 12:00'], 2 => ['pending', '2026-10-12 16:10']]);

        // Reminder 2 sent at 18:00 → overdue the next morning at 08:30
        $this->clock('2026-10-12 18:00');
        $this->artisan('orders:whatsapp-reminders');
        $due = $this->as($admin)->getJson('/api/admin/whatsapp-reminders')->json('data.due');
        $this->assertSame(2, $due[0]['reminder']['attempt']);
        $this->assertStringContainsString('annulée', rawurldecode($due[0]['reminder']['url']));
        $this->as($admin)->postJson("/api/admin/seller-orders/{$so->id}/whatsapp-sent", ['attempt' => 2])->assertOk();

        $this->clock('2026-10-13 08:29');
        $this->assertSame([], $this->as($admin)->getJson('/api/admin/whatsapp-reminders')->json('data.overdue'));
        $this->clock('2026-10-13 08:30');
        $overdue = $this->as($admin)->getJson('/api/admin/whatsapp-reminders')->json('data.overdue');
        $this->assertCount(1, $overdue);
        $this->assertSame('55111222', $overdue[0]['seller']['call_phone']);
        $this->assertSame(1, $this->as($admin)->getJson('/api/admin/whatsapp-reminders/count')->json('data.overdue'));

        // Seller finally prepares it: no longer overdue
        $this->as($seller)->postJson("/api/seller/orders/{$so->id}/prepared")->assertOk();
        $this->assertSame(0, $this->as($admin)->getJson('/api/admin/whatsapp-reminders/count')->json('data.total'));
    }

    public function test_night_confirmation_reminds_next_morning(): void
    {
        $this->clock('2026-10-12 22:40');
        $seller = $this->makeSeller();
        $order  = $this->checkout([$this->makeProduct($seller)]);
        $this->as($this->makeUser('admin'))->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();

        $this->assertReminders($this->subOrderOf($order, $seller), [0 => ['due', '2026-10-12 22:40'], 1 => ['pending', '2026-10-13 08:30']]);
    }

    public function test_marking_prepared_cancels_the_reminders_and_hides_them(): void
    {
        $this->clock('2026-10-12 10:00');
        $seller = $this->makeSeller();
        $order  = $this->checkout([$this->makeProduct($seller)]);
        $admin  = $this->makeUser('admin');
        $this->as($admin)->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();
        $so = $this->subOrderOf($order, $seller);

        $this->as($seller)->getJson("/api/seller/orders/{$so->id}")->assertJsonPath('data.order.can_mark_prepared', true);
        $this->as($seller)->postJson("/api/seller/orders/{$so->id}/prepared")->assertOk();
        $this->as($seller)->postJson("/api/seller/orders/{$so->id}/prepared")->assertOk();   // idempotent

        $this->assertReminders($so, [0 => ['cancelled', '2026-10-12 10:00'], 1 => ['cancelled', '2026-10-12 12:00']]);
        $this->assertNotNull($so->fresh()->prepared_at);
        $this->assertSame('confirmed', $so->fresh()->status);   // not a status: the courier step is unchanged

        $this->clock('2026-10-12 13:00');
        $this->artisan('orders:whatsapp-reminders');
        $this->assertSame(0, $this->as($admin)->getJson('/api/admin/whatsapp-reminders/count')->json('data.total'));
        $this->as($admin)->postJson("/api/admin/seller-orders/{$so->id}/whatsapp-sent", ['attempt' => 1])->assertStatus(409);

        // Another seller can't touch it
        $this->as($this->makeSeller())->postJson("/api/seller/orders/{$so->id}/prepared")->assertNotFound();
    }

    public function test_cancelled_or_shipped_parcels_stop_the_reminders(): void
    {
        $this->clock('2026-10-12 10:00');
        $sellerA = $this->makeSeller();
        $sellerB = $this->makeSeller();
        $order   = $this->checkout([$this->makeProduct($sellerA), $this->makeProduct($sellerB)]);
        $admin   = $this->makeUser('admin');
        $this->as($admin)->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();
        $soA = $this->subOrderOf($order, $sellerA);
        $soB = $this->subOrderOf($order, $sellerB);

        $this->as($sellerA)->patchJson("/api/seller/orders/{$soA->id}/status", ['status' => 'cancelled'])->assertOk();
        $this->as($sellerB)->patchJson("/api/seller/orders/{$soB->id}/status", ['status' => 'handed_to_courier'])->assertOk();

        $this->assertSame(0, SellerOrderReminder::whereIn('seller_order_id', [$soA->id, $soB->id])->whereIn('status', SellerOrderReminder::OPEN)->count());
        $this->as($sellerA)->postJson("/api/seller/orders/{$soA->id}/prepared")->assertStatus(422);
    }

    public function test_platform_parcels_and_seller_self_confirmation_get_no_reminders(): void
    {
        $seller = $this->makeSeller();
        $order  = $this->checkout([$this->makeProduct($seller)]);
        $so     = $this->subOrderOf($order, $seller);

        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->assertSame(0, SellerOrderReminder::where('seller_order_id', $so->id)->count());

        // Confirmed before reminders existed: the drawer still offers the initial notice
        $admin = $this->makeUser('admin');
        $wa = collect($this->as($admin)->getJson("/api/admin/orders/{$order->id}")->json('data.seller_orders'))->firstWhere('id', $so->id)['whatsapp'];
        $this->assertSame(0, $wa['current']['attempt']);
        $this->assertStringStartsWith('https://wa.me/21655111222?', $wa['current']['url']);   // shop phone fallback
        $this->assertSame('phone', $wa['phone_source']);
        $this->as($admin)->postJson("/api/admin/seller-orders/{$so->id}/whatsapp-sent", ['attempt' => 0])->assertOk();
        $this->assertSame(['sent', 'pending'], SellerOrderReminder::where('seller_order_id', $so->id)->orderBy('attempt')->pluck('status')->all());
    }

    public function test_arabic_messages_for_sellers_who_chose_arabic(): void
    {
        $seller = $this->makeSeller(['preferred_language' => 'ar']);
        $order  = $this->checkout([$this->makeProduct($seller)]);
        $admin  = $this->makeUser('admin');
        $this->as($admin)->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();

        $url = collect($this->as($admin)->getJson("/api/admin/orders/{$order->id}")->json('data.seller_orders'))->first()['whatsapp']['current']['url'];
        $this->assertStringContainsString('تأكيد التحضير', rawurldecode($url));
    }

    // ── Seller settings ─────────────────────────────────────────────────────

    public function test_seller_sets_whatsapp_number_and_language(): void
    {
        $seller = $this->makeSeller();
        $this->as($seller)->getJson('/api/seller/whatsapp')->assertOk()
            ->assertJsonPath('data.whatsapp_number', null)
            ->assertJsonPath('data.fallback_phone', '+216 55 111 222')
            ->assertJsonPath('data.business_number', '+216 57 252 576');

        $this->as($seller)->putJson('/api/seller/whatsapp', ['whatsapp_number' => '12 345 678', 'language' => 'fr'])
            ->assertStatus(422)->assertJsonValidationErrors('whatsapp_number');
        $this->as($seller)->putJson('/api/seller/whatsapp', ['whatsapp_number' => '22 345 678', 'language' => 'en'])
            ->assertStatus(422)->assertJsonValidationErrors('language');

        $this->as($seller)->putJson('/api/seller/whatsapp', ['whatsapp_number' => '00216 22 345 678', 'language' => 'ar'])->assertOk()
            ->assertJsonPath('data.whatsapp_number', '21622345678')
            ->assertJsonPath('data.display_number', '+216 22 345 678')
            ->assertJsonPath('data.language', 'ar');
        $this->assertSame('21622345678', $seller->fresh()->whatsapp_number);
    }

    public function test_endpoints_are_protected(): void
    {
        $this->getJson('/api/admin/whatsapp-reminders')->assertUnauthorized();
        $this->getJson('/api/seller/whatsapp')->assertUnauthorized();
        $this->as($this->makeSeller())->getJson('/api/admin/whatsapp-reminders')->assertForbidden();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

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

    private function makeProduct(User $seller): Product
    {
        $name = 'WA Product ' . Str::random(6);
        return Product::create([
            'seller_id' => $seller->id, 'name' => $name, 'slug' => Str::slug($name),
            'price' => 40, 'stock' => 50, 'is_approved' => true, 'is_active' => true,
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
