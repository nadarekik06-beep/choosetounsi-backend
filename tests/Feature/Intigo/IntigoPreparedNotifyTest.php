<?php

namespace Tests\Feature\Intigo;

use App\Mail\IntigoAlertMail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Seller marks "prepared": admins told (bell + e-mail, once), parcel sent once,
 * admin panel shows prepared / send state; Intigo pickup and later statuses
 * tell admin, seller and buyer. Admin confirmation never creates a parcel.
 */
class IntigoPreparedNotifyTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
        config(['intigo.alert_email' => 'alerts@example.com']);
        Mail::fake();
    }

    private function adminNotes(string $type): int
    {
        return DB::table('notifications')->where('notifiable_id', $this->admin->id)
            ->where('data', 'like', '%"type":"' . $type . '"%')->count();
    }

    private function confirmedParcel($seller)
    {
        $product = $this->product($seller, 40, false, 10);
        $this->addToCart($product, 1);
        [, $orderId] = $this->placeAndConfirm();
        return $this->parcel($orderId, $seller);
    }

    public function test_prepared_notifies_admins_once_and_sends_one_parcel(): void
    {
        $seller = $this->sellerWithPickup();
        $so = $this->confirmedParcel($seller);

        // Admin confirmation alone never reaches Intigo
        $this->assertNull($this->so($so)->intigo_nid);
        $this->assertSame(0, DB::table('intigo_logs')->where('seller_order_id', $so->id)->where('endpoint', '/parcels/by-name')->count());

        $this->prepare($so, $seller);
        $this->prepare($so, $seller);   // double click

        $fresh = $this->so($so);
        $this->assertSame('confirmed', $fresh->status, 'prepared is not a status');
        $this->assertNotNull($fresh->prepared_at);
        $this->assertSame('FAKE-' . $so->id, $fresh->intigo_nid);
        $this->assertSame(1, DB::table('intigo_logs')->where('seller_order_id', $so->id)->where('endpoint', '/parcels/by-name')->count(), 'one parcel only');

        $this->assertSame(1, $this->adminNotes('intigo_prepared'));
        $this->assertCount(1, Mail::queued(IntigoAlertMail::class, fn ($m) => $m->info && $m->sellerOrderId === $so->id));

        // Admin list + detail
        $row = collect($this->as($this->admin)->getJson('/api/admin/orders')->assertOk()->json('data.data'))->firstWhere('id', $so->order_id);
        $this->assertNotNull($row['parcels_summary'][0]['prepared_at']);
        $this->assertSame('sent', $row['parcels_summary'][0]['intigo']);
        $parcel = collect($this->as($this->admin)->getJson("/api/admin/orders/{$so->order_id}")->assertOk()->json('data.seller_orders'))->firstWhere('id', $so->id);
        $this->assertSame('sent', $parcel['intigo']['send_state']);
        $this->assertNotNull($parcel['intigo']['prepared_at']);
    }

    public function test_a_seller_without_pickup_is_flagged_missing_pickup_and_never_sent(): void
    {
        $seller = $this->seller('free');   // no Intigo pickup index
        $so = $this->confirmedParcel($seller);

        $this->prepare($so, $seller)->assertJsonPath('data.delivery.state', 'failed');

        $fresh = $this->so($so);
        $this->assertNotNull($fresh->prepared_at, 'prepared is saved anyway');
        $this->assertNull($fresh->intigo_nid);
        $this->assertSame(0, DB::table('intigo_logs')->where('seller_order_id', $so->id)->where('endpoint', '/parcels/by-name')->count());
        $this->assertSame(1, $this->adminNotes('intigo_send_failed'));
        $this->assertSame('missing_pickup', \App\Http\Controllers\Admin\IntigoController::sendState($fresh));
        $this->assertTrue(\App\Http\Controllers\Admin\IntigoController::parcelPayload($fresh)['can_retry']);
    }

    public function test_pickup_and_later_statuses_notify_admin_seller_and_buyer(): void
    {
        $seller = $this->sellerWithPickup();
        $so = $this->confirmedParcel($seller);
        $this->prepare($so, $seller);
        $nid = $this->so($so)->intigo_nid;

        $this->webhook($nid, 2000, '2026-11-03T10:00:00+01:00')->assertOk();
        $this->assertSame('handed_to_courier', $this->so($so)->status);
        $this->assertSame('handed_to_courier', DB::table('orders')->where('id', $so->order_id)->value('status'));
        $this->assertSame(1, $this->adminNotes('intigo_carrier_handed_to_courier'));
        $sellerNotes = DB::table('notifications')->where('notifiable_id', $seller->id)->pluck('data')->implode(' ');
        $this->assertStringContainsString('parcel_picked_up', $sellerNotes);
        $buyer = DB::table('notifications')->where('notifiable_id', $this->customer->id)->get(['type', 'data'])->map(fn ($n) => $n->type . ' ' . $n->data)->implode(' ');
        $this->assertStringContainsString('shipped', $buyer);

        // Same webhook retried, then an old one arriving late: nothing moves back, no extra notice
        $this->webhook($nid, 2000, '2026-11-03T10:00:00+01:00')->assertOk()->assertJsonPath('duplicate', true);
        $this->webhook($nid, 1000, '2026-11-03T09:00:00+01:00')->assertOk();
        $this->assertSame('handed_to_courier', $this->so($so)->status);
        $this->assertSame(1, $this->adminNotes('intigo_carrier_handed_to_courier'));

        $this->webhook($nid, 4000)->assertOk();
        $this->webhook($nid, 5000)->assertOk();
        $this->assertSame('delivered', $this->so($so)->status);
        $this->assertNotNull($this->so($so)->cash_collected_at);
        $this->assertSame(1, $this->adminNotes('intigo_carrier_delivered'));
    }
}
