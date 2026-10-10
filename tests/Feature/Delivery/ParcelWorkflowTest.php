<?php

namespace Tests\Feature\Delivery;

use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\SellerAdjustment;
use App\Models\SellerOrder;
use App\Services\Orders\IllegalTransition;
use App\Services\Orders\ParcelStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Three outcomes, never merged (App\Services\Orders\ParcelStatus):
 *   cancelled  never handed to the courier: no money at all, stock back now, hidden from finance
 *   refused    shipped, refused at the door: no payout / commission, agency fee per the admin
 *              setting, stock back only once returned to the seller
 *   refunded   paid then returned (ReturnFlowTest)
 * plus the transition rules and the status history the delivery agency API will feed.
 */
class ParcelWorkflowTest extends TestCase
{
    use DatabaseTransactions, DeliveryTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDelivery();
    }

    private function financeRows(int $sellerId, string $query = ''): \Illuminate\Support\Collection
    {
        return collect($this->as($this->admin)->getJson("/api/admin/finance/orders?seller_id={$sellerId}&per_page=100{$query}")->assertOk()->json('data.data'));
    }

    private function overview(int $sellerId): array
    {
        return $this->as($this->admin)->getJson("/api/admin/finance/overview?period=all&seller_id={$sellerId}")->assertOk()->json('data');
    }

    // ── Cancelled ───────────────────────────────────────────────────────────

    public function test_cancelled_before_shipping_is_hidden_from_finance_owes_nothing_and_gives_stock_back(): void
    {
        $seller  = $this->seller('free');
        $product = $this->product($seller, 50, false, 10);
        $this->addToCart($product, 3);
        $res = $this->checkout();
        $so  = $this->parcel($res['order_id'], $seller);
        $this->assertSame(7, Product::find($product->id)->stock);

        // Client cancels during the admin confirmation call
        $this->as($this->admin)->patchJson("/api/admin/orders/{$res['order_id']}/confirm-order", ['action' => 'cancelled'])->assertOk();

        $so = $this->fresh($so);
        $this->assertSame('cancelled', $so->status);
        $this->assertSame('cancelled', $so->payout_status);
        $this->assertSame(10, Product::find($product->id)->stock);
        $this->assertSame(0, self::m(Order::find($res['order_id'])->moneySummary()['total']));
        $this->assertFalse(SellerAdjustment::where('seller_order_id', $so->id)->exists(), 'no agency fee for a parcel never shipped');
        $this->assertNull($so->refused_agency_fee);

        // Hidden by default, shown (with 0 money) only on demand
        $this->assertNull($this->financeRows($seller->id)->firstWhere('id', $so->id));
        $row = $this->financeRows($seller->id, '&include_cancelled=1')->firstWhere('id', $so->id);
        $this->assertSame('cancelled', $row['status']);
        $this->assertSame('cancelled', $row['payout_status']);
        foreach (['subtotal', 'commission_amount', 'seller_net_amount', 'cod_amount', 'delivery_fee', 'shipping_cost', 'platform_profit'] as $col) {
            $this->assertSame(0, self::m($row[$col]), $col);
        }
        $this->assertCount(1, $this->financeRows($seller->id, '&status=cancelled'));

        // History: pending → cancelled, by the admin
        $h = DB::table('seller_order_status_history')->where('seller_order_id', $so->id)->get();
        $this->assertCount(1, $h);
        $this->assertSame(['pending', 'cancelled', 'admin', $this->admin->id], [$h[0]->from_status, $h[0]->to_status, $h[0]->source, (int) $h[0]->changed_by]);
    }

    public function test_seller_cancelling_before_the_courier_releases_stock_and_payout(): void
    {
        $seller  = $this->seller('free');
        $product = $this->product($seller, 40, false, 5);
        $this->addToCart($product, 2);
        $so = $this->parcel($this->checkout()['order_id'], $seller);

        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'cancelled'])->assertOk();

        $so = $this->fresh($so);
        $this->assertSame('cancelled', $so->payout_status);
        $this->assertSame(5, Product::find($product->id)->stock);
        $this->assertSame('seller', DB::table('seller_order_status_history')->where('seller_order_id', $so->id)->latest('id')->value('source'));
    }

    // ── Refused ─────────────────────────────────────────────────────────────

    /** @return array{0: object, 1: Product, 2: \App\Models\User, 3: Promotion} */
    private function shippedFlashParcel(): array
    {
        $seller  = $this->seller('free');
        $product = $this->product($seller, 50, false, 10);
        $promo   = $this->promotion($product, 'flash_sale', 10);
        $this->addToCart($product, 2);
        $so = $this->parcel($this->checkout()['order_id'], $seller);
        $this->ship($so);
        return [$so, $product, $seller, $promo];
    }

    private function assertNoSale(object $so, int $sellerId): void
    {
        $so = $this->fresh($so);
        $this->assertSame('cancelled', $so->payout_status, 'no payout');
        $row = $this->financeRows($sellerId)->firstWhere('id', $so->id);
        $this->assertNotNull($row, 'refused parcels stay visible in Finance › Orders');
        foreach (['subtotal', 'commission_amount', 'seller_net_amount', 'cod_amount', 'amount_to_remit', 'platform_profit'] as $col) {
            $this->assertSame(0, self::m($row[$col]), $col);
        }
        $this->as($this->admin)->postJson("/api/admin/finance/confirm-money/{$so->id}")->assertStatus(422);
        $this->assertSame(0, self::m(Order::find($so->order_id)->moneySummary()['total']));
    }

    public function test_refused_platform_pays_the_fee_stock_back_only_when_returned_to_seller(): void
    {
        $this->setFees(8, 7, 6, ['refused_parcel_agency_fee' => 7000, 'refused_parcel_fee_paid_by' => 'platform']);
        [$so, $product, $seller, $promo] = $this->shippedFlashParcel();
        $this->assertSame(8, Product::find($product->id)->stock);
        $this->assertSame(2, Promotion::find($promo->id)->flash_stock_used);

        $this->refuse($so)->assertOk();
        $this->assertNoSale($so, $seller->id);
        $this->assertSame('platform', $this->fresh($so)->refused_fee_paid_by);
        $this->assertFalse(SellerAdjustment::where('seller_order_id', $so->id)->exists());

        // Still with the courier: stock and flash units stay out
        $this->assertSame(8, Product::find($product->id)->stock);
        $this->assertSame(2, Promotion::find($promo->id)->flash_stock_used);

        $data = $this->overview($seller->id);
        $this->assertSame(1, $data['kpis']['refused_parcels']['count']);
        $this->assertSame(1, $data['kpis']['refused_parcels']['awaiting_return']);
        $this->assertSame(7000, self::m($data['kpis']['refused_parcels']['agency_fees_lost']));
        $this->assertSame(-7000, self::m($data['kpis']['total_platform_profit']), 'platform delivery loss');
        $this->assertSame(-7000, self::m($data['delivery']['platform_delivery_margin']));
        $this->assertSame(0, self::m($data['kpis']['total_commission']));
        $this->assertSame(0, self::m($data['kpis']['gross_revenue']));

        // Back at the seller: stock and flash units released, once
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/returned-to-seller")->assertOk();
        $this->assertSame(10, Product::find($product->id)->stock);
        $this->assertSame(0, Promotion::find($promo->id)->flash_stock_used);
        $so = $this->fresh($so);
        $this->assertSame('returned_to_seller', $so->status);
        $this->assertNotNull($so->returned_to_seller_at);
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/returned-to-seller")->assertStatus(422);
        $this->assertSame(10, Product::find($product->id)->stock);

        // Still a refused parcel for finance: same loss, no longer awaiting return
        $data = $this->overview($seller->id);
        $this->assertSame(1, $data['kpis']['refused_parcels']['count']);
        $this->assertSame(0, $data['kpis']['refused_parcels']['awaiting_return']);
        $this->assertSame(7000, self::m($data['kpis']['refused_parcels']['agency_fees_lost']));
        $this->assertSame('returned_to_seller', $this->financeRows($seller->id, '&status=refused')->firstWhere('id', $so->id)['status']);
        $this->assertNoSale($so, $seller->id);

        $steps = DB::table('seller_order_status_history')->where('seller_order_id', $so->id)->orderBy('id')->pluck('to_status')->all();
        $this->assertSame(['confirmed', 'out_for_delivery', 'refused', 'returned_to_seller'], $steps);
    }

    public function test_refused_seller_pays_the_fee_as_a_negative_adjustment(): void
    {
        $this->setFees(8, 7, 6, ['refused_parcel_agency_fee' => 5500, 'refused_parcel_fee_paid_by' => 'seller']);
        [$so, $product, $seller] = $this->shippedFlashParcel();

        $this->refuse($so)->assertOk();
        $this->assertNoSale($so, $seller->id);

        $adj = SellerAdjustment::where('seller_order_id', $so->id)->sole();
        $this->assertSame(SellerAdjustment::TYPE_REFUSED_PARCEL, $adj->type);
        $this->assertSame(-5500, self::m($adj->amount));
        $this->assertNull($adj->applied_at, 'deducted on the next settlement');
        $this->assertSame(8, Product::find($product->id)->stock);

        $data = $this->overview($seller->id);
        $this->assertSame(0, self::m($data['kpis']['refused_parcels']['agency_fees_lost']));
        $this->assertSame(5500, self::m($data['kpis']['refused_parcels']['agency_fees_billed']));
        $this->assertSame(0, self::m($data['kpis']['total_platform_profit']));

        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/returned-to-seller")->assertOk();
        $this->assertSame(10, Product::find($product->id)->stock);
        $this->assertSame(1, SellerAdjustment::where('seller_order_id', $so->id)->count(), 'billed once');
    }

    public function test_refused_with_a_zero_fee_costs_nobody_anything(): void
    {
        $this->setFees(8, 7, 6, ['refused_parcel_agency_fee' => 0, 'refused_parcel_fee_paid_by' => 'seller']);
        [$so, $product, $seller] = $this->shippedFlashParcel();

        $this->refuse($so)->assertOk();
        $this->assertNoSale($so, $seller->id);
        $this->assertFalse(SellerAdjustment::where('seller_order_id', $so->id)->exists());
        $this->assertSame(0, self::m($this->fresh($so)->refused_agency_fee));

        $data = $this->overview($seller->id);
        $this->assertSame(1, $data['kpis']['refused_parcels']['count']);
        $this->assertSame(0, self::m($data['kpis']['refused_parcels']['agency_fees_lost']));
        $this->assertSame(0, self::m($data['kpis']['refused_parcels']['agency_fees_billed']));
        $this->assertSame(0, self::m($data['kpis']['total_platform_profit']));
        $this->assertSame(8, Product::find($product->id)->stock);
    }

    // ── Transition rules ───────────────────────────────────────────────────

    public function test_illegal_transitions_are_rejected(): void
    {
        $seller  = $this->seller('free');
        $product = $this->product($seller, 50, false, 10);
        $this->addToCart($product);
        $res = $this->checkout();
        $so  = $this->parcel($res['order_id'], $seller);
        $admin = fn (string $to) => $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/status", ['status' => $to]);

        // Before shipping: no refusal, no delivery, no return
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/refused")->assertStatus(422);
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/delivered")->assertStatus(422);
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/returned-to-seller")->assertStatus(422);
        $this->as($this->admin)->patchJson("/api/admin/orders/{$res['order_id']}/status", ['status' => 'delivered'])->assertStatus(422);

        // Handed to the courier: no cancel any more, no delivery before shipping
        $admin('confirmed')->assertOk();
        $admin('handed_to_courier')->assertOk();
        $admin('cancelled')->assertStatus(422)->assertJsonFragment(['message' => "Parcel #{$so->id} is handed_to_courier and can't become cancelled: only a parcel not yet handed to the courier can be cancelled."]);
        $admin('delivered')->assertStatus(422);
        $admin('refused')->assertStatus(422);

        // Shipped: no cancel (admin parcel, admin order-wide, seller), no going back
        $admin('out_for_delivery')->assertOk();
        $admin('cancelled')->assertStatus(422);
        $this->as($this->admin)->patchJson("/api/admin/orders/{$res['order_id']}/status", ['status' => 'cancelled'])->assertStatus(422);
        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'cancelled'])->assertStatus(422);
        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'pending'])->assertStatus(422);
        $admin('confirmed')->assertStatus(422);

        $this->assertSame('out_for_delivery', $this->fresh($so)->status);
        $this->assertSame(9, Product::find($product->id)->stock, 'nothing released by a rejected move');
        $this->assertSame('pending', $this->fresh($so)->payout_status);

        // Delivered is final but for a return
        $admin('delivered')->assertOk();
        foreach (['refused', 'cancelled', 'returned_to_seller', 'out_for_delivery'] as $to) {
            $admin($to)->assertStatus(422);
        }

        // Rules themselves
        $this->assertFalse(ParcelStatus::canTransition('pending', 'refused'));
        $this->assertFalse(ParcelStatus::canTransition('out_for_delivery', 'cancelled'));
        $this->assertFalse(ParcelStatus::canTransition('confirmed', 'delivered'));
        $this->assertTrue(ParcelStatus::canTransition('cancelled', 'confirmed'));
        $this->expectException(IllegalTransition::class);
        app(ParcelStatus::class)->transition(SellerOrder::find($so->id), 'cancelled', ['source' => 'api']);
    }

    public function test_order_wide_cancel_skips_closed_parcels_and_rejects_shipped_ones(): void
    {
        $a = $this->seller('free');
        $b = $this->seller('free');
        $this->addToCart($this->product($a, 50));
        $this->addToCart($this->product($b, 30));
        $res = $this->checkout();
        $pa = $this->parcel($res['order_id'], $a);
        $pb = $this->parcel($res['order_id'], $b);

        $this->as($b)->patchJson("/api/seller/orders/{$pb->id}/status", ['status' => 'cancelled'])->assertOk();
        // Confirming the order leaves B's cancellation alone
        $this->as($this->admin)->patchJson("/api/admin/orders/{$res['order_id']}/confirm-order", ['action' => 'confirmed'])->assertOk();
        $this->assertSame('confirmed', $this->fresh($pa)->status);
        $this->assertSame('cancelled', $this->fresh($pb)->status);
        $this->assertSame('confirmed', Order::find($res['order_id'])->status);

        $this->ship($pa);
        $this->as($this->admin)->patchJson("/api/admin/orders/{$res['order_id']}/status", ['status' => 'cancelled'])->assertStatus(422);
        $this->assertSame('out_for_delivery', $this->fresh($pa)->status);
    }

    // ── Finance totals ─────────────────────────────────────────────────────

    public function test_finance_totals_ignore_cancelled_and_count_refused_apart(): void
    {
        $this->setFees(8, 7, 6, ['refused_parcel_agency_fee' => 7000, 'refused_parcel_fee_paid_by' => 'platform']);
        $seller = $this->seller('free');

        $orders = [];
        foreach (['delivered', 'cancelled', 'refused'] as $outcome) {
            $this->addToCart($this->product($seller, 50));
            $orders[$outcome] = $this->parcel($this->checkout()['order_id'], $seller);
        }
        $this->deliver($orders['delivered']);
        $this->confirmRemittance($orders['delivered'])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/orders/{$orders['cancelled']->order_id}/confirm-order", ['action' => 'cancelled'])->assertOk();
        $this->refuse($orders['refused'])->assertOk();

        $delivered = $this->fresh($orders['delivered']);
        $data      = $this->overview($seller->id);
        $k         = $data['kpis'];

        // Only the delivered parcel is a sale
        $this->assertSame(1, $k['orders_count']);
        $this->assertSame(50000, self::m($k['gross_revenue']));
        $this->assertSame(self::m($delivered->commission_amount), self::m($k['total_commission']));
        $this->assertSame(self::m($delivered->seller_net_amount), self::m($k['total_seller_payouts']));
        $this->assertSame(self::m($delivered->platform_profit) - 7000, self::m($k['total_platform_profit']));
        $this->assertSame(self::m($delivered->platform_profit), self::m($k['platform_profit_before_refusals']));
        $this->assertSame(1, $data['payout_summary']['ready']['count']);
        $this->assertSame(0, $data['payout_summary']['pending']['count'], 'cancelled / refused payouts are not pending');
        $this->assertSame(1, $data['delivery']['delivered_parcels']);
        $this->assertSame(self::m($delivered->cod_amount), self::m($data['delivery']['cash_collected']));
        $this->assertSame(0, self::m($data['delivery']['reconciliation']['difference']));
        $this->assertSame(1, $k['refused_parcels']['count']);
        $this->assertSame(7000, self::m($k['refused_parcels']['agency_fees_lost']));

        // Finance › Orders: cancelled hidden, refused shown with its own status
        $rows = $this->financeRows($seller->id);
        $this->assertEqualsCanonicalizing([$orders['delivered']->id, $orders['refused']->id], $rows->pluck('id')->all());
        $this->assertSame('refused', $rows->firstWhere('id', $orders['refused']->id)['status']);
        $this->assertSame('cancelled', $rows->firstWhere('id', $orders['refused']->id)['payout_status']);
        $this->assertCount(3, $this->financeRows($seller->id, '&include_cancelled=1'));

        // Sellers summary and the seller's own earnings ignore both
        $sellerRow = collect($this->as($this->admin)->getJson("/api/admin/finance/sellers?seller_id={$seller->id}")->json('data.data'))->first();
        $this->assertSame(1, (int) $sellerRow['orders_count']);
        $this->assertSame(self::m($delivered->seller_net_amount), self::m($sellerRow['total_net']));
        $earn = collect($this->as($seller)->getJson('/api/seller/earnings/orders')->assertOk()->json('data.data'));
        $this->assertSame('none', $earn->firstWhere('id', $orders['refused']->id)['payout_stage']);
        $this->assertSame('none', $earn->firstWhere('id', $orders['cancelled']->id)['payout_stage']);
    }

    // ── Re-opening a cancelled parcel ──────────────────────────────────────

    /** @return array{0: object, 1: Product, 2: Promotion, 3: \App\Models\User} cancelled parcel: 2 units, flash-priced */
    private function cancelledFlashParcel(): array
    {
        $seller  = $this->seller('free');
        $product = $this->product($seller, 50, false, 10);
        $promo   = $this->promotion($product, 'flash_sale', 10);   // quota 20
        $this->addToCart($product, 2);
        $so = $this->parcel($this->checkout()['order_id'], $seller);
        $this->as($this->admin)->patchJson("/api/admin/orders/{$so->order_id}/confirm-order", ['action' => 'cancelled'])->assertOk();
        $this->assertSame(10, Product::find($product->id)->stock);
        $this->assertSame(0, Promotion::find($promo->id)->flash_stock_used);
        $this->assertSame('cancelled', $this->fresh($so)->payout_status);
        return [$so, $product, $promo, $seller];
    }

    public function test_reopening_reserves_stock_and_flash_units_again_and_payout_waits_for_cash(): void
    {
        [$so, $product, $promo, $seller] = $this->cancelledFlashParcel();

        // Sellers can't re-open; the admin can
        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'confirmed'])->assertStatus(422);
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/status", ['status' => 'confirmed'])->assertOk();

        $fresh = $this->fresh($so);
        $this->assertSame('confirmed', $fresh->status);
        $this->assertSame('pending', $fresh->payout_status, 'waits for cash again, never paid');
        $this->assertSame(8, Product::find($product->id)->stock);
        $this->assertSame(2, Promotion::find($promo->id)->flash_stock_used);
        $this->assertSame(2, (int) DB::table('order_items')->where('seller_order_id', $so->id)->value('flash_reserved'));

        // Cancelled once more: everything given back once more
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(10, Product::find($product->id)->stock);
        $this->assertSame(0, Promotion::find($promo->id)->flash_stock_used);
        $this->assertSame('cancelled', $this->fresh($so)->payout_status);
    }

    public function test_reopening_is_rejected_when_the_stock_was_sold_meanwhile(): void
    {
        [$so, $product, $promo] = $this->cancelledFlashParcel();
        Product::whereKey($product->id)->update(['stock' => 1]);

        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/status", ['status' => 'confirmed'])
            ->assertStatus(422)
            ->assertJsonPath('message', "Parcel #{$so->id} can't be re-opened: only 1 left of {$product->name}. Nothing was reserved.");
        $this->as($this->admin)->patchJson("/api/admin/orders/{$so->order_id}/status", ['status' => 'pending'])->assertStatus(422);

        $fresh = $this->fresh($so);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('cancelled', $fresh->payout_status);
        $this->assertSame(1, Product::find($product->id)->stock);
        $this->assertSame(0, Promotion::find($promo->id)->flash_stock_used);
    }

    public function test_reopening_is_rejected_when_the_flash_sale_sold_out_meanwhile(): void
    {
        [$so, $product, $promo] = $this->cancelledFlashParcel();
        Promotion::whereKey($promo->id)->update(['flash_stock_used' => 19]);   // 1 unit left, the parcel needs 2

        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/status", ['status' => 'confirmed'])
            ->assertStatus(422)
            ->assertJsonPath('message', "Parcel #{$so->id} can't be re-opened: the flash sale on {$product->name} is sold out. Nothing was reserved.");

        // Rolled back: the stock taken first is back on the shelf
        $this->assertSame('cancelled', $this->fresh($so)->status);
        $this->assertSame('cancelled', $this->fresh($so)->payout_status);
        $this->assertSame(10, Product::find($product->id)->stock);
        $this->assertSame(19, Promotion::find($promo->id)->flash_stock_used);
        $this->assertSame(0, (int) DB::table('order_items')->where('seller_order_id', $so->id)->value('flash_reserved'));
        $this->assertSame(0, DB::table('seller_order_status_history')->where('seller_order_id', $so->id)->where('to_status', 'confirmed')->count());
    }

    // ── Settled payouts are frozen ─────────────────────────────────────────

    public function test_a_parcel_in_a_settlement_batch_can_no_longer_change_status(): void
    {
        $seller = $this->seller('free');
        $this->addToCart($this->product($seller, 50));
        $so = $this->parcel($this->checkout()['order_id'], $seller);
        $this->deliver($so);
        $this->confirmRemittance($so)->assertOk();
        $this->assertSame(['refunded'], ParcelStatus::allowedNext(SellerOrder::find($so->id)));

        $batch = $this->as($this->admin)->postJson('/api/admin/settlements/create', ['seller_id' => $seller->id, 'batch_date' => now()->toDateString()])->assertOk()->json('data');
        foreach (['draft' => null, 'paid' => fn () => $this->as($this->admin)->postJson("/api/admin/settlements/{$batch['id']}/confirm")->assertOk()] as $state => $step) {
            $step && $step();
            $this->assertSame($state, DB::table('settlement_batches')->where('id', $batch['id'])->value('status'));
            $this->assertSame([], ParcelStatus::allowedNext(SellerOrder::find($so->id)), "batch {$state}");
            $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/status", ['status' => 'refunded'])
                ->assertStatus(422)->assertJsonFragment(['message' => "Parcel #{$so->id} can't become refunded: its payout is already settled ({$batch['batch_reference']}, {$state}). A return goes through the return flow."]);
            $this->as($this->admin)->getJson("/api/admin/orders/{$so->order_id}")->assertOk()->assertJsonPath('data.seller_orders.0.allowed_next', []);
        }
        $this->assertSame('delivered', $this->fresh($so)->status);
        $this->assertSame('paid', $this->fresh($so)->payout_status);
    }

    /** ORD-KAK2QQIZ's shape: cancelled after its payout was paid in a batch. */
    public function test_a_cancelled_parcel_whose_payout_was_paid_cannot_be_reopened(): void
    {
        $seller = $this->seller('free');
        $this->addToCart($this->product($seller, 50));
        $live = $this->parcel($this->checkout()['order_id'], $seller);
        $this->deliver($live);
        $this->confirmRemittance($live)->assertOk();
        $batch = $this->as($this->admin)->postJson('/api/admin/settlements/create', ['seller_id' => $seller->id, 'batch_date' => now()->toDateString()])->assertOk()->json('data');

        $product = $this->product($seller, 40, false, 5);
        $this->addToCart($product);
        $so = $this->parcel($this->checkout()['order_id'], $seller);
        $this->as($this->admin)->patchJson("/api/admin/orders/{$so->order_id}/confirm-order", ['action' => 'cancelled'])->assertOk();
        DB::table('seller_orders')->where('id', $so->id)->update(['settlement_batch_id' => $batch['id'], 'payout_status' => 'paid']);

        foreach (['draft', 'confirmed', 'paid'] as $state) {
            DB::table('settlement_batches')->where('id', $batch['id'])->update(['status' => $state]);
            $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/status", ['status' => 'confirmed'])->assertStatus(422);
            $this->as($this->admin)->patchJson("/api/admin/orders/{$so->order_id}/status", ['status' => 'pending'])->assertStatus(422);
        }
        // Paid outside any live batch: still frozen
        DB::table('settlement_batches')->where('id', $batch['id'])->update(['status' => 'cancelled']);
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/status", ['status' => 'confirmed'])->assertStatus(422);

        $this->expectException(IllegalTransition::class);
        try {
            app(ParcelStatus::class)->transition($so->id, 'confirmed', ['source' => 'api']);
        } finally {
            $this->assertSame('cancelled', $this->fresh($so)->status);
            $this->assertSame('paid', $this->fresh($so)->payout_status);
            $this->assertSame(5, Product::find($product->id)->stock, 'nothing reserved');
        }
    }

    // ── Delivery agency (future API) ───────────────────────────────────────

    public function test_carrier_updates_are_recorded_and_mapped_through_the_workflow(): void
    {
        $seller = $this->seller('free');
        $this->addToCart($this->product($seller, 50));
        $so = SellerOrder::find($this->parcel($this->checkout()['order_id'], $seller)->id);
        $parcels = app(ParcelStatus::class);

        // No mapping yet: the raw code and tracking number are only recorded
        $so = $parcels->carrierUpdate($so, 'PICKED_UP_FROM_SHIPPER', 'TRK-123');
        $this->assertSame('pending', $so->status);
        $this->assertSame('TRK-123', $so->carrier_tracking_number);
        $this->assertSame('PICKED_UP_FROM_SHIPPER', $so->carrier_status_raw);
        $this->assertNotNull($so->carrier_status_at);

        // An api move goes through the same rules and lands in the history
        $parcels->transition($so, 'confirmed', ['by' => $this->admin, 'source' => 'admin']);
        $parcels->transition($so, 'handed_to_courier', ['source' => 'api', 'carrier_status_raw' => 'PICKED_UP_FROM_SHIPPER']);
        $h = DB::table('seller_order_status_history')->where('seller_order_id', $so->id)->orderByDesc('id')->first();
        $this->assertSame(['confirmed', 'handed_to_courier', 'api', null, 'PICKED_UP_FROM_SHIPPER'], [$h->from_status, $h->to_status, $h->source, $h->changed_by, $h->carrier_status_raw]);
        $this->assertSame('handed_to_courier', Order::find($so->order_id)->status);

        $history = $this->as($this->admin)->getJson("/api/admin/seller-orders/{$so->id}/status-history")->assertOk()->json('data');
        $this->assertSame(['out_for_delivery'], $history['allowed_next']);
        $this->assertSame('TRK-123', $history['carrier_tracking_number']);
        $this->assertCount(2, $history['history']);
        $this->assertSame($this->admin->id, $history['history'][0]['changed_by']['id']);
    }
}
