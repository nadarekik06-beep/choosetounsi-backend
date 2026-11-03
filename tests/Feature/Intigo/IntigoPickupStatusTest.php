<?php

namespace Tests\Feature\Intigo;

use App\Http\Controllers\Api\Seller\SellerOrderController;
use App\Http\Resources\Buyer\BuyerOrderResource;
use App\Services\Intigo\IntigoStatusMap;
use App\Services\Intigo\IntigoStatusSync;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Intigo 1002 "Pickup Ramassé" (seen live on ORD-VLIQ7ART): the agent has the
 * parcel, so it moves to handed_to_courier. Undocumented pickup codes (1001,
 * 1003–1008) are logged and shown, never applied. Plus the safety nets that
 * re-apply a status: the 15-min poller and the admin "Sync with Intigo".
 */
class IntigoPickupStatusTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
    }

    private function sentParcel(?\App\Models\User $seller = null): \App\Models\SellerOrder
    {
        $seller ??= $this->sellerWithPickup();
        $this->addToCart($this->product($seller, 40, false, 10), 1);
        [, $orderId] = $this->placeAndConfirm();
        $so = $this->parcel($orderId, $seller);
        $this->prepare($so, $seller);
        return $this->so($so);
    }

    private function history(int $soId): array
    {
        return DB::table('seller_order_status_history')->where('seller_order_id', $soId)->orderBy('id')
            ->get()->map(fn ($h) => "{$h->from_status}>{$h->to_status}:{$h->source}")->all();
    }

    private function pickedUpNotes(\App\Models\SellerOrder $so): array
    {
        return [
            'seller' => DB::table('notifications')->where('notifiable_id', $so->seller_id)->where('data', 'like', '%parcel_picked_up%')->count(),
            'admin'  => DB::table('notifications')->where('notifiable_id', $this->admin->id)->where('data', 'like', '%Parcel picked up by Intigo%')->count(),
        ];
    }

    public function test_the_mapping(): void
    {
        $this->assertSame('confirmed', IntigoStatusMap::target(1000));
        $this->assertSame('handed_to_courier', IntigoStatusMap::target(1002));
        foreach ([1001, 1003, 1004, 1005, 1006, 1007, 1008] as $code) {
            $this->assertNull(IntigoStatusMap::target($code), "{$code} has no status of ours");
            $this->assertTrue(IntigoStatusMap::isUnmapped($code));
        }
        $this->assertFalse(IntigoStatusMap::isUnmapped(99));
        $this->assertFalse(IntigoStatusMap::isUnmapped(6500));
        $this->assertTrue(IntigoStatusMap::isPickupPhase(1000));
        $this->assertFalse(IntigoStatusMap::isPickupPhase(1002), 'picked up: no longer cancellable through the API');
    }

    public function test_a_signed_1002_webhook_moves_the_parcel_to_handed_to_courier_and_notifies_once(): void
    {
        $so = $this->sentParcel();
        $ts = '2026-11-03T13:43:12.230741+00:00';

        $this->webhook($so->intigo_nid, 1002, $ts, null, 1000)->assertOk()->assertJsonPath('duplicate', false);

        $fresh = $this->so($so);
        $this->assertSame('handed_to_courier', $fresh->status);
        $this->assertSame(1002, $fresh->intigo_status_code);
        $this->assertSame('Label 1002', $fresh->intigo_status_label);
        $this->assertSame('handed_to_courier', DB::table('orders')->where('id', $so->order_id)->value('status'));
        $this->assertSame(['pending>confirmed:admin', 'confirmed>handed_to_courier:api'], $this->history($so->id));
        $this->assertSame(['seller' => 1, 'admin' => 1], $this->pickedUpNotes($so));

        // Intigo retries the same event: stored once, nothing more
        $this->webhook($so->intigo_nid, 1002, $ts, null, 1000)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, DB::table('intigo_webhook_events')->where('nid', $so->intigo_nid)->where('new_status', 1002)->count());
        $this->assertSame(['seller' => 1, 'admin' => 1], $this->pickedUpNotes($so));

        // Prepared stays as it was (a flag next to the status)
        $this->assertNotNull($fresh->prepared_at);
    }

    public function test_a_bad_signature_changes_nothing(): void
    {
        $so = $this->sentParcel();
        $this->webhook($so->intigo_nid, 1002, null, 'wrong-secret')->assertStatus(401);
        $this->assertSame('confirmed', $this->so($so)->status);
        $this->assertSame(0, DB::table('intigo_webhook_events')->where('nid', $so->intigo_nid)->count());
    }

    public function test_a_late_1002_never_moves_a_parcel_backwards(): void
    {
        $so = $this->sentParcel();
        $this->webhook($so->intigo_nid, 4000, '2026-11-03T15:00:00+01:00')->assertOk();
        $this->webhook($so->intigo_nid, 1002, '2026-11-03T14:43:00+01:00')->assertOk();

        $fresh = $this->so($so);
        $this->assertSame('out_for_delivery', $fresh->status);
        $this->assertSame(4000, $fresh->intigo_status_code);
        $this->assertStringContainsString('older than the current status', DB::table('intigo_webhook_events')->where('nid', $so->intigo_nid)->where('new_status', 1002)->value('result'));
    }

    public function test_undocumented_pickup_codes_are_logged_and_shown_but_never_applied(): void
    {
        Log::spy();
        $so = $this->sentParcel();

        $this->webhook($so->intigo_nid, 1003, '2026-11-03T10:00:00+01:00')->assertOk();
        $fresh = $this->so($so);
        $this->assertSame('confirmed', $fresh->status, 'no status of ours for 1003');
        $this->assertSame(1003, $fresh->intigo_status_code, "Intigo's code and label are shown");
        $this->assertSame('Label 1003', $fresh->intigo_status_label);
        $this->assertStringContainsString('unmapped status 1003', DB::table('intigo_webhook_events')->where('nid', $so->intigo_nid)->where('new_status', 1003)->value('result'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'unmapped status 1003'));

        // A completely unknown code: logged, kept on the event, nothing moves
        $this->webhook($so->intigo_nid, 7777, '2026-11-03T10:05:00+01:00')->assertOk();
        $this->assertSame('confirmed', $this->so($so)->status);
        $this->assertStringContainsString('unmapped status 7777', DB::table('intigo_webhook_events')->where('new_status', 7777)->value('result'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'unmapped status 7777'));

        // After the pickup, a late 1001 doesn't move anything back
        $this->webhook($so->intigo_nid, 1002, '2026-11-03T11:00:00+01:00')->assertOk();
        $this->webhook($so->intigo_nid, 1001, '2026-11-03T09:00:00+01:00')->assertOk();
        $this->assertSame('handed_to_courier', $this->so($so)->status);
        $this->assertSame(1002, $this->so($so)->intigo_status_code, 'picked up ends the pickup phase: a late 1001 is older');

        // After the pickup, a late undocumented 1004 doesn't replace "picked up" either
        $this->webhook($so->intigo_nid, 1004, '2026-11-03T09:30:00+01:00')->assertOk();
        $this->assertSame(1002, $this->so($so)->intigo_status_code);
    }

    public function test_picked_up_then_delivered_runs_the_delivered_logic(): void
    {
        $so = $this->sentParcel();
        $this->webhook($so->intigo_nid, 1002, '2026-11-03T14:43:00+01:00')->assertOk();
        $this->webhook($so->intigo_nid, 5000, '2026-11-04T11:00:00+01:00')->assertOk();

        $fresh = $this->so($so);
        $this->assertSame('delivered', $fresh->status);
        $this->assertNotNull($fresh->cash_collected_at);
        $this->assertSame('delivered', DB::table('orders')->where('id', $so->order_id)->value('status'));
        $this->assertSame(
            ['pending>confirmed:admin', 'confirmed>handed_to_courier:api', 'handed_to_courier>out_for_delivery:api', 'out_for_delivery>delivered:api'],
            $this->history($so->id)
        );
    }

    public function test_one_shops_pickup_does_not_move_the_other_shops_parcel(): void
    {
        $a = $this->sellerWithPickup(3);
        $b = $this->sellerWithPickup(4);
        $this->addToCart($this->product($a, 40), 1);
        $this->addToCart($this->product($b, 50), 1);
        [, $orderId] = $this->placeAndConfirm();
        $soA = $this->parcel($orderId, $a);
        $soB = $this->parcel($orderId, $b);
        $this->prepare($soA, $a);
        $this->prepare($soB, $b);

        $this->webhook($this->so($soA)->intigo_nid, 1002)->assertOk();

        $this->assertSame('handed_to_courier', $this->so($soA)->status);
        $this->assertSame('confirmed', $this->so($soB)->status);
        $this->assertSame('handed_to_courier', DB::table('orders')->where('id', $orderId)->value('status'));
    }

    public function test_the_poller_applies_a_new_status_from_intigo(): void
    {
        $so = $this->sentParcel();
        $client = $this->scripted();
        $client->statuses = [$so->intigo_nid => ['status' => 1002, 'status_label' => 'Pickup Ramassé']];

        $this->artisan('intigo:sync-statuses', ['--no-prune' => true])->assertExitCode(0);

        $fresh = $this->so($so);
        $this->assertSame('handed_to_courier', $fresh->status);
        $this->assertSame('Pickup Ramassé', $fresh->intigo_status_label);

        // Next run, same answer: nothing more
        $this->artisan('intigo:sync-statuses', ['--no-prune' => true])->assertExitCode(0);
        $this->assertSame(['pending>confirmed:admin', 'confirmed>handed_to_courier:api'], $this->history($so->id));
        $this->assertSame(['seller' => 1, 'admin' => 1], $this->pickedUpNotes($so));
    }

    public function test_the_poller_catches_up_a_parcel_stuck_behind_a_code_it_already_has(): void
    {
        // Production state before the fix: 1002 stored, parcel still confirmed
        $so = $this->sentParcel();
        DB::table('seller_orders')->where('id', $so->id)->update(['intigo_status_code' => 1002, 'intigo_status_label' => 'Pickup Ramassé']);
        $client = $this->scripted();
        $client->statuses = [$so->intigo_nid => ['status' => 1002, 'status_label' => 'Pickup Ramassé']];

        $this->artisan('intigo:sync-statuses', ['--no-prune' => true])->assertExitCode(0);
        $this->assertSame('handed_to_courier', $this->so($so)->status);
    }

    public function test_the_admin_sync_button_reads_intigo_and_applies_the_status_once(): void
    {
        $so = $this->sentParcel();
        DB::table('seller_orders')->where('id', $so->id)->update(['intigo_status_code' => 1002, 'intigo_status_label' => 'Pickup Ramassé']);
        $client = $this->scripted();
        $client->statuses = [$so->intigo_nid => ['status' => 1002, 'status_label' => 'Pickup Ramassé', 'updated_at' => '2026-11-03T13:43:12.173049Z']];

        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/intigo/sync")
            ->assertOk()->assertJsonPath('status', 'handed_to_courier')->assertJsonPath('data.status_code', 1002);
        $this->assertSame([$so->intigo_nid], $client->calls['getParcel'], 'one GET, no write call');
        $this->assertSame([], $client->calls['create']);
        $this->assertSame([], $client->calls['cancel']);

        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/intigo/sync")
            ->assertOk()->assertJsonPath('result', 'already handed_to_courier');
        $this->assertSame(['seller' => 1, 'admin' => 1], $this->pickedUpNotes($so));

        // Not for sellers
        $this->as($so->seller)->postJson("/api/admin/seller-orders/{$so->id}/intigo/sync")->assertStatus(403);
    }

    public function test_the_sync_order_command(): void
    {
        $so = $this->sentParcel();
        $client = $this->scripted();
        $client->statuses = [$so->intigo_nid => ['status' => 1002, 'status_label' => 'Pickup Ramassé']];
        $number = DB::table('orders')->where('id', $so->order_id)->value('order_number');

        $this->artisan('intigo:sync-order', ['order_number' => $number])->assertExitCode(0);
        $this->assertSame('handed_to_courier', $this->so($so)->status);
    }

    public function test_seller_and_buyer_see_the_status_and_the_carrier_timeline(): void
    {
        $so = $this->sentParcel();
        $this->webhook($so->intigo_nid, 1002, '2026-11-03T14:43:00+01:00', null, 1000)->assertOk();
        $fresh = $this->so($so);

        $seller = SellerOrderController::deliveryInfo($fresh);
        $this->assertSame($so->intigo_nid, $seller['nid']);
        $this->assertSame('Label 1002', $seller['status_label']);
        $this->assertSame([1002], array_column($seller['timeline'], 'code'));

        $buyer = BuyerOrderResource::stage($fresh, true);
        $this->assertSame('in_transit', $buyer);
        $this->assertSame([['code' => 1002, 'label' => 'Label 1002']], array_map(fn ($e) => ['code' => $e['code'], 'label' => $e['label']], IntigoStatusSync::timeline($fresh)));
        $this->assertArrayNotHasKey('result', IntigoStatusSync::timeline($fresh)[0], 'no internal details for buyers');
    }
}
