<?php

namespace Tests\Feature\Delivery;

use App\Models\Order;
use App\Models\Product;
use App\Models\SellerAdjustment;
use App\Services\Delivery\DeliverySettings;
use App\Services\Orders\DeliveryDocumentService;
use App\Support\Millimes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Parcel outcomes (admin only) and when a payout becomes payable:
 * delivered → cash collected; payable only once the admin confirms the
 * remittance; refused / cancelled → no payout, no commission.
 */
class ParcelLifecycleTest extends TestCase
{
    use DatabaseTransactions, DeliveryTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDelivery();
    }

    private function financeRow(object $so, string $query = ''): ?array
    {
        return collect($this->as($this->admin)->getJson("/api/admin/finance/orders?seller_id={$so->seller_id}&per_page=100{$query}")->assertOk()->json('data.data'))
            ->firstWhere('id', $so->id);
    }

    // 13 ────────────────────────────────────────────────────────────────────
    public function test_13_payout_is_payable_only_after_delivery_and_remittance(): void
    {
        $seller = $this->seller('free');
        $this->addToCart($this->product($seller, 50));
        $res = $this->checkout();
        $so  = $this->parcel($res['order_id'], $seller);
        $this->assertSame('pending', $so->payout_status);

        // Not delivered: no remittance, no settlement
        $this->confirmRemittance($so)->assertStatus(422);
        $this->as($this->admin)->postJson('/api/admin/settlements/create', ['seller_id' => $seller->id, 'batch_date' => now()->toDateString()])->assertStatus(422);

        // Delivered = cash collected, still not payable
        $this->deliver($so);
        $so = $this->fresh($so);
        $this->assertSame('delivered', $so->status);
        $this->assertNotNull($so->cash_collected_at);
        $this->assertSame('pending', $so->payout_status);
        $this->assertSame('delivered', Order::find($res['order_id'])->status);
        $this->assertSame([], $this->as($this->admin)->getJson("/api/admin/finance/pending-payouts?seller_id={$seller->id}")->json('data.data'));
        $k = $this->as($this->admin)->getJson("/api/admin/finance/overview?period=all&seller_id={$seller->id}")->json('data.delivery');
        $this->assertSame(51000, self::m($k['pending_at_delivery_company']));
        $this->assertSame(42500, self::m($k['seller_payouts_not_payable']));

        // Remittance confirmed by the admin → payable → settled
        $this->confirmRemittance($so)->assertOk();
        $this->assertSame('ready', $this->fresh($so)->payout_status);
        $k = $this->as($this->admin)->getJson("/api/admin/finance/overview?period=all&seller_id={$seller->id}")->json('data.delivery');
        $this->assertSame(51000, self::m($k['remitted_to_platform']));
        $this->assertSame(0, self::m($k['pending_at_delivery_company']));
        $this->assertSame(42500, self::m($k['seller_payouts_payable']));

        $batch = $this->as($this->admin)->postJson('/api/admin/settlements/create', ['seller_id' => $seller->id, 'batch_date' => now()->toDateString()])->assertOk()->json('data');
        $this->assertSame(42500, self::m($batch['total_seller_payout']));
        $this->as($this->admin)->postJson("/api/admin/settlements/{$batch['id']}/confirm")->assertOk();
        $this->assertSame('paid', $this->fresh($so)->payout_status);
        $this->assertBalanced($this->fresh($so));

        // Seller view: item amount, commission, net and stage per order
        $row = collect($this->as($seller)->getJson('/api/seller/earnings/orders')->assertOk()->json('data.data'))->firstWhere('id', $so->id);
        $this->assertSame('paid', $row['payout_stage']);
        $this->assertSame(50000, self::m($row['items_amount']));
        $this->assertSame(7500, self::m($row['commission_amount']));
        $this->assertSame(42500, self::m($row['net_earnings']));
    }

    // 14 ────────────────────────────────────────────────────────────────────
    public function test_14_cancelled_parcel_has_no_payout_and_no_commission(): void
    {
        $seller = $this->seller('free');
        $this->addToCart($this->product($seller, 50));
        $res = $this->checkout();
        $so  = $this->parcel($res['order_id'], $seller);

        $this->as($this->admin)->patchJson("/api/admin/orders/{$res['order_id']}/confirm-order", ['action' => 'cancelled'])->assertOk();
        $so = $this->fresh($so);
        $this->assertSame('cancelled', $so->payout_status);
        $this->assertNull($this->financeRow($so), 'cancelled parcels are hidden from Finance › Orders');
        $row = $this->financeRow($so, '&include_cancelled=1');
        $this->assertSame(0, self::m($row['commission_amount']));
        $this->assertSame(0, self::m($row['seller_net_amount']));
        $this->assertSame(0, self::m($row['cod_amount']));
        $this->confirmRemittance($so)->assertStatus(422);
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/delivered")->assertStatus(422);
        // History kept on the frozen columns
        $this->assertSame(58000, self::m($so->cod_amount));
    }

    public function test_14_refused_parcel_fee_absorbed_by_the_platform(): void
    {
        $this->setFees(8, 7, 6, ['refused_parcel_agency_fee' => 5000, 'refused_parcel_fee_paid_by' => 'platform']);
        $seller  = $this->seller('free');
        $product = $this->product($seller, 50, false, 10);
        $this->addToCart($product, 2);
        $res = $this->checkout();
        $so  = $this->parcel($res['order_id'], $seller);
        $this->assertSame(8, Product::find($product->id)->stock);

        $this->refuse($so)->assertOk();
        $so = $this->fresh($so);
        $this->assertSame('refused', $so->status);
        $this->assertSame('cancelled', $so->payout_status);
        $this->assertSame(5000, self::m($so->refused_agency_fee));
        $this->assertSame('platform', $so->refused_fee_paid_by);
        $this->assertSame(8, Product::find($product->id)->stock);    // still with the courier
        $this->assertSame('refused', Order::find($res['order_id'])->status);
        $this->assertFalse(SellerAdjustment::where('seller_order_id', $so->id)->exists());

        $row = $this->financeRow($so);
        $this->assertSame(0, self::m($row['commission_amount']));
        $this->assertSame(0, self::m($row['seller_net_amount']));
        $k = $this->as($this->admin)->getJson("/api/admin/finance/overview?period=all&seller_id={$seller->id}")->json('data.delivery');
        $this->assertSame(1, $k['refused_parcels']);
        $this->assertSame(5000, self::m($k['refused_platform_loss']));
        $this->assertSame(-5000, self::m($k['platform_delivery_margin']));
        $this->assertSame(0, self::m($k['commission']));
        $this->assertSame(0, self::m($k['cash_collected']));
        $this->assertSame(0, self::m(Order::find($res['order_id'])->moneySummary()['total']));

        // Final: can't be delivered or remitted afterwards
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/delivered")->assertStatus(422);
        $this->confirmRemittance($so)->assertStatus(422);

        // Back at the seller: stock released
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/returned-to-seller")->assertOk();
        $this->assertSame(10, Product::find($product->id)->stock);
        $this->assertSame('returned_to_seller', Order::find($res['order_id'])->status);
    }

    public function test_14_refused_parcel_fee_billed_to_the_seller(): void
    {
        $this->setFees(8, 7, 6, ['refused_parcel_agency_fee' => 7000, 'refused_parcel_fee_paid_by' => 'seller']);
        $seller = $this->seller('free');
        $this->addToCart($this->product($seller, 50));
        $res = $this->checkout();
        $so  = $this->parcel($res['order_id'], $seller);

        $this->refuse($so)->assertOk();
        $adj = SellerAdjustment::where('seller_order_id', $so->id)->firstOrFail();
        $this->assertSame(SellerAdjustment::TYPE_REFUSED_PARCEL, $adj->type);
        $this->assertSame(-7000, self::m($adj->amount));
        $k = $this->as($this->admin)->getJson("/api/admin/finance/overview?period=all&seller_id={$seller->id}")->json('data.delivery');
        $this->assertSame(7000, self::m($k['refused_fees_billed_to_sellers']));
        $this->assertSame(0, self::m($k['refused_platform_loss']));

        // Deducted from the seller's next settlement
        $this->addToCart($this->product($seller, 100));
        $next = $this->parcel($this->checkout()['order_id'], $seller);
        $this->deliver($next);
        $this->confirmRemittance($next)->assertOk();
        $batch = $this->as($this->admin)->postJson('/api/admin/settlements/create', ['seller_id' => $seller->id, 'batch_date' => now()->toDateString()])->assertOk()->json('data');
        $this->assertSame(self::m($this->fresh($next)->seller_net_amount) - 7000, self::m($batch['total_seller_payout']));
    }

    public function test_cancelled_parcel_in_a_multi_seller_order_drops_its_delivery_fee(): void
    {
        $a = $this->seller('free');
        $b = $this->seller('free');
        $this->addToCart($this->product($a, 50));
        $this->addToCart($this->product($b, 30));
        $res = $this->checkout();
        $this->assertEquals(96.0, $res['total']);   // 58 + 38
        $pa = $this->parcel($res['order_id'], $a);
        $pb = $this->parcel($res['order_id'], $b);

        // Seller B cancels their parcel
        $this->as($b)->patchJson("/api/seller/orders/{$pb->id}/status", ['status' => 'cancelled'])->assertOk();

        $order = Order::find($res['order_id']);
        $money = $order->moneySummary();
        $this->assertSame(58000, self::m($money['total']));
        $this->assertSame(8000, self::m($money['shipping_fee']));

        $docs  = app(DeliveryDocumentService::class);
        $order = $docs->query()->findOrFail($res['order_id']);
        $slips = $docs->slipsFor($order);
        $this->assertCount(1, $slips);
        $this->assertSame(58000, self::m($slips[0]['money']['cod']));
        $this->assertSame(8000, self::m($slips[0]['money']['shipping']));
        $this->assertSame(self::m($pa->cod_amount), self::m($slips[0]['money']['cod']));

        // The client's view says the same
        $shown = $this->as($this->customer)->getJson("/api/client/orders/{$res['order_id']}")->assertOk()->json('data');
        $this->assertSame(58000, self::m($shown['amount_due']));
    }

    public function test_seller_cannot_mark_a_parcel_delivered(): void
    {
        $seller = $this->seller('free');
        $this->addToCart($this->product($seller, 50));
        $res = $this->checkout();
        $so  = $this->parcel($res['order_id'], $seller);

        foreach (['delivered', 'completed', 'refused', 'out_for_delivery', 'returned_to_seller'] as $status) {
            $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => $status])->assertStatus(422);
        }
        // Handing it to the courier is fine, once confirmed
        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'handed_to_courier'])->assertStatus(422);
        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'confirmed'])->assertOk()
            ->assertJsonPath('data.allowed_next', ['handed_to_courier', 'cancelled']);
        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'handed_to_courier'])->assertOk()
            ->assertJsonPath('data.allowed_next', []);
        $so = $this->fresh($so);
        $this->assertSame('handed_to_courier', $so->status);
        $this->assertNull($so->cash_collected_at);
        // Once the courier has it, the seller can't cancel it
        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'cancelled'])->assertStatus(422);

        // ...and once the admin marked it delivered, the seller can't change it any more
        $this->deliver($so);
        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'cancelled'])->assertStatus(422);
        $this->assertSame('delivered', $this->fresh($so)->status);

        // Only admins reach the parcel endpoints
        $this->as($seller)->postJson("/api/admin/seller-orders/{$so->id}/delivered")->assertStatus(403);
    }

    public function test_order_wide_delivered_stamps_cash_collection_and_skips_refused_parcels(): void
    {
        $a = $this->seller('free');
        $b = $this->seller('free');
        $this->addToCart($this->product($a, 50));
        $this->addToCart($this->product($b, 30));
        $res = $this->checkout();
        $pa = $this->parcel($res['order_id'], $a);
        $pb = $this->parcel($res['order_id'], $b);

        $this->refuse($pb)->assertOk();
        $this->ship($pa);
        $this->as($this->admin)->patchJson("/api/admin/orders/{$res['order_id']}/status", ['status' => 'delivered'])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/orders/{$res['order_id']}/status", ['status' => 'completed'])->assertStatus(422);

        $this->assertSame('delivered', $this->fresh($pa)->status);
        $this->assertNotNull($this->fresh($pa)->cash_collected_at);
        $this->assertSame('refused', $this->fresh($pb)->status);
        $this->assertSame('delivered', Order::find($res['order_id'])->status);
    }

    public function test_legacy_completed_parcel_still_counts_as_delivered_for_the_remittance(): void
    {
        $seller = $this->seller('free');
        $this->addToCart($this->product($seller, 50));
        $res = $this->checkout();
        $so  = $this->parcel($res['order_id'], $seller);
        DB::table('seller_orders')->where('id', $so->id)->update(['status' => 'completed', 'cash_collected_at' => now()]);

        $this->confirmRemittance($so)->assertOk();
        $this->assertSame('ready', $this->fresh($so)->payout_status);
    }
}
