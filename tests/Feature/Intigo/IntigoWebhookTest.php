<?php

namespace Tests\Feature\Intigo;

use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * POST /api/webhooks/intigo: signature, deduplication, status mapping onto
 * our workflow (forward or return branch only, never backwards) and the
 * money / stock effects of each step.
 */
class IntigoWebhookTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
    }

    /** @return array{0: \App\Models\SellerOrder, 1: Product, 2: \App\Models\User} a parcel sent to (fake) Intigo */
    private function sentParcel(int $qty = 2, string $size = 'S'): array
    {
        $seller  = $this->sellerWithPickup();
        $product = $this->product($seller, 40, false, 10);
        $product->update(['parcel_size' => $size]);
        $this->addToCart($product, $qty);
        [, $orderId] = $this->placeAndConfirm();
        $so = $this->parcel($orderId, $seller);
        $this->prepare($so, $seller);
        $so = $this->so($so);
        $this->assertSame('FAKE-' . $so->id, $so->intigo_nid);
        return [$so, $product, $seller];
    }

    private function history(int $soId): array
    {
        return DB::table('seller_order_status_history')->where('seller_order_id', $soId)->orderBy('id')
            ->get()->map(fn ($h) => "{$h->from_status}>{$h->to_status}:{$h->source}")->all();
    }

    public function test_the_signature_is_checked_against_the_raw_body(): void
    {
        [$so] = $this->sentParcel();

        $this->webhook($so->intigo_nid, 2000, null, 'wrong-secret')->assertStatus(401);
        $this->call('POST', '/api/webhooks/intigo', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"data":{"nid":"x","new_status":5000}}')->assertStatus(401);
        // Same JSON re-encoded differently = different raw body = rejected
        $body = '{"event":"parcel.status_changed","data":{"nid":"' . $so->intigo_nid . '","new_status":2000}}';
        $sig  = 'sha256=' . hash_hmac('sha256', $body, self::SECRET);
        $this->call('POST', '/api/webhooks/intigo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => $sig], str_replace(':', ' : ', $body))->assertStatus(401);
        $this->assertSame(0, DB::table('intigo_webhook_events')->count());

        $this->call('POST', '/api/webhooks/intigo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => $sig], $body)->assertOk();
        $this->assertSame('handed_to_courier', $this->so($so)->status);

        config(['intigo.webhook_secret' => null]);
        $this->webhook($so->intigo_nid, 4000)->assertStatus(401);   // no secret configured: refuse everything
    }

    public function test_retried_webhooks_are_applied_once(): void
    {
        [$so] = $this->sentParcel();
        $ts = '2026-10-12T10:00:00.000+01:00';

        $this->webhook($so->intigo_nid, 2000, $ts)->assertOk()->assertJsonPath('duplicate', false);
        $this->webhook($so->intigo_nid, 2000, $ts)->assertOk()->assertJsonPath('duplicate', true);
        $this->webhook($so->intigo_nid, 2000, $ts)->assertOk();

        $this->assertSame(1, DB::table('intigo_webhook_events')->where('nid', $so->intigo_nid)->count());
        $this->assertSame(['pending>confirmed:admin', 'confirmed>handed_to_courier:api'], $this->history($so->id));
    }

    public function test_the_forward_flow_runs_the_delivered_logic(): void
    {
        [$so] = $this->sentParcel();

        $this->webhook($so->intigo_nid, 1000)->assertOk();
        $this->assertSame('confirmed', $this->so($so)->status, 'waiting for pickup');
        $this->assertSame(1000, $this->so($so)->intigo_status_code);

        $this->webhook($so->intigo_nid, 2001)->assertOk();
        $this->assertSame('handed_to_courier', $this->so($so)->status);
        $this->webhook($so->intigo_nid, 3100)->assertOk();
        $this->webhook($so->intigo_nid, 4000)->assertOk();
        $this->assertSame('out_for_delivery', $this->so($so)->status);

        $this->webhook($so->intigo_nid, 5000)->assertOk();
        $fresh = $this->so($so);
        $this->assertSame('delivered', $fresh->status);
        $this->assertNotNull($fresh->cash_collected_at, 'COD collected');
        $this->assertSame('pending', $fresh->getAttribute('payout_status'), 'payout waits for the remittance');
        $this->assertSame(5000, $fresh->intigo_status_code);
        $this->assertSame('delivered', DB::table('orders')->where('id', $so->order_id)->value('status'));
        $this->assertSame(
            ['pending>confirmed:admin', 'confirmed>handed_to_courier:api', 'handed_to_courier>out_for_delivery:api', 'out_for_delivery>delivered:api'],
            $this->history($so->id)
        );
        $this->assertSame('5000 Label 5000', DB::table('seller_order_status_history')->where('seller_order_id', $so->id)->orderByDesc('id')->value('carrier_status_raw'));

        // Notified: the seller (bell) and the buyer
        $sellerNotes = DB::table('notifications')->where('notifiable_id', $so->seller_id)->pluck('data')->implode(' ');
        $this->assertStringContainsString('parcel_delivered', $sellerNotes);
        $this->assertStringContainsString('parcel_out_for_delivery', $sellerNotes);
        $buyerTypes = DB::table('notifications')->where('notifiable_id', $this->customer->id)->pluck('type')->implode(' ');
        $this->assertStringContainsString('order_out_for_delivery', $buyerTypes . ' ' . DB::table('notifications')->where('notifiable_id', $this->customer->id)->pluck('data')->implode(' '));
    }

    public function test_out_of_order_webhooks_never_move_a_parcel_backwards(): void
    {
        [$so] = $this->sentParcel();

        // Missed webhooks: delivered straight from the pickup phase walks the steps in between
        $this->webhook($so->intigo_nid, 5000)->assertOk();
        $this->assertSame('delivered', $this->so($so)->status);
        $this->assertCount(4, $this->history($so->id));

        // Late arrivals of older statuses change nothing
        foreach ([4000, 2000, 1000, 6000] as $late) {
            $this->webhook($so->intigo_nid, $late)->assertOk();
        }
        $fresh = $this->so($so);
        $this->assertSame('delivered', $fresh->status);
        $this->assertSame(5000, $fresh->intigo_status_code);
        $this->assertCount(4, $this->history($so->id));
    }

    public function test_returned_parcel_books_the_size_return_fee_and_restocks(): void
    {
        $this->setSizeFees(['S' => [8, 7, 4, 6], 'L' => [12, 10, 6.5, 9], 'XL' => [20, 17, 9, 15]]);
        [$so, $product] = $this->sentParcel(2, 'L');
        $this->assertSame(8, $product->fresh()->stock, '10 − 2 sold');

        $this->webhook($so->intigo_nid, 2000)->assertOk();
        $this->webhook($so->intigo_nid, 4000)->assertOk();
        $this->webhook($so->intigo_nid, 6000)->assertOk();   // return in progress

        $fresh = $this->so($so);
        $this->assertSame('refused', $fresh->status);
        $this->assertSame(6500, \App\Support\Millimes::of($fresh->getAttribute('refused_agency_fee')), 'L return fee');
        $this->assertSame('platform', $fresh->getAttribute('refused_fee_paid_by'), 'charged to the platform by default');
        $this->assertSame('cancelled', $fresh->getAttribute('payout_status'), 'no payout, no commission earned');
        $this->assertSame(8, $product->fresh()->stock, 'stock stays out until the parcel is back');

        $this->webhook($so->intigo_nid, 6001)->assertOk();
        $this->webhook($so->intigo_nid, 6900)->assertOk();   // back at the seller
        $fresh = $this->so($so);
        $this->assertSame('returned_to_seller', $fresh->status);
        $this->assertSame(10, $product->fresh()->stock, 'restocked');

        // Finance: the return fee is a platform delivery loss
        $loss = DB::table('seller_orders')->where('id', $so->id)->where('refused_fee_paid_by', 'platform')->sum('refused_agency_fee');
        $this->assertEquals(6.5, (float) $loss);
        $this->assertStringContainsString('parcel_returned', DB::table('notifications')->where('notifiable_id', $so->seller_id)->pluck('data')->implode(' '));
    }

    public function test_a_parcel_returned_from_the_warehouse_skips_out_for_delivery(): void
    {
        [$so] = $this->sentParcel();
        $this->webhook($so->intigo_nid, 2001)->assertOk();   // client unreachable on the follow-up call
        $this->webhook($so->intigo_nid, 3201)->assertOk();   // transfer for return
        $this->assertSame('refused', $this->so($so)->status);
        $this->assertSame(['pending>confirmed:admin', 'confirmed>handed_to_courier:api', 'handed_to_courier>refused:api'], $this->history($so->id));

        // Only the carrier may make that move
        $this->as($this->admin)->postJson('/api/admin/seller-orders/' . $so->id . '/status', ['status' => 'refused'])->assertStatus(422);
    }

    public function test_a_carrier_cancellation_is_flagged_to_the_admin_without_cancelling_the_order(): void
    {
        [$so] = $this->sentParcel();
        $this->webhook($so->intigo_nid, 1101)->assertOk();

        $fresh = $this->so($so);
        $this->assertSame('confirmed', $fresh->status, 'the client still wants it: the admin decides');
        $this->assertSame('cancelled', $fresh->intigo_sync_status);
        $this->assertStringContainsString('Intigo cancelled parcel', $fresh->intigo_attention);
        $this->assertTrue(DB::table('notifications')->where('notifiable_id', $this->admin->id)->where('data', 'like', '%intigo_attention%')->orWhere('data', 'like', '%carrier_cancelled%')->exists());

        // The admin re-sends: a new parcel, the cancelled one archived
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/intigo/retry")->assertOk()->assertJsonPath('result', 'sent');
        $fresh = $this->so($so);
        $this->assertSame('FAKE-' . $so->id . '-2', $fresh->intigo_nid);
        $this->assertSame(['FAKE-' . $so->id], $fresh->intigo_previous_nids);
        $this->assertNull($fresh->intigo_attention);
    }

    public function test_rerouting_is_informational_and_unknown_parcels_are_ignored(): void
    {
        [$so] = $this->sentParcel();
        $this->webhook($so->intigo_nid, 4000)->assertOk();
        $this->webhook($so->intigo_nid, 99)->assertOk();
        $this->assertSame('out_for_delivery', $this->so($so)->status);
        $this->assertSame(4000, $this->so($so)->intigo_status_code);

        $this->webhook('NOT-OURS-1', 5000)->assertOk();
        $this->assertSame('ignored: unknown nid', DB::table('intigo_webhook_events')->where('nid', 'NOT-OURS-1')->value('result'));
    }

    public function test_simulate_webhook_command_drives_the_same_route(): void
    {
        [$so] = $this->sentParcel();
        $this->artisan('intigo:simulate-webhook', ['nid' => $so->intigo_nid, 'status' => 4000, '--direct' => true])->assertExitCode(0);
        $this->assertSame('out_for_delivery', $this->so($so)->status);
        $this->artisan('intigo:simulate-webhook', ['nid' => $so->intigo_nid, 'status' => 5000, '--direct' => true, '--bad-signature' => true])->assertExitCode(1);
        $this->assertSame('out_for_delivery', $this->so($so)->status);
    }
}
