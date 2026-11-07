<?php

namespace Tests\Feature\Orders;

use App\Models\Complaint;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\Buyer\ComplaintNotification;
use App\Notifications\ComplaintCreatedNotification;
use App\Services\Orders\CheckoutGuard;
use App\Services\Orders\GuestOrderAccess;
use App\Support\GuestCheckout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Intigo\IntigoTestHelpers;
use Tests\TestCase;

/**
 * Guests track and complain with only their phone (no account, no e-mail):
 * device tokens (hashed), order number + phone, "find all my orders", guest
 * returns through the same rules as accounts, staff views, account linking.
 *
 * Run:  php vendor/bin/phpunit tests/Feature/Orders/GuestOrderAccessTest.php
 */
class GuestOrderAccessTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    private string $cartToken;
    private User $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('choosetounsi_test', DB::connection()->getDatabaseName());

        $this->setUpIntigo();
        config(['guest_checkout.enabled' => true]);
        PlatformSetting::where('key', GuestCheckout::SETTING_KEY)->delete();
        PlatformSetting::flushCache();
        Cache::flush(); // rate limiter counters
        Storage::fake('public');
        $this->shop = $this->seller();
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function anon()
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        return $this;
    }

    private function withOrderToken(string $token)
    {
        return $this->anon()->withHeaders([GuestOrderAccess::HEADER => $token]);
    }

    /** A guest COD order on this phone: [order, token]. */
    private function guestOrder(string $phone = '57252576', array $body = []): array
    {
        $this->cartToken = Str::random(40);
        $this->anon()->withHeaders([GuestCheckout::CART_HEADER => $this->cartToken])
            ->postJson('/api/cart', ['product_id' => $this->product($this->shop, 50)->id, 'quantity' => 2])->assertOk();

        $this->travel(CheckoutGuard::RECENT_SECONDS + 1)->seconds();
        $quote = $this->anon()->withHeaders([GuestCheckout::CART_HEADER => $this->cartToken])
            ->postJson('/api/checkout/quote', ['phone' => $phone])->assertOk()->json('data');
        $res = $this->anon()->withHeaders([GuestCheckout::CART_HEADER => $this->cartToken])->postJson('/api/checkout', [
            'recipient_name' => 'Amel Ben Ali', 'wilaya' => 'Tunis', 'delegation' => 'Bab Bhar',
            'address' => '5 rue de la Kasbah', 'postal_code' => '1000', 'phone' => $phone,
            'payment_method' => 'cod', 'expected_total' => $quote['total'],
        ] + $body)->assertCreated()->json();

        return [Order::findOrFail($res['order_id']), $res['tracking_token']];
    }

    private function delivered(Order $order): Order
    {
        DB::table('seller_orders')->where('order_id', $order->id)->update(['status' => 'delivered']);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'delivered', 'updated_at' => now()]);
        return $order->fresh();
    }

    private function returnRequest(array $extra = []): array
    {
        return $extra + [
            'complaint_type' => 'wrong_size',
            'description'    => 'La taille reçue ne correspond pas à celle commandée.',
            'return_all'     => 1,
            'images'         => [UploadedFile::fake()->image('proof.jpg', 400, 300)],
        ];
    }

    /** A delivered guest order with one guest return: [order, token, complaint]. */
    private function guestReturn(string $phone = '57252576'): array
    {
        [$order, $token] = $this->guestOrder($phone);
        $this->delivered($order);
        $id = $this->withOrderToken($token)->post('/api/guest/complaints', $this->returnRequest(), ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        return [$order, $token, Complaint::findOrFail($id)];
    }

    // ── tracking ────────────────────────────────────────────────────────────

    public function test_guest_tracks_by_token_without_any_email(): void
    {
        [$order, $token] = $this->guestOrder();
        $this->assertNull($order->guest_email);

        $data = $this->anon()->getJson("/api/orders/track/{$token}")->assertOk()->json('data');
        $this->assertSame($order->order_number, $data['order_number']);
        $this->assertSame('** *** 576', $data['phone_masked']);
        $this->assertSame('5 ru•••, Bab Bhar, Tunis', $data['address_masked']);
        $this->assertEquals(108.0, $data['total']);
        $this->assertEquals(8.0, $data['shipping_fee']);
        $this->assertStringNotContainsString('57252576', json_encode($data));
        $this->assertStringNotContainsString('5 rue de la Kasbah', json_encode($data));
    }

    public function test_guest_tracks_by_order_number_and_phone_in_any_format(): void
    {
        [$order] = $this->guestOrder('57252576');
        foreach (['57 252 576', '57252576', '+216 57252576', '0021657252576'] as $typed) {
            $token = $this->anon()->postJson('/api/orders/track', ['order_number' => $order->order_number, 'phone' => $typed])
                ->assertOk()->json('data.tracking_token');
            $this->anon()->getJson("/api/orders/track/{$token}")->assertOk()->assertJsonPath('data.order_number', $order->order_number);
        }
    }

    public function test_wrong_phone_is_rejected_with_a_generic_message(): void
    {
        [$order] = $this->guestOrder('57252576');
        $wrong = $this->anon()->postJson('/api/orders/track', ['order_number' => $order->order_number, 'phone' => '22999999'])
            ->assertStatus(404)->json();
        $unknown = $this->anon()->postJson('/api/orders/track', ['order_number' => 'ORD-NOPE1234', 'phone' => '57252576'])
            ->assertStatus(404)->json();
        // Same answer: nobody learns whether the order exists
        $this->assertSame($wrong, $unknown);
        $this->assertSame('order_not_found', $wrong['code']);
    }

    public function test_failed_lookups_are_rate_limited_per_order_number(): void
    {
        [$order] = $this->guestOrder('57252576');
        // Right answers never count
        for ($i = 0; $i < 3; $i++) {
            $this->anon()->postJson('/api/orders/track', ['order_number' => $order->order_number, 'phone' => '57252576'])->assertOk();
        }
        for ($i = 0; $i < 5; $i++) {
            $this->anon()->postJson('/api/orders/track', ['order_number' => $order->order_number, 'phone' => '2299999' . $i])->assertStatus(404);
        }
        // 6th try, even with the right phone: blocked for 10 minutes
        $this->anon()->postJson('/api/orders/track', ['order_number' => $order->order_number, 'phone' => '57252576'])
            ->assertStatus(429)->assertJsonPath('code', 'rate_limited');

        $this->travel(11)->minutes();
        $this->anon()->postJson('/api/orders/track', ['order_number' => $order->order_number, 'phone' => '57252576'])->assertOk();
    }

    public function test_failed_lookups_are_rate_limited_per_ip_across_order_numbers(): void
    {
        [$order] = $this->guestOrder('57252576');
        for ($i = 0; $i < 5; $i++) {
            $this->anon()->postJson('/api/orders/track', ['order_number' => 'ORD-GUESS00' . $i, 'phone' => '57252576'])->assertStatus(404);
        }
        $this->anon()->postJson('/api/orders/track', ['order_number' => $order->order_number, 'phone' => '57252576'])->assertStatus(429);
    }

    public function test_find_all_my_orders_needs_one_order_number_never_the_phone_alone(): void
    {
        [$a] = $this->guestOrder('57252576');
        [$b] = $this->guestOrder('57 252 576');
        [$other] = $this->guestOrder('98111222');

        $this->anon()->postJson('/api/orders/track/all', ['phone' => '57252576'])->assertStatus(422);

        $list = $this->anon()->postJson('/api/orders/track/all', ['order_number' => $a->order_number, 'phone' => '+216 57 252 576'])
            ->assertOk()->json('data');
        $numbers = array_column($list, 'order_number');
        $this->assertEqualsCanonicalizing([$a->order_number, $b->order_number], $numbers);
        $this->assertNotContains($other->order_number, $numbers);
        foreach ($list as $row) {
            $this->anon()->getJson("/api/orders/track/{$row['tracking_token']}")->assertOk()->assertJsonPath('data.order_number', $row['order_number']);
        }

        // Proving with someone else's phone gives nothing
        $this->anon()->postJson('/api/orders/track/all', ['order_number' => $a->order_number, 'phone' => '98111222'])->assertStatus(404);
    }

    public function test_tokens_are_stored_hashed_only(): void
    {
        [$order, $token] = $this->guestOrder();
        $this->assertNull($order->tracking_token);
        $stored = DB::table('guest_order_tokens')->where('order_id', $order->id)->pluck('token_hash')->all();
        $this->assertSame([hash('sha256', $token)], $stored);
        $this->assertStringNotContainsString($token, json_encode(DB::table('orders')->where('id', $order->id)->first()));
    }

    public function test_legacy_plain_tracking_links_still_open_the_order(): void
    {
        // Orders placed before hashing: their link was backfilled as a hash (migration)
        [$order] = $this->guestOrder();
        $legacy = Str::random(40);
        DB::table('orders')->where('id', $order->id)->update(['tracking_token' => $legacy]);
        DB::table('guest_order_tokens')->insert(['order_id' => $order->id, 'token_hash' => hash('sha256', $legacy), 'source' => 'legacy', 'created_at' => now()]);

        $this->anon()->getJson("/api/orders/track/{$legacy}")->assertOk();
        $this->withOrderToken($legacy)->getJson('/api/guest/complaints')->assertOk();
    }

    // ── guest returns ───────────────────────────────────────────────────────

    public function test_guest_complaint_creation_same_rules_as_accounts(): void
    {
        Notification::fake();
        [$order, $token] = $this->guestOrder();

        // Not delivered yet: nothing to return, same message as accounts
        $this->withOrderToken($token)->getJson('/api/guest/complaints/eligible-orders')->assertOk()->assertJsonCount(0, 'data');
        $this->withOrderToken($token)->post('/api/guest/complaints', $this->returnRequest(), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', __('messages.complaint.only_delivered'));

        $this->delivered($order);
        $eligible = $this->withOrderToken($token)->getJson('/api/guest/complaints/eligible-orders')->assertOk();
        $this->assertSame($order->id, $eligible->json('data.0.id'));
        $this->assertNotEmpty($eligible->json('after_sale_fees'));

        // Photo proof is required, and must be an image
        $this->withOrderToken($token)->post('/api/guest/complaints', array_diff_key($this->returnRequest(), ['images' => 1]), ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->withOrderToken($token)->post('/api/guest/complaints', $this->returnRequest(['images' => [UploadedFile::fake()->create('virus.php', 10, 'text/x-php')]]), ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->withOrderToken($token)->post('/api/guest/complaints', $this->returnRequest(['images' => [UploadedFile::fake()->image('big.jpg')->size(6000)]]), ['Accept' => 'application/json'])
            ->assertStatus(422);

        $res = $this->withOrderToken($token)->post('/api/guest/complaints', $this->returnRequest(), ['Accept' => 'application/json'])->assertCreated();
        $complaint = Complaint::findOrFail($res->json('data.id'));
        $this->assertNull($complaint->user_id);
        $this->assertSame($order->id, $complaint->order_id);
        $this->assertSame((int) $this->shop->id, (int) $complaint->seller_id);
        $this->assertSame(Complaint::STATUS_REQUESTED, $complaint->status);
        $this->assertSame(Complaint::payerFor('wrong_size'), $complaint->shipping_payer);
        $this->assertNotEmpty($complaint->image_paths);
        Storage::disk('public')->assertExists($complaint->image_paths[0]);

        // Seller and admins are told, as for any return
        Notification::assertSentTo($this->shop, ComplaintCreatedNotification::class);
        Notification::assertSentTo($this->admin, ComplaintCreatedNotification::class);
        // No e-mail given: nothing sent to the guest (they follow it on the page)
        Notification::assertNotSentTo($this->shop, ComplaintNotification::class);

        // The guest sees it with its tracking
        $list = $this->withOrderToken($token)->getJson('/api/guest/complaints')->assertOk()->json('data.data');
        $this->assertSame([$complaint->id], array_column($list, 'id'));
        $one = $this->withOrderToken($token)->getJson("/api/guest/complaints/{$complaint->id}")->assertOk()->json('data');
        $this->assertNotEmpty($one['timeline']);

        // The same units can't be returned twice
        $this->withOrderToken($token)->post('/api/guest/complaints', $this->returnRequest(), ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_guest_with_an_email_gets_return_updates_by_email(): void
    {
        Notification::fake();
        [$order, $token] = $this->guestOrder('57252576', ['email' => 'amel@example.com']);
        $this->delivered($order);
        $this->withOrderToken($token)->post('/api/guest/complaints', $this->returnRequest(), ['Accept' => 'application/json'])->assertCreated();

        Notification::assertSentOnDemand(ComplaintNotification::class, function ($n, $channels, $notifiable) {
            return ($notifiable->routes['mail'] ?? null) === 'amel@example.com';
        });
    }

    public function test_guest_cancels_and_escalates_like_an_account(): void
    {
        Notification::fake();
        [, $token, $complaint] = $this->guestReturn();

        // Seller refuses → the guest contests → admins decide
        $this->as($this->shop)->patchJson("/api/seller/complaints/{$complaint->id}/reject", [
            'rejection_reason' => 'Article utilisé', 'seller_note' => 'Traces visibles sur la photo',
        ])->assertOk();
        $this->withOrderToken($token)->patchJson("/api/guest/complaints/{$complaint->id}/escalate", ['note' => 'Il est neuf, voir la photo.'])
            ->assertOk()->assertJsonPath('data.status', Complaint::STATUS_ESCALATED);

        // A second request, withdrawn before the shop answers
        [, $token2, $c2] = $this->guestReturn('98765432');
        $this->withOrderToken($token2)->patchJson("/api/guest/complaints/{$c2->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', Complaint::STATUS_CANCELLED);
    }

    public function test_idor_another_orders_ids_are_never_reachable(): void
    {
        [$orderA, $tokenA, $complaintA] = $this->guestReturn('57252576');
        [$orderB, $tokenB, $complaintB] = $this->guestReturn('98111222');

        // Token A cannot read, contest or cancel B's return
        $this->withOrderToken($tokenA)->getJson("/api/guest/complaints/{$complaintB->id}")->assertStatus(404);
        $this->withOrderToken($tokenA)->patchJson("/api/guest/complaints/{$complaintB->id}/cancel")->assertStatus(404);
        $this->withOrderToken($tokenA)->patchJson("/api/guest/complaints/{$complaintB->id}/escalate")->assertStatus(404);
        $this->assertSame([$complaintA->id], array_column($this->withOrderToken($tokenA)->getJson('/api/guest/complaints')->json('data.data'), 'id'));

        // …nor file a return on B by sending B's order id
        $this->withOrderToken($tokenA)->post('/api/guest/complaints', $this->returnRequest(['order_id' => $orderB->id]), ['Accept' => 'application/json'])
            ->assertStatus(404);

        // No token / a made-up token: nothing
        $this->anon()->getJson("/api/guest/complaints/{$complaintA->id}")->assertStatus(404);
        $this->withOrderToken(Str::random(64))->getJson('/api/guest/complaints')->assertStatus(404);
        // An order id is never a key
        $this->anon()->getJson("/api/orders/track/{$orderA->id}")->assertStatus(404);

        // An account's return is invisible to guest tokens, and guests' to accounts
        $this->as($this->customer)->getJson("/api/client/complaints/{$complaintA->id}")->assertStatus(404);
    }

    public function test_seller_and_admin_see_guest_returns_with_badge_name_and_phone(): void
    {
        Notification::fake();
        [$order, , $complaint] = $this->guestReturn('57252576');

        $row = collect($this->as($this->shop)->getJson('/api/seller/complaints')->assertOk()->json('data.data'))->firstWhere('id', $complaint->id);
        $this->assertTrue($row['buyer']['is_guest']);
        $this->assertSame('Amel Ben Ali', $row['buyer']['name']);
        $this->assertSame('57252576', $row['buyer']['phone']);
        $this->assertNull($row['user']);
        $this->as($this->shop)->getJson("/api/seller/complaints/{$complaint->id}")->assertOk()->assertJsonPath('data.buyer.is_guest', true);

        $admin = collect($this->as($this->admin)->getJson('/api/admin/complaints')->assertOk()->json('data.data'))->firstWhere('id', $complaint->id);
        $this->assertTrue($admin['buyer']['is_guest']);
        $this->assertSame('57252576', $admin['buyer']['phone']);
        $show = $this->as($this->admin)->getJson("/api/admin/complaints/{$complaint->id}")->assertOk()->json('data');
        $this->assertTrue($show['buyer']['is_guest']);
        $this->assertSame(['cash'], $show['refund_methods']); // COD, no wallet for a guest
        // Admin search by the guest's phone
        $ids = array_column($this->as($this->admin)->getJson('/api/admin/complaints?search=57252576')->json('data.data'), 'id');
        $this->assertContains($complaint->id, $ids);

        // Seller decision works as for any return
        $this->as($this->shop)->patchJson("/api/seller/complaints/{$complaint->id}/approve", ['seller_note' => 'OK'])
            ->assertOk()->assertJsonPath('data.status', Complaint::STATUS_SELLER_ACCEPTED);
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$complaint->id}/approve")->assertOk();
        $this->assertSame(Complaint::STATUS_ADMIN_APPROVED, $complaint->fresh()->status);
    }

    // ── signed-in buyers: unchanged ─────────────────────────────────────────

    public function test_logged_in_return_flow_unchanged(): void
    {
        Notification::fake();
        $this->addToCart($this->product($this->shop, 40));
        $res   = $this->checkout();
        $order = $this->delivered(Order::findOrFail($res['order_id']));

        $this->as($this->customer)->getJson('/api/client/complaints/eligible-orders')->assertOk()->assertJsonPath('data.0.id', $order->id);
        $id = $this->as($this->customer)->post('/api/client/complaints', $this->returnRequest(['order_id' => $order->id]), ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $c = Complaint::findOrFail($id);
        $this->assertSame((int) $this->customer->id, (int) $c->user_id);
        Notification::assertSentTo($this->customer, ComplaintNotification::class);

        $list = $this->as($this->customer)->getJson('/api/client/complaints')->assertOk()->json('data.data');
        $this->assertSame([$id], array_column($list, 'id'));
        $this->as($this->customer)->patchJson("/api/client/complaints/{$id}/cancel")->assertOk();

        // Staff: an account, not a guest
        $row = collect($this->as($this->shop)->getJson('/api/seller/complaints')->json('data.data'))->firstWhere('id', $id);
        $this->assertFalse($row['buyer']['is_guest']);

        // Guest endpoints never open account orders
        $this->anon()->postJson('/api/orders/track', ['order_number' => $order->order_number, 'phone' => '22123456'])->assertStatus(404);
        // Another account can't touch it
        $this->as($this->makeUser('client'))->getJson("/api/client/complaints/{$id}")->assertStatus(404);
    }

    // ── account linking (optional) ──────────────────────────────────────────

    public function test_account_adds_device_guest_orders_on_request_only_when_phone_matches(): void
    {
        Notification::fake();
        [$mine, $tokenMine, $complaint] = $this->guestReturn('57252576');
        [$notMine, $tokenOther] = $this->guestOrder('98111222');

        $user = $this->makeUser('client');
        $user->forceFill(['phone' => '57252576'])->save();

        // Logging in attaches nothing by itself
        $this->assertNull($mine->fresh()->user_id);

        $offer = $this->as($user)->postJson('/api/orders/guest/claimable', ['tokens' => [$tokenMine, $tokenOther, Str::random(64)]])
            ->assertOk()->json('data');
        $this->assertSame([$mine->order_number], array_column($offer, 'order_number'));

        $this->as($user)->postJson('/api/orders/guest/claim', ['tokens' => [$tokenMine, $tokenOther]])->assertOk()->assertJsonPath('claimed', 1);
        $this->assertSame((int) $user->id, (int) $mine->fresh()->user_id);
        $this->assertSame((int) $user->id, (int) $complaint->fresh()->user_id); // its return follows
        $this->assertNull($notMine->fresh()->user_id);

        // Now in the account's lists
        $this->assertContains($complaint->id, array_column($this->as($user)->getJson('/api/client/complaints')->json('data.data'), 'id'));
        // The device link still opens the order
        $this->anon()->getJson("/api/orders/track/{$tokenMine}")->assertOk()->assertJsonPath('data.claimed', true);

        // Phone alone (no token held) is never enough
        $this->as($user)->postJson('/api/orders/guest/claim', ['tokens' => []])->assertStatus(422);
    }
}
