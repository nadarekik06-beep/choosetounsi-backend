<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\SellerOrder;
use App\Services\Intigo\ParcelSender;
use App\Services\Orders\CheckoutGuard;
use App\Services\Orders\IllegalTransition;
use App\Services\Orders\ParcelStatus;
use App\Services\Orders\WhatsApp\SellerReminderService;
use App\Support\GuestCheckout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Intigo\IntigoTestHelpers;
use Tests\Feature\Intigo\ScriptedIntigoClient;
use Tests\TestCase;

/**
 * Orders wait for the admin: checkout (guest or account) → pending; only the
 * admin confirms; only then can the seller mark it prepared, which sends the
 * Intigo parcel (ScriptedIntigoClient: nothing reaches the network).
 *
 * Run:  php -d extension=gd vendor/bin/phpunit tests/Feature/Orders/AdminConfirmationRequiredTest.php
 */
class AdminConfirmationRequiredTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    private ScriptedIntigoClient $client;
    private string $cartToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('choosetounsi_test', DB::connection()->getDatabaseName());
        $this->setUpIntigo();
        $this->client = $this->scripted();
        config(['guest_checkout.enabled' => true]);
        PlatformSetting::where('key', GuestCheckout::SETTING_KEY)->delete();
        PlatformSetting::flushCache();
        Cache::flush();
        $this->cartToken = Str::random(40);
    }

    private function guestOrder($product): int
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $h = [GuestCheckout::CART_HEADER => $this->cartToken];
        $this->withHeaders($h)->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 1])->assertOk();
        $this->travel(CheckoutGuard::RECENT_SECONDS + 1)->seconds();
        $quote = $this->withHeaders($h)->postJson('/api/checkout/quote', ['phone' => '22333444'])->assertOk()->json('data');
        return (int) $this->withHeaders($h)->postJson('/api/checkout', [
            'recipient_name' => 'Amel Guest', 'wilaya' => 'Tunis', 'delegation' => 'Bab Bhar',
            'address' => '5 rue de la Kasbah', 'postal_code' => '1000', 'phone' => '22333444',
            'payment_method' => 'cod', 'expected_total' => $quote['total'],
        ])->assertCreated()->json('order_id');
    }

    /** The whole flow, for a guest order and an account order. */
    public function test_guest_and_account_orders_wait_for_the_admin_before_prepare_and_intigo(): void
    {
        foreach (['guest' => 3, 'account' => 4] as $kind => $pickupIndex) {
            $seller = $this->sellerWithPickup($pickupIndex);
            $sends  = count($this->client->calls['create']);
            $product = $this->product($seller, 40);
            if ($kind === 'guest') {
                $orderId = $this->guestOrder($product);
            } else {
                $this->addToCart($product);
                $orderId = (int) $this->checkout()['order_id'];
            }
            $so = SellerOrder::where('order_id', $orderId)->where('seller_id', $seller->id)->firstOrFail();

            // 1. Placed: pending everywhere, no admin stamp
            $this->assertSame('pending', Order::find($orderId)->status, $kind);
            $this->assertSame('pending', $so->status, $kind);
            $this->assertNull($so->admin_confirmed_at, $kind);

            // 2. Seller: no prepare button, no confirm option, API calls refused
            $show = $this->as($seller)->getJson("/api/seller/orders/{$so->id}")->assertOk()->json('data.order');
            $this->assertFalse($show['can_mark_prepared'], $kind);
            $this->assertTrue($show['awaiting_admin_confirmation'], $kind);
            $this->assertNotContains('confirmed', $show['allowed_next'], $kind);
            $this->assertNotContains('handed_to_courier', $show['allowed_next'], $kind);

            $this->as($seller)->postJson("/api/seller/orders/{$so->id}/prepared")
                ->assertStatus(403)->assertJsonPath('code', 'awaiting_admin_confirmation');
            $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'confirmed'])->assertStatus(422);
            $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'handed_to_courier'])->assertStatus(422);

            $so->refresh();
            $this->assertSame('pending', $so->status, $kind);
            $this->assertNull($so->prepared_at, $kind);
            $this->assertNull($so->intigo_nid, $kind);
            $this->assertSame('pending', Order::find($orderId)->status, $kind);
            $this->assertSame(0, DB::table('seller_order_notifications')->where('seller_order_id', $so->id)->where('event', 'confirmed')->count());
            $this->assertCount($sends, $this->client->calls['create'], $kind);   // nothing sent to Intigo

            // 3. Admin confirms: stamp, order confirmed, seller told, WhatsApp reminders scheduled
            $this->as($this->admin)->patchJson("/api/admin/orders/{$orderId}/confirm-order", ['action' => 'confirmed'])->assertOk();
            $so->refresh();
            $this->assertSame('confirmed', $so->status);
            $this->assertNotNull($so->admin_confirmed_at);
            $this->assertSame($this->admin->id, (int) $so->admin_confirmed_by);
            $this->assertSame('confirmed', Order::find($orderId)->status);
            $this->assertNotNull(Order::find($orderId)->confirmed_at);
            $this->assertSame(1, DB::table('seller_order_notifications')->where('seller_order_id', $so->id)->where('event', 'confirmed')->count());
            $this->assertGreaterThan(0, DB::table('seller_order_reminders')->where('seller_order_id', $so->id)->count());

            $this->as($seller)->getJson("/api/seller/orders/{$so->id}")->assertJsonPath('data.order.can_mark_prepared', true);

            // 4. Seller prepares → Intigo parcel (mocked)
            $this->prepare($so, $seller);
            $so->refresh();
            $this->assertNotNull($so->prepared_at);
            $this->assertNotNull($so->intigo_nid, $kind . ': ' . $so->intigo_error . ' / ' . $so->intigo_sync_status);
            $this->assertSame(ParcelSender::SENT, $so->intigo_sync_status);
            $this->assertCount($sends + 1, $this->client->calls['create'], $kind);
        }
    }

    public function test_only_an_admin_source_can_confirm_from_any_code_path(): void
    {
        $seller = $this->sellerWithPickup();
        $this->addToCart($this->product($seller, 30));
        $orderId = (int) $this->checkout()['order_id'];
        $so = SellerOrder::where('order_id', $orderId)->firstOrFail();

        foreach (['seller', 'system', 'api', 'courier', null] as $source) {
            try {
                app(ParcelStatus::class)->transition($so, 'confirmed', ['source' => $source]);
                $this->fail("source {$source} confirmed a parcel");
            } catch (IllegalTransition $e) {
                $this->assertStringContainsString('only an admin', $e->getMessage());
            }
        }
        $this->assertSame('pending', $so->fresh()->status);

        // Back to pending (admin) clears the stamp: the seller can't prepare any more
        app(ParcelStatus::class)->transition($so, 'confirmed', ['source' => 'admin', 'by' => $this->admin]);
        $this->assertNotNull($so->fresh()->admin_confirmed_at);
        app(ParcelStatus::class)->transition($so, 'pending', ['source' => 'admin', 'by' => $this->admin]);
        $this->assertNull($so->fresh()->admin_confirmed_at);
        $this->as($seller)->postJson("/api/seller/orders/{$so->id}/prepared")->assertStatus(403);
    }

    /** A parcel 'confirmed' without the admin stamp (seller-confirmed before the fix) is never prepared nor sent. */
    public function test_confirmed_without_admin_stamp_is_neither_prepared_nor_sent(): void
    {
        $seller = $this->sellerWithPickup();
        $this->addToCart($this->product($seller, 30));
        $orderId = (int) $this->checkout()['order_id'];
        $so = SellerOrder::where('order_id', $orderId)->firstOrFail();
        DB::table('seller_orders')->where('id', $so->id)->update(['status' => 'confirmed']);   // legacy row
        $so->refresh();

        $this->assertFalse(ParcelStatus::canMarkPrepared($so));
        $this->as($seller)->postJson("/api/seller/orders/{$so->id}/prepared")->assertStatus(422);
        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'handed_to_courier'])->assertStatus(422);

        try {
            app(SellerReminderService::class)->markPrepared($so, $seller->id);
            $this->fail('prepared without admin confirmation');
        } catch (IllegalTransition $e) {
        }

        // Every Intigo send goes through ParcelSender: refused, no call
        $r = app(ParcelSender::class)->retry($so->id);
        $this->assertSame('skipped', $r['result']);
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/intigo/retry")->assertStatus(422);
        $this->assertCount(0, $this->client->calls['create']);
        $this->assertNull($so->fresh()->intigo_nid);
        $this->assertNull($so->fresh()->prepared_at);
    }

    // ── Parcels already sent before the rule (like seller_order 6): later steps are never blocked ──

    /** Confirmed + prepared + sent to Intigo, with NO admin stamp (seller-confirmed before the fix). */
    private function legacySentParcel(int $pickupIndex = 3): array
    {
        $seller = $this->sellerWithPickup($pickupIndex);
        $this->addToCart($this->product($seller, 50));
        $orderId = (int) $this->checkout()['order_id'];
        $so = SellerOrder::where('order_id', $orderId)->firstOrFail();
        $this->as($this->admin)->patchJson("/api/admin/orders/{$orderId}/confirm-order", ['action' => 'confirmed'])->assertOk();
        $this->prepare($so, $seller);
        DB::table('seller_orders')->where('id', $so->id)->update(['admin_confirmed_at' => null, 'admin_confirmed_by' => null]);
        $so->refresh();
        $this->assertNotNull($so->intigo_nid);
        $this->assertNotNull($so->prepared_at);
        $this->assertNull($so->admin_confirmed_at);
        return [$so, $seller, $orderId];
    }

    public function test_legacy_sent_parcel_webhooks_delivery_settlement_and_return_still_work(): void
    {
        [$so, $seller, $orderId] = $this->legacySentParcel();

        // Admin drawer and seller page still load; nothing offers a re-send
        $this->as($this->admin)->getJson("/api/admin/seller-orders/{$so->id}/intigo")->assertOk()->assertJsonPath('data.can_retry', false);
        $this->as($seller)->getJson("/api/seller/orders/{$so->id}")->assertOk()->assertJsonPath('data.order.can_mark_prepared', false);
        $this->as($seller)->postJson("/api/seller/orders/{$so->id}/prepared")->assertOk();   // already prepared: idempotent, nothing re-sent

        // Intigo webhooks: picked up → out for delivery → delivered
        $this->webhook($so->intigo_nid, 1002, null, null, 1000)->assertOk();
        $this->assertSame('handed_to_courier', $so->fresh()->status);
        $this->webhook($so->intigo_nid, 4000)->assertOk();
        $this->assertSame('out_for_delivery', $so->fresh()->status);
        $this->webhook($so->intigo_nid, 5000)->assertOk();
        $so->refresh();
        $this->assertSame('delivered', $so->status);
        $this->assertNotNull($so->cash_collected_at);
        $this->assertSame('delivered', Order::find($orderId)->status);

        // Finance: remittance → payable → settlement batch → paid
        $this->as($this->admin)->postJson("/api/admin/finance/confirm-money/{$so->id}")->assertOk();
        $this->assertSame('ready', $so->fresh()->payout_status);
        $batch = $this->as($this->admin)->postJson('/api/admin/settlements/create', ['seller_id' => $seller->id, 'batch_date' => now()->toDateString()])->assertOk()->json('data');
        $this->as($this->admin)->postJson("/api/admin/settlements/{$batch['id']}/confirm")->assertOk();
        $this->assertSame('paid', $so->fresh()->payout_status);

        // Complaint / return: requested by the buyer, accepted by the seller, approved by the admin
        $res = $this->as($this->customer)->post('/api/client/complaints', [
            'order_id' => $orderId, 'complaint_type' => 'damaged_product', 'return_all' => 1,
            'description' => 'The item arrived broken, the box was crushed.',
            'images' => [\Illuminate\Http\UploadedFile::fake()->image('proof.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();
        $complaintId = $res->json('data.id');
        $this->as($seller)->patchJson("/api/seller/complaints/{$complaintId}/approve")->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$complaintId}/approve")->assertOk();
    }

    public function test_legacy_sent_parcel_admin_delivered_and_refused_return_branch_still_work(): void
    {
        // Admin marks it delivered by hand (after the carrier picked it up)
        [$so] = $this->legacySentParcel(3);
        $this->webhook($so->intigo_nid, 1002, null, null, 1000)->assertOk();
        $this->webhook($so->intigo_nid, 4000)->assertOk();
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/delivered")->assertOk();
        $this->assertSame('delivered', $so->fresh()->status);

        // Refused at the door → returned to the seller (webhooks)
        [$so2] = $this->legacySentParcel(4);
        $this->webhook($so2->intigo_nid, 1002, null, null, 1000)->assertOk();
        $this->webhook($so2->intigo_nid, 4000)->assertOk();
        $this->webhook($so2->intigo_nid, 6000)->assertOk();
        $this->assertSame('refused', $so2->fresh()->status);
        $this->webhook($so2->intigo_nid, 6900)->assertOk();
        $this->assertSame('returned_to_seller', $so2->fresh()->status);
    }
}
