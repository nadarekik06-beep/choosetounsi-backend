<?php

namespace Tests\Feature\Intigo;

use App\Services\Intigo\ParcelCanceller;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Order cancellation, admin tools (size override, pickup index, retry/cancel), seller and buyer views. */
class IntigoAdminAndCancelTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    private const CLIENT_ORDERS = '/api/client/orders/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
    }

    private function confirmedParcel(?\App\Models\User $seller = null): array
    {
        $seller ??= $this->sellerWithPickup();
        $this->addToCart($this->product($seller, 40));
        [, $orderId] = $this->placeAndConfirm();
        return [$this->parcel($orderId, $seller), $seller];
    }

    public function test_cancelling_an_order_in_the_pickup_phase_cancels_the_intigo_parcel(): void
    {
        [$so, $seller] = $this->confirmedParcel();
        $this->prepare($so, $seller);
        $nid = $this->so($so)->intigo_nid;

        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/status", ['status' => 'cancelled'])->assertOk();

        $fresh = $this->so($so);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('cancelled', $fresh->intigo_sync_status);
        $this->assertTrue(DB::table('intigo_logs')->where('endpoint', "/parcels/{$nid}/cancel")->where('http_status', 200)->exists());
    }

    public function test_a_parcel_past_pickup_is_flagged_instead_of_cancelled(): void
    {
        [$so, $seller] = $this->confirmedParcel();
        $this->prepare($so, $seller);
        DB::table('seller_orders')->where('id', $so->id)->update(['intigo_status_code' => 2000, 'intigo_status_label' => 'Au dépôt']);

        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/intigo/cancel")->assertStatus(422);
        $result = app(ParcelCanceller::class)->cancel($so->id, 'Order cancelled');
        $this->assertSame('refused', $result['result']);
        $this->assertStringContainsString('already with Intigo', $this->so($so)->intigo_attention);
        $this->assertFalse(DB::table('intigo_logs')->where('endpoint', 'like', '%/cancel')->exists(), 'no cancel call past pickup');
    }

    public function test_admin_cancel_button_then_resend(): void
    {
        [$so, $seller] = $this->confirmedParcel();
        $this->prepare($so, $seller);

        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/intigo/cancel")->assertOk()
            ->assertJsonPath('data.sync_status', 'cancelled')->assertJsonPath('data.can_retry', true);
        $this->assertSame('confirmed', $this->so($so)->status, 'the order itself is untouched');

        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/intigo/retry")->assertOk()
            ->assertJsonPath('data.nid', 'FAKE-' . $so->id . '-2');
    }

    public function test_admin_size_override_before_sending_updates_the_intigo_cost_only(): void
    {
        $this->setSizeFees(['S' => [8, 7, 4, 6], 'L' => [12, 10, 6, 9], 'XL' => [20, 17, 9, 15]]);
        [$so, $seller] = $this->confirmedParcel();
        $codBefore = $so->cod_amount;

        $this->as($this->admin)->putJson("/api/admin/seller-orders/{$so->id}/parcel-size", ['size' => 'XL'])->assertOk()
            ->assertJsonPath('data.parcel_size', 'XL')->assertJsonPath('data.package_size', 5);
        $fresh = $this->fresh($so);
        $this->assertSame('XL', $fresh->parcel_size);
        $this->assertSame('S', $fresh->parcel_size_computed);
        $this->assertSame($codBefore, $fresh->cod_amount, 'the client pays what they were shown');
        $this->assertParcel($fresh, ['agency' => '17', 'margin' => '-9', 'cod' => '48', 'to_remit' => '31']);

        $this->prepare($so, $seller);
        $this->assertSame(5, $this->sentPayload($so)['package_size']);
        $this->as($this->admin)->putJson("/api/admin/seller-orders/{$so->id}/parcel-size", ['size' => 'S'])->assertStatus(422);
        $this->as($this->admin)->putJson("/api/admin/seller-orders/{$so->id}/parcel-size", ['size' => 'M'])->assertStatus(422);
    }

    public function test_pickup_addresses_and_seller_pickup_index(): void
    {
        $seller = $this->seller();
        $addresses = $this->as($this->admin)->getJson('/api/admin/intigo/pickup-addresses')->assertOk()->json('data');
        $this->assertNotEmpty($addresses);

        $this->as($this->admin)->putJson("/api/admin/sellers/{$seller->id}/intigo-pickup", ['index' => $addresses[1]['index'], 'label' => $addresses[1]['label']])->assertOk();
        $this->as($this->admin)->getJson("/api/admin/sellers/{$seller->id}")->assertOk()
            ->assertJsonPath('data.intigo_pickup_index', $addresses[1]['index']);
        $this->assertSame($addresses[1]['index'], $seller->fresh()->intigo_pickup_index);

        $this->as($seller)->putJson("/api/admin/sellers/{$seller->id}/intigo-pickup", ['index' => 1])->assertStatus(403);
    }

    public function test_admin_overview_order_drawer_and_parcel_history(): void
    {
        [$so, $seller] = $this->confirmedParcel();
        $this->prepare($so, $seller);
        $this->webhook($this->so($so)->intigo_nid, 2000)->assertOk();

        $this->as($this->admin)->getJson('/api/admin/intigo')->assertOk()->assertJsonPath('data.mode', 'fake')
            ->assertJsonStructure(['data' => ['setup' => ['api_key_set', 'webhook_url', 'regions_synced'], 'failed', 'attention', 'logs', 'events']]);
        $drawer = $this->as($this->admin)->getJson("/api/admin/orders/{$so->order_id}")->assertOk()->json('data.seller_orders.0.intigo');
        $this->assertSame('FAKE-' . $so->id, $drawer['nid']);
        $this->assertSame(2000, $drawer['status_code']);
        $detail = $this->as($this->admin)->getJson("/api/admin/seller-orders/{$so->id}/intigo")->assertOk()->json('data');
        $this->assertCount(1, $detail['history']);
        $this->assertNotEmpty($detail['logs']);
        $this->as($seller)->getJson('/api/admin/intigo')->assertStatus(403);
    }

    public function test_seller_sees_the_reference_to_write_on_the_parcel(): void
    {
        [$so, $seller] = $this->confirmedParcel();
        $show = $this->as($seller)->getJson("/api/seller/orders/{$so->id}")->assertOk()->json('data.order');
        $this->assertContains('handed_to_courier', $show['allowed_next']);

        $this->prepare($so, $seller)->assertJsonPath('data.delivery.state', 'sent')->assertJsonPath('data.delivery.nid', 'FAKE-' . $so->id);
        $show = $this->as($seller)->getJson("/api/seller/orders/{$so->id}")->assertOk()->json('data.order');
        $this->assertSame('FAKE-' . $so->id, $show['delivery']['nid']);
        $this->assertNotContains('handed_to_courier', $show['allowed_next'], 'Intigo reports the pickup');
        $list = $this->as($seller)->getJson('/api/seller/orders')->assertOk()->json('data.data');
        $this->assertSame('FAKE-' . $so->id, collect($list)->firstWhere('id', $so->id)['delivery']['nid']);
    }

    public function test_buyer_sees_the_delivery_step_and_never_the_seller_phone(): void
    {
        [$so, $seller] = $this->confirmedParcel();
        $this->prepare($so, $seller);

        $url = self::CLIENT_ORDERS . $so->order_id;
        $res = $this->as($this->customer)->getJson($url)->assertOk();
        $json  = $res->getContent();
        $group = $res->json('data.seller_groups.0');
        $this->assertSame('awaiting_pickup', $group['delivery']['stage']);
        $this->assertSame('FAKE-' . $so->id, $group['delivery']['tracking_ref']);
        foreach (['55111222', '98000111', 'intigo_error', 'intigo_attention', 'seller_net_amount', 'platform_delivery_margin'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $json);
        }

        $this->webhook($this->so($so)->intigo_nid, 4000)->assertOk();
        $this->assertSame('out_for_delivery', $this->as($this->customer)->getJson($url)->json('data.seller_groups.0.delivery.stage'));
    }

    public function test_complaint_intigo_return_reference(): void
    {
        [$so] = $this->confirmedParcel();
        $complaint = new \App\Models\Complaint();
        $complaint->forceFill(['user_id' => $this->customer->id, 'order_id' => $so->order_id, 'complaint_type' => 'damaged', 'description' => 'Broken on arrival'])->save();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$complaint->id}/intigo-return-ref", ['intigo_return_ref' => ' RET-123 '])->assertOk()
            ->assertJsonPath('data.intigo_return_ref', 'RET-123');
    }
}
