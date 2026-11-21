<?php

namespace Tests\Feature\Commission;

use App\Models\Complaint;
use App\Models\OrderItem;
use App\Models\ProductCommissionOverride as Override;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Models\SellerSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\ProductCommissionOverrideNotification as Notice;
use App\Services\CommissionService;
use App\Services\ProductCommissionOverrideService;
use App\Services\Returns\ReturnService;
use App\Services\Returns\Settlements;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Discounts\DiscountTestHelpers;
use Tests\TestCase;

/**
 * Product special commission (admin incentive): the rule, the order snapshot, every discount
 * kind, refunds, settlements, validation, previews, notifications, expiry.
 *
 * Grid pinned by setUpDiscounts(): ≤100 → 15 %, 100.01–200 → 12 %, >200 → 10 %, floor 3;
 * plans: free −0, red −3, black −6. Admin delivery: client 8, agency 7.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Commission/ProductCommissionOverrideTest.php
 */
class ProductCommissionOverrideTest extends TestCase
{
    use DatabaseTransactions, DiscountTestHelpers;

    private ProductCommissionOverrideService $svc;
    private CommissionService $rates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDiscounts();
        SubscriptionPlan::flushCache();
        $this->svc   = app(ProductCommissionOverrideService::class);
        $this->rates = app(CommissionService::class);
    }

    protected function tearDown(): void
    {
        SubscriptionPlan::flushCache();   // the rolled-back plan edit must not stay in the static plan cache
        parent::tearDown();
    }

    private function ovr(Product $p, string $type, $value, array $extra = []): Override
    {
        return $this->svc->create(['product_id' => $p->id, 'type' => $type, 'value' => $value] + $extra, $this->admin);
    }

    private function rate(Product $p, float $price, ?Carbon $at = null): array
    {
        return $this->rates->rateFor($p->id, $p->seller_id, $price, $at);
    }

    // ── The rule ───────────────────────────────────────────────────────────────

    public function test_fixed_rate_is_the_final_rate_and_no_plan_reduction_is_added_on_top(): void
    {
        $seller = $this->seller('red');                       // red: −3 → normal 9 % at 150
        $p      = $this->product($seller, 150);
        $this->assertSame(9.0, $this->rate($p, 150)['rate']);

        $this->ovr($p, 'fixed_rate', 5);
        $r = $this->rate($p, 150);
        $this->assertSame(5.0, $r['rate']);
        $this->assertSame('product_override', $r['source']);
        $this->assertSame(9.0, $r['normal_rate']);

        $calc = $this->rates->calculateForSeller($seller->id, 150.0, 1, 0.0, null, 0, $p->id);
        $this->assertMoney('7.500', $calc['commission_amount']);
        $this->assertMoney('142.500', $calc['seller_amount']);
    }

    public function test_reduction_points_subtract_from_the_rate_that_would_otherwise_apply(): void
    {
        $red = $this->seller('red');
        $p   = $this->product($red, 150);                      // 12 − 3 = 9, then −4 = 5
        $this->ovr($p, 'reduction_points', 4);
        $this->assertSame(5.0, $this->rate($p, 150)['rate']);

        $green = $this->seller('free');
        $q     = $this->product($green, 80);                   // 15 − 4 = 11
        $this->ovr($q, 'reduction_points', 4);
        $this->assertSame(11.0, $this->rate($q, 80)['rate']);
    }

    public function test_an_override_may_go_below_the_platform_floor_and_the_admin_preview_warns(): void
    {
        $red = $this->seller('red');
        $p   = $this->product($red, 150);                      // normal 9, floor 3
        $this->ovr($p, 'reduction_points', 8);                 // 1 % < floor
        $this->assertSame(1.0, $this->rate($p, 150)['rate']);

        $q = $this->product($this->seller('red'), 150);
        $preview = $this->as($this->admin)->postJson('/api/admin/special-commissions/preview', [
            'product_ids' => [$q->id], 'type' => 'reduction_points', 'value' => 8,
        ])->assertOk()->json('data.0');
        $this->assertContains('below_floor', $preview['warnings']);
        $this->assertSame(1.0, (float) $preview['proposed']['rate']);
    }

    public function test_zero_percent_and_clamping(): void
    {
        $seller = $this->seller('free');
        $zero   = $this->product($seller, 150);
        $this->ovr($zero, 'fixed_rate', 0);
        $calc = $this->rates->calculateForSeller($seller->id, 150.0, 1, 0.0, null, 0, $zero->id);
        $this->assertSame(0.0, $calc['commission_percentage']);
        $this->assertMoney('0', $calc['commission_amount']);
        $this->assertMoney('150.000', $calc['seller_amount']);

        // A reduction larger than the rate never goes negative
        $big = $this->product($seller, 50);
        $this->ovr($big, 'reduction_points', 20);              // 15 − 20
        $this->assertSame(0.0, $this->rate($big, 50)['rate']);

        $hundred = $this->product($seller, 50);
        $this->ovr($hundred, 'fixed_rate', 100);
        $this->assertSame(100.0, $this->rate($hundred, 50)['rate']);
    }

    public function test_precedence_product_over_seller_override_over_flat_plan_over_bracket(): void
    {
        $seller = $this->seller('red');
        $p      = $this->product($seller, 150);
        $sub = SellerSubscription::create([
            'seller_application_id' => SellerApplication::where('user_id', $seller->id)->value('id'),
            'user_id' => $seller->id, 'current_plan' => 'red', 'status' => 'active',
            'commission_override' => 8, 'commission_override_expires_at' => now()->addDay(),
        ]);
        $this->assertSame(8.0, $this->rate($p, 150)['normal_rate']);
        $this->assertSame('override', $this->rate($p, 150)['source'], "seller-level keeps its own 'override' meaning");

        $o = $this->ovr($p, 'reduction_points', 3);            // 8 (seller override) − 3
        $this->assertSame(5.0, $this->rate($p, 150)['rate']);
        $this->svc->update($o, ['type' => 'fixed_rate', 'value' => 2], $this->admin);
        $this->assertSame(2.0, $this->rate($p, 150)['rate'], 'fixed replaces the seller override');

        // A flat plan rate (no seller override): reduction applies to it
        $sub->update(['commission_override' => null]);
        SubscriptionPlan::where('slug', 'red')->update(['commission_rate' => 7]); SubscriptionPlan::flushCache();
        $this->svc->update($o, ['type' => 'reduction_points', 'value' => 2], $this->admin);
        $this->assertSame(5.0, $this->rate($p, 150)['rate']);
    }

    public function test_scheduled_expired_and_mid_day_ending_are_decided_by_the_date_not_by_cron(): void
    {
        $seller = $this->seller('free');
        $now    = Carbon::parse('2026-12-01 10:00:00');

        $scheduled = $this->product($seller, 150);
        $this->svc->create(['product_id' => $scheduled->id, 'type' => 'fixed_rate', 'value' => 5, 'starts_at' => $now->copy()->addHours(2)], $this->admin);
        $this->assertSame(12.0, $this->rate($scheduled, 150, $now)['rate'], 'not started yet');
        $this->assertSame(5.0, $this->rate($scheduled, 150, $now->copy()->addHours(2))['rate'], 'starts_at <= at');

        $midday = $this->product($seller, 150);
        $o = $this->ovr($midday, 'fixed_rate', 5);
        $o->forceFill(['ends_at' => Carbon::parse('2026-12-01 12:00:00'), 'starts_at' => Carbon::parse('2026-12-01 08:00:00')])->save();
        $this->assertSame(5.0, $this->rate($midday, 150, Carbon::parse('2026-12-01 11:59:59'))['rate']);
        $this->assertSame(12.0, $this->rate($midday, 150, Carbon::parse('2026-12-01 12:00:00'))['rate'], 'at < ends_at, not <=');

        // Over but the cron has not run: is_active is still 1, the rate is already normal
        $expired = $this->product($seller, 150);
        $e = $this->ovr($expired, 'fixed_rate', 5);
        $e->forceFill(['starts_at' => now()->subDays(3), 'ends_at' => now()->subHour()])->save();
        $this->assertTrue((bool) $e->fresh()->is_active);
        $this->assertSame(12.0, $this->rate($expired, 150)['rate']);
    }

    // ── Order-time snapshot ────────────────────────────────────────────────────

    public function test_order_snapshots_the_override_and_deleting_it_never_changes_the_order(): void
    {
        $seller = $this->seller('red');
        $p      = $this->product($seller, 150);                // normal 9
        $o      = $this->ovr($p, 'fixed_rate', 5, ['note' => 'launch incentive']);
        $this->addToCart($p, 2);

        $res  = $this->place();
        $line = $this->line($res['order_id'], $p);
        $this->assertEquals(5, (float) $line->commission_percentage);
        $this->assertSame('product_override', $line->commission_source);
        $this->assertSame($o->id, (int) $line->commission_override_id);
        $this->assertEquals(9, (float) $line->commission_normal_rate);
        $this->assertMoney('15.000', $line->commission_amount);          // 5 % × 300
        $this->assertMoney('285.000', $line->seller_amount);

        $so = $this->parcel($res['order_id'], $seller);
        $this->assertSame('product_override', $so->commission_source);
        $this->assertEquals(5.0, (float) $so->commission_rate);
        $this->assertParcel($so, ['items' => '300', 'cod' => '308', 'commission' => '15', 'payout' => '285']);

        // Edit, end, delete, expire: the order keeps its snapshot
        $this->svc->update($o, ['value' => 1], $this->admin);
        $this->svc->endNow($o->fresh(), $this->admin);
        $this->svc->delete($o->fresh(), $this->admin);
        $again = $this->line($res['order_id'], $p);
        $this->assertSame($line->commission_amount, $again->commission_amount);
        $this->assertSame($line->seller_amount, $again->seller_amount);
        $this->assertSame($line->commission_override_id, $again->commission_override_id);
        $this->assertSame($so->commission_amount, $this->parcel($res['order_id'], $seller)->commission_amount);
        $this->assertNotNull(Override::withTrashed()->find($o->id), 'soft deleted: the id on the order still resolves');

        // The next order is back to the normal rate
        $this->addToCart($p, 1);
        $next = $this->line($this->place()['order_id'], $p);
        $this->assertEquals(9, (float) $next->commission_percentage);
        $this->assertNull($next->commission_override_id);
        $this->assertSame('plan', $next->commission_source);
    }

    public function test_seller_promotion_flash_sale_and_coupon_keep_the_same_base_with_an_override(): void
    {
        $seller = $this->seller('free');

        // Promotion: bracket from the ORIGINAL 120 (12 %), override replaces the rate, applied to the paid 96
        $promo = $this->product($seller, 120);
        $this->promo([$promo], 'discount', 20);
        $this->ovr($promo, 'fixed_rate', 5);
        $this->addToCart($promo);
        $l = $this->line($this->place()['order_id'], $promo);
        $this->assertMoney('96.000', $l->net_total);
        $this->assertMoney('4.800', $l->commission_amount);
        $this->assertMoney('91.200', $l->seller_amount);
        $this->assertMoney('120.000', $l->commission_tier_price);

        // Flash sale 50 % on 40: rate × 20 = 20 × 15 % − 4 points = 11 %
        $flash = $this->product($seller, 40);
        $this->promo([$flash], 'flash_sale', 50);
        $this->ovr($flash, 'reduction_points', 4);
        $this->addToCart($flash);
        $l = $this->line($this->place()['order_id'], $flash);
        $this->assertEquals(11, (float) $l->commission_percentage);
        $this->assertMoney('2.200', $l->commission_amount);
        $this->assertMoney('17.800', $l->seller_amount);

        // Coupon 10 % on 100: base is the 90 paid
        $couponed = $this->product($seller, 100);
        $this->coupon($seller, [$couponed], 'percentage', 10);
        $this->ovr($couponed, 'fixed_rate', 5);
        $this->addToCart($couponed);
        $code = \App\Models\Coupon::where('seller_id', $seller->id)->latest('id')->value('code');
        $res = $this->place(['coupon_codes' => [$code]]);
        $l = $this->line($res['order_id'], $couponed);
        $this->assertMoney('90.000', $l->net_total);
        $this->assertMoney('4.500', $l->commission_amount);
        $this->assertMoney('85.500', $l->seller_amount);
    }

    public function test_platform_funded_promotion_with_an_override_pays_the_seller_on_the_original_price_at_the_override_rate(): void
    {
        $seller  = $this->seller('free');
        $product = $this->product($seller, 120);
        $this->as($this->admin)->postJson('/api/admin/promotions', [
            'name' => 'BF override', 'type' => 'discount', 'discount_type' => 'percentage', 'discount_value' => 20,
            'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addDays(3)->toIso8601String(),
            'product_ids' => [$product->id], 'funded_by' => 'platform',
        ])->assertCreated();
        $this->ovr($product, 'fixed_rate', 5);

        $this->addToCart($product);
        $res  = $this->place();
        $line = $this->line($res['order_id'], $product);

        // Customer pays 96. Seller is paid as if sold at 120: 120 − 5 % × 120 = 114.000.
        // The platform keeps 96 − 114 = −18.000 (it also pays the 24 discount): charged commission = −18 + 24 = 6 = 5 % × 120
        $this->assertMoney('24.000', $line->platform_funded_amount);
        $this->assertMoney('96.000', $line->net_total);
        $this->assertMoney('114.000', $line->seller_amount);
        $this->assertMoney('-18.000', $line->commission_amount);
        $this->assertEquals(5, (float) $line->commission_percentage);
        $this->assertSame('product_override', $line->commission_source);

        $so = $this->parcel($res['order_id'], $seller);
        $this->assertParcel($so, ['items' => '96', 'cod' => '104', 'commission' => '-18', 'payout' => '114', 'agency' => '7', 'margin' => '1']);
        $detail = $this->as($seller)->getJson("/api/seller/orders/{$so->id}")->assertOk()->json('data');
        $this->assertEquals(6.0, $detail['commission']['total_commission_amount'], 'charged commission, on the original price');
        $this->assertTrue($detail['items'][0]['special_offer']);
    }

    public function test_multi_seller_order_only_the_overridden_seller_gets_the_special_rate(): void
    {
        $s1 = $this->seller('free');
        $s2 = $this->seller('free');
        $a  = $this->product($s1, 100);
        $b  = $this->product($s2, 100);
        $o  = $this->ovr($a, 'fixed_rate', 5);
        $this->addToCart($a);
        $this->addToCart($b);

        $res = $this->place();
        $la = $this->line($res['order_id'], $a);
        $lb = $this->line($res['order_id'], $b);
        $this->assertEquals(5, (float) $la->commission_percentage);
        $this->assertSame($o->id, (int) $la->commission_override_id);
        $this->assertEquals(15, (float) $lb->commission_percentage);
        $this->assertNull($lb->commission_override_id);

        $p1 = $this->parcel($res['order_id'], $s1);
        $p2 = $this->parcel($res['order_id'], $s2);
        $this->assertMoney('5.000', $p1->commission_amount);
        $this->assertSame('product_override', $p1->commission_source);
        $this->assertMoney('15.000', $p2->commission_amount);
        $this->assertNotSame('product_override', $p2->commission_source);
    }

    public function test_pack_with_one_overridden_and_one_normal_product_and_the_preview_equals_checkout(): void
    {
        $seller = $this->seller('free');
        $a = $this->product($seller, 30);                      // overridden
        $b = $this->product($seller, 20);                      // normal
        $bundle = $this->pack($seller, 50, [[$a, 1], [$b, 2]]);
        $this->ovr($a, 'fixed_rate', 5);

        // What the pack form shows (backend preview)…
        $preview = $this->as($seller)->postJson('/api/seller/commission/pack-preview', [
            'pack_price' => 50, 'quantity' => 2,
            'items' => [['product_id' => $a->id, 'quantity' => 1], ['product_id' => $b->id, 'quantity' => 2]],
        ])->assertOk()->json('data');
        $this->assertTrue($preview['special_offer']);

        // …is exactly what checkout calculates, line by line
        $this->addPack($bundle, 2);
        $res = $this->place();
        $la = $this->line($res['order_id'], $a);
        $lb = $this->line($res['order_id'], $b);
        $byProduct = collect($preview['lines'])->keyBy('product_id');

        $this->assertMoney($byProduct[$a->id]['commission'], $la->commission_amount);
        $this->assertMoney($byProduct[$a->id]['seller_amount'], $la->seller_amount);
        $this->assertEquals($byProduct[$a->id]['rate'], (float) $la->commission_percentage);
        $this->assertMoney($byProduct[$b->id]['commission'], $lb->commission_amount);
        $this->assertMoney($byProduct[$b->id]['seller_amount'], $lb->seller_amount);
        $this->assertEquals($byProduct[$b->id]['rate'], (float) $lb->commission_percentage);
        $this->assertEquals(5, (float) $la->commission_percentage);
        $this->assertEquals(15, (float) $lb->commission_percentage);
        $this->assertSame('product_override', $la->commission_source);
        $this->assertNull($lb->commission_override_id);

        $so = $this->parcel($res['order_id'], $seller);
        $this->assertMoney($preview['commission_amount'], $so->commission_amount);
        $this->assertMoney($preview['seller_amount'], $so->seller_net_amount);
        $this->assertMoney($preview['pack_price'], 100);
    }

    public function test_single_product_form_preview_equals_checkout_and_follows_the_edited_price(): void
    {
        $seller = $this->seller('free');
        $p      = $this->product($seller, 150);
        $this->ovr($p, 'reduction_points', 4);                 // 12 − 4 = 8 at 150; 15 − 4 = 11 at 90

        $at150 = $this->as($seller)->postJson('/api/seller/commission/calculate', ['price' => 150, 'product_id' => $p->id])->assertOk()->json('data');
        $this->assertEquals(8, $at150['commission_percentage']);
        $this->assertEquals(12, $at150['normal_rate']);
        $this->assertSame('product_override', $at150['commission_source']);
        $this->assertSame('reduction_points', $at150['special_offer']['type']);
        $this->assertArrayNotHasKey('note', $at150['special_offer']);

        // The seller edits the price down a bracket: the preview recalculates
        $at90 = $this->as($seller)->postJson('/api/seller/commission/calculate', ['price' => 90, 'product_id' => $p->id])->assertOk()->json('data');
        $this->assertEquals(11, $at90['commission_percentage']);

        $this->addToCart($p);
        $line = $this->line($this->place()['order_id'], $p);
        $this->assertMoney($at150['commission_amount'], $line->commission_amount);
        $this->assertMoney($at150['seller_amount'], $line->seller_amount);

        // Another seller's product id is refused
        $this->as($this->seller('free'))->postJson('/api/seller/commission/calculate', ['price' => 150, 'product_id' => $p->id])->assertNotFound();
    }

    // ── Refunds, settlements ───────────────────────────────────────────────────

    public function test_partial_return_reverses_exactly_the_snapshotted_commission(): void
    {
        $seller = $this->seller('free');
        $p      = $this->product($seller, 100);
        $this->ovr($p, 'fixed_rate', 5);
        $this->addToCart($p, 2);
        $res = $this->place();
        DB::table('seller_orders')->where('order_id', $res['order_id'])->update(['status' => 'delivered']);
        DB::table('orders')->where('id', $res['order_id'])->update(['status' => 'delivered']);

        $line = OrderItem::where('order_id', $res['order_id'])->firstOrFail();
        $this->assertMoney('10.000', $line->commission_amount);            // 5 % × 200

        // The override is deleted BEFORE the return: the reversal still uses the snapshot (5 %), not today's 15 %
        $this->svc->delete(Override::findOrFail($line->commission_override_id), $this->admin);

        $r = $this->as($this->customer)->post('/api/client/complaints', [
            'order_id' => $res['order_id'], 'complaint_type' => 'damaged_product',
            'description' => 'The item arrived broken, the box was crushed.',
            'items' => [['order_item_id' => $line->id, 'quantity' => 1]], 'images' => [UploadedFile::fake()->image('p.jpg', 400, 300)],
        ], ['Accept' => 'application/json'])->assertCreated();
        $c = Complaint::findOrFail($r->json('data.id'));
        app(ReturnService::class)->applyFinance($c, $this->admin);

        $line = $line->fresh();
        $this->assertSame(1, (int) $line->quantity);
        $this->assertMoney('5.000', $line->commission_amount, '10 − 5: exactly the returned unit\'s share');
        $this->assertMoney('95.000', $line->seller_amount);
        $this->assertEquals(5, (float) $line->commission_percentage);
        $this->assertNotNull($line->commission_override_id);
        $so = $this->parcel($res['order_id'], $seller);
        $this->assertMoney('5.000', $so->commission_amount);
        $this->assertMoney('95.000', $so->seller_net_amount);
    }

    public function test_settlement_totals_equal_the_sum_of_the_item_snapshots(): void
    {
        $seller = $this->seller('free');
        $ids = [];
        foreach ([[100, 'fixed_rate', 5], [150, 'reduction_points', 4], [80, null, null]] as [$price, $type, $value]) {
            $p = $this->product($seller, $price);
            if ($type) {
                $this->ovr($p, $type, $value);
            }
            $this->addToCart($p, 2);
            $ids[] = $this->place()['order_id'];
        }
        $batchId = DB::table('settlement_batches')->insertGetId([
            'batch_reference' => 'T-' . uniqid(), 'seller_id' => $seller->id, 'batch_date' => now()->toDateString(), 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('seller_orders')->whereIn('order_id', $ids)->where('seller_id', $seller->id)->update(['settlement_batch_id' => $batchId]);
        Settlements::recomputeDraft($batchId);

        $items = DB::table('order_items')->whereIn('order_id', $ids)->get();
        $batch = DB::table('settlement_batches')->find($batchId);
        $this->assertMoney($items->sum(fn ($i) => self::m($i->commission_amount)) / 1000, $batch->total_commission);
        $this->assertMoney($items->sum(fn ($i) => self::m($i->seller_amount)) / 1000, $batch->total_seller_payout);
        // 5 % × 200 + 8 % × 300 (150 → 12 − 4) + 15 % × 160 = 10 + 24 + 24
        $this->assertMoney('58.000', $batch->total_commission);
    }

    // ── 0 % visibility vs legacy rows ──────────────────────────────────────────

    public function test_a_new_zero_percent_order_is_shown_as_a_special_offer_and_a_legacy_zero_row_looks_as_today(): void
    {
        $seller = $this->seller('free');
        $p      = $this->product($seller, 100);
        $this->ovr($p, 'fixed_rate', 0);
        $this->addToCart($p);
        $res = $this->place();
        $so  = $this->parcel($res['order_id'], $seller);

        $this->assertEquals(0.0, (float) $so->commission_rate, 'seller order carries 0 %, not null');
        $this->assertSame('product_override', $so->commission_source);
        $detail = $this->as($seller)->getJson("/api/seller/orders/{$so->id}")->assertOk()->json('data');
        $item = $detail['items'][0];
        $this->assertTrue($item['has_commission'], '0 % is visible');
        $this->assertEquals(0.0, $item['commission_percentage']);
        $this->assertEquals(0.0, $item['commission_amount']);
        $this->assertTrue($item['special_offer']);
        $this->assertTrue($detail['commission']['has_commission']);
        $this->assertEquals(100.0, $detail['commission']['total_seller_net']);

        // A legacy row: 0 / 0, no source, no override id → exactly as before (hidden)
        $legacy = $this->product($seller, 100);
        $this->addToCart($legacy);
        $r2  = $this->place();
        DB::table('order_items')->where('order_id', $r2['order_id'])->update([
            'commission_percentage' => 0, 'commission_amount' => 0, 'commission_source' => null, 'commission_override_id' => null,
        ]);
        $so2 = $this->parcel($r2['order_id'], $seller);
        $d2  = $this->as($seller)->getJson("/api/seller/orders/{$so2->id}")->assertOk()->json('data');
        $this->assertFalse($d2['items'][0]['has_commission']);
        $this->assertNull($d2['items'][0]['commission_percentage']);
        $this->assertNull($d2['items'][0]['commission_amount']);
        $this->assertFalse($d2['items'][0]['special_offer']);
        $this->assertFalse($d2['commission']['has_commission']);
        $this->assertNull($d2['commission']['total_commission_amount']);
    }

    public function test_admin_finance_flags_null_source_as_unknown_and_shows_the_override(): void
    {
        $seller = $this->seller('free');
        $p = $this->product($seller, 100);
        $o = $this->ovr($p, 'fixed_rate', 5);
        $this->addToCart($p);
        $res = $this->place();
        $so  = $this->parcel($res['order_id'], $seller);

        $item = $this->as($this->admin)->getJson("/api/admin/finance/orders/{$so->id}/details")->assertOk()->json('data.items.0');
        $this->assertSame('product_override', $item['commission_source']);
        $this->assertSame($o->id, $item['commission_override_id']);
        $this->assertFalse($item['source_unknown']);

        DB::table('order_items')->where('order_id', $res['order_id'])->update(['commission_source' => null, 'commission_override_id' => null]);
        $item = $this->as($this->admin)->getJson("/api/admin/finance/orders/{$so->id}/details")->assertOk()->json('data.items.0');
        $this->assertTrue($item['source_unknown']);
    }

    // ── Validation: one active per product ─────────────────────────────────────

    public function test_two_active_overrides_on_one_product_are_rejected_but_back_to_back_windows_are_fine(): void
    {
        $p = $this->product($this->seller('free'), 100);
        $first = $this->ovr($p, 'fixed_rate', 5);

        try {
            $this->ovr($p, 'fixed_rate', 4);
            $this->fail('second active override accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('product_id', $e->errors());
        }
        // Through the API too
        $this->as($this->admin)->postJson('/api/admin/special-commissions', ['product_ids' => [$p->id], 'type' => 'fixed_rate', 'value' => 3])->assertStatus(422);

        // A scheduled one that overlaps a running open-ended offer: rejected
        $this->assertThrowsValidation(fn () => $this->ovr($p, 'fixed_rate', 4, ['starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(9)]));

        // Close the first at +10 days; a second starting right then is fine, one overlapping it is not
        $this->svc->update($first, ['ends_at' => now()->addDays(10)], $this->admin);
        $second = $this->ovr($p, 'fixed_rate', 4, ['starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(20)]);
        $this->assertNotNull($second->id);
        $this->assertThrowsValidation(fn () => $this->ovr($p, 'fixed_rate', 2, ['starts_at' => now()->addDays(19), 'ends_at' => now()->addDays(30)]));
        // Editing the second into the first is also refused
        $this->assertThrowsValidation(fn () => $this->svc->update($second, ['starts_at' => now()->addDays(8)], $this->admin));

        // After "end now", the product is free again
        $this->svc->endNow($second, $this->admin);
        $this->svc->endNow($first->fresh(), $this->admin);
        $this->assertNotNull($this->ovr($p, 'fixed_rate', 1)->id);
    }

    public function test_values_must_be_between_0_and_100_with_at_most_two_decimals_and_dates_in_order(): void
    {
        $p = $this->product($this->seller('free'), 100);
        foreach ([-1, 100.01, 101, 'abc', 5.123] as $bad) {
            $this->assertThrowsValidation(fn () => $this->ovr($p, 'fixed_rate', $bad), "value {$bad}");
        }
        $this->assertThrowsValidation(fn () => $this->ovr($p, 'half_off', 5));
        $this->assertThrowsValidation(fn () => $this->ovr($p, 'fixed_rate', 5, ['starts_at' => now()->addDays(2), 'ends_at' => now()->addDay()]));
        $this->assertThrowsValidation(fn () => $this->ovr($p, 'fixed_rate', 5, ['ends_at' => now()->subDay()]));
        $this->as($this->admin)->postJson('/api/admin/special-commissions', ['product_ids' => [$p->id], 'type' => 'fixed_rate', 'value' => -5])->assertStatus(422);
        $this->as($this->admin)->postJson('/api/admin/special-commissions', ['product_ids' => [$p->id], 'type' => 'fixed_rate', 'value' => 101])->assertStatus(422);
        $this->assertSame(0, Override::count());
        $this->assertNotNull($this->ovr($p, 'fixed_rate', 12.5)->id);
    }

    private function assertThrowsValidation(\Closure $fn, string $msg = ''): void
    {
        try {
            $fn();
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
            return;
        }
        $this->fail('Validation should have failed. ' . $msg);
    }

    // ── Admin API: bulk, preview, list, audit ──────────────────────────────────

    public function test_admin_preview_shows_current_normal_and_proposed_with_real_money(): void
    {
        $seller = $this->seller('red');
        $p      = $this->product($seller, 150);
        SellerSubscription::create([
            'seller_application_id' => SellerApplication::where('user_id', $seller->id)->value('id'),
            'user_id' => $seller->id, 'current_plan' => 'red', 'status' => 'active',
            'commission_override' => 8, 'commission_override_expires_at' => now()->addDay(),
        ]);

        $row = $this->as($this->admin)->postJson('/api/admin/special-commissions/preview', [
            'product_ids' => [$p->id], 'type' => 'reduction_points', 'value' => 3,
        ])->assertOk()->json('data.0');

        $this->assertSame(8.0, (float) $row['normal']['rate']);
        $this->assertSame('override', $row['normal']['source'], '"Current rate: 8% (seller override)"');
        $this->assertSame(5.0, (float) $row['proposed']['rate']);
        $this->assertMoney('7.500', $row['proposed']['commission']);       // 5 % × 150
        $this->assertMoney('142.500', $row['proposed']['seller_amount']);
        $this->assertMoney('4.500', $row['proposed']['seller_gain']);        // vs 12.000 commission at 8 %
        $this->assertSame(0, Override::count(), 'nothing saved by a preview');
    }

    public function test_bulk_create_list_filters_end_now_delete_and_audit_log(): void
    {
        $seller = $this->seller('free');
        $a = $this->product($seller, 100);
        $b = $this->product($seller, 200);
        $admin = $this->as($this->admin);

        $res = $admin->postJson('/api/admin/special-commissions', [
            'product_ids' => [$a->id, $b->id], 'type' => 'fixed_rate', 'value' => 6, 'note' => 'secret note',
            'ends_at' => now()->addDays(30)->toIso8601String(),
        ])->assertCreated();
        $this->assertCount(2, $res->json('data'));
        $id = $res->json('data.0.id');

        $this->as($this->admin)->getJson('/api/admin/special-commissions?status=active')->assertOk()->assertJsonPath('meta.total', 2);
        $this->as($this->admin)->getJson('/api/admin/special-commissions?status=scheduled')->assertOk()->assertJsonPath('meta.total', 0);
        $this->as($this->admin)->getJson("/api/admin/special-commissions?seller_id={$seller->id}&q=secret")->assertOk()->assertJsonPath('meta.total', 2);

        $this->as($this->admin)->putJson("/api/admin/special-commissions/{$id}", ['value' => 7])->assertOk()->assertJsonPath('data.value', 7);
        $this->as($this->admin)->postJson("/api/admin/special-commissions/{$id}/end")->assertOk()->assertJsonPath('data.status', 'ended');
        $this->as($this->admin)->getJson('/api/admin/special-commissions?status=expired')->assertOk()->assertJsonPath('meta.total', 1);
        $other = $res->json('data.1.id');
        $this->as($this->admin)->deleteJson("/api/admin/special-commissions/{$other}")->assertOk();
        $this->assertNull(Override::find($other));

        $log = $this->as($this->admin)->getJson("/api/admin/special-commissions/logs?override_id={$id}")->assertOk()->json('data');
        $this->assertSame(['ended', 'updated', 'created'], array_column($log, 'action'));
        $upd = collect($log)->firstWhere('action', 'updated');
        $this->assertEquals(6, $upd['before']['value']);
        $this->assertEquals(7, $upd['after']['value']);
        $this->assertSame($this->admin->name, $upd['actor_name']);

        // Not for sellers / customers
        $this->as($seller)->getJson('/api/admin/special-commissions')->assertStatus(403);
        $this->as($this->customer)->getJson('/api/admin/special-commissions')->assertStatus(403);
    }

    // ── Seller-side privacy and notifications ──────────────────────────────────

    public function test_seller_endpoints_never_expose_the_admin_note_and_customers_see_nothing(): void
    {
        $seller = $this->seller('free');
        $p = $this->product($seller, 100);
        $this->ovr($p, 'fixed_rate', 5, ['note' => 'TOP-SECRET-NOTE']);

        $offers = $this->as($seller)->getJson('/api/seller/commission/offers')->assertOk();
        $this->assertStringNotContainsString('TOP-SECRET-NOTE', $offers->getContent());
        $this->assertSame($p->id, $offers->json('data.0.product_id'));
        $this->assertEquals(5, $offers->json('data.0.rate'));

        $calc = $this->as($seller)->postJson('/api/seller/commission/calculate', ['price' => 100, 'product_id' => $p->id])->assertOk();
        $this->assertStringNotContainsString('TOP-SECRET-NOTE', $calc->getContent());

        $this->addToCart($p);
        $res = $this->place();
        $so  = $this->parcel($res['order_id'], $seller);
        $this->assertStringNotContainsString('TOP-SECRET-NOTE', $this->as($seller)->getJson("/api/seller/orders/{$so->id}")->getContent());

        // The buyer's order and the catalog know nothing about commission
        $buyer = $this->as($this->customer)->getJson('/api/client/orders')->getContent();
        $this->assertStringNotContainsStringIgnoringCase('commission', $buyer);
        $this->assertStringNotContainsString('product_override', $buyer);
    }

    public function test_seller_is_notified_when_granted_without_the_note_and_gets_the_3_day_reminder_and_the_end_notice(): void
    {
        $seller = $this->seller('free');
        $p = $this->product($seller, 100);

        $o = $this->ovr($p, 'fixed_rate', 5, ['note' => 'TOP-SECRET-NOTE', 'ends_at' => now()->addDays(10)]);
        Notification::assertSentTo($seller, Notice::class, fn ($n) => $this->noticeKind($n) === Notice::GRANTED);
        Notification::assertSentTo($seller, Notice::class, function ($n) use ($seller) {
            $db = $n->toDatabase($seller);
            $this->assertStringNotContainsString('TOP-SECRET-NOTE', json_encode($db));
            $this->assertStringNotContainsString('TOP-SECRET-NOTE', $n->toMail($seller)->render());
            return true;
        });
        $this->assertNotNull($o->fresh()->granted_notified_at);

        // 10 days out: no reminder yet
        $this->assertSame(['expired' => 0, 'reminded' => 0], $this->svc->runDue());
        // 2 days before the end → reminder, once
        $this->travelTo(now()->addDays(8));
        $this->assertSame(['expired' => 0, 'reminded' => 1], $this->svc->runDue());
        $this->assertSame(['expired' => 0, 'reminded' => 0], $this->svc->runDue(), 'only once');
        Notification::assertSentToTimes($seller, Notice::class, 2);

        // Past the end: switched off, audit-logged, seller told
        $this->travelTo(now()->addDays(3));
        $this->assertSame(['expired' => 1, 'reminded' => 0], $this->svc->runDue());
        $this->assertFalse((bool) $o->fresh()->is_active);
        $this->assertSame('expired', DB::table('product_commission_override_logs')->where('override_id', $o->id)->orderByDesc('id')->value('action'));
        Notification::assertSentToTimes($seller, Notice::class, 3);
        $this->assertSame(0, array_sum($this->svc->runDue()), 'idempotent');
    }

    public function test_the_expiry_command_is_scheduled_and_runs(): void
    {
        $seller = $this->seller('free');
        $p = $this->product($seller, 100);
        $o = $this->ovr($p, 'fixed_rate', 5);
        $o->forceFill(['starts_at' => now()->subDays(2), 'ends_at' => now()->subMinute()])->save();

        $this->artisan('commission-overrides:process')->assertExitCode(0);
        $this->assertFalse((bool) $o->fresh()->is_active);

        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->pluck('command')->implode("\n");
        $this->assertStringContainsString('commission-overrides:process', $events);
    }

    private function noticeKind(Notice $n): string
    {
        return (string) ($n->toDatabase(new User(['email' => null]))['action'] ?? '');
    }
}
