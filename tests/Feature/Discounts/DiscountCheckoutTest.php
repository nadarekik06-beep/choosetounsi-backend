<?php

namespace Tests\Feature\Discounts;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Promotion;
use App\Services\CouponService;
use App\Support\GuestCheckout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AUDIT_DISCOUNTS phase 2 — checkout: what is charged, frozen on the order,
 * sent to Intigo and paid out, for promotions, flash sales and coupons.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Discounts/DiscountCheckoutTest.php
 */
class DiscountCheckoutTest extends TestCase
{
    use DatabaseTransactions, DiscountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDiscounts();
    }

    // ── M-4 / M-7: snapshot + commission bracket from the original price ──────

    public function test_promotion_line_is_frozen_and_commission_bracket_comes_from_the_original_price(): void
    {
        $seller  = $this->seller();
        $product = $this->product($seller, 120);
        $promo   = $this->promo([$product], 'discount', 20);   // 120 → 96
        $this->addToCart($product);

        $res  = $this->place();
        $line = $this->line($res['order_id'], $product);

        $this->assertSame('promotion', $line->discount_type);
        $this->assertSame($promo->id, (int) $line->discount_source_id);
        $this->assertMoney('24.000', $line->applied_discount_amount);
        $this->assertSame('percentage', $line->promo_discount_type);
        $this->assertMoney('20.000', $line->promo_discount_value);
        $this->assertMoney('120.000', $line->original_unit_price);
        $this->assertMoney('96.000', $line->unit_price);
        $this->assertSame('seller', $line->funded_by);

        // Bracket from 120 (12 %), rate applied to the 96 paid — not the 15 % bracket of 96
        $this->assertMoney('120.000', $line->commission_tier_price);
        $this->assertEquals(12, (float) $line->commission_percentage);
        $this->assertMoney('11.520', $line->commission_amount);
        $this->assertMoney('84.480', $line->seller_amount);

        $so = $this->parcel($res['order_id'], $seller);
        $this->assertParcel($so, ['items' => '96', 'coupon' => '0', 'delivery_fee' => '8', 'cod' => '104', 'commission' => '11.52', 'payout' => '84.48']);
        $this->assertSame(104.0, $this->intigoPrice($so), 'Intigo collects the final price + delivery');

        // Ending / editing / deleting the promotion never changes the order
        $promo->update(['discount_value' => 50, 'status' => 'expired']);
        $this->assertMoney('96.000', $this->line($res['order_id'], $product)->unit_price);
        $this->assertMoney('104.000', $this->parcel($res['order_id'], $seller)->cod_amount);
    }

    public function test_flash_sale_and_plain_lines_record_their_discount_type(): void
    {
        $seller = $this->seller();
        $flash  = $this->product($seller, 40);
        $plain  = $this->product($seller, 25);
        $promo  = $this->promo([$flash], 'flash_sale', 50);
        $this->addToCart($flash);
        $this->addToCart($plain);

        $res = $this->place();
        $f = $this->line($res['order_id'], $flash);
        $p = $this->line($res['order_id'], $plain);

        $this->assertSame('flash_sale', $f->discount_type);
        $this->assertSame($promo->id, (int) $f->discount_source_id);
        $this->assertMoney('20.000', $f->unit_price);
        $this->assertMoney('20.000', $f->applied_discount_amount);
        $this->assertSame('none', $p->discount_type);
        $this->assertNull($p->discount_source_id);
        $this->assertMoney('0', $p->applied_discount_amount);
    }

    // ── M-8: who funds an admin promotion ──────────────────────────────────────

    public function test_platform_funded_admin_promotion_pays_the_seller_on_the_original_price(): void
    {
        $seller  = $this->seller();
        $product = $this->product($seller, 120);

        $this->as($this->admin)->postJson('/api/admin/promotions', [
            'name' => 'Black Friday CT', 'type' => 'discount', 'discount_type' => 'percentage', 'discount_value' => 20,
            'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addDays(3)->toIso8601String(),
            'product_ids' => [$product->id], 'funded_by' => 'platform',
        ])->assertCreated();
        $promo = Promotion::where('name', 'Black Friday CT')->firstOrFail();
        $this->assertSame('platform', $promo->funded_by);
        $this->assertTrue($promo->isPlatformFunded());

        $this->addToCart($product);
        $res  = $this->place();
        $line = $this->line($res['order_id'], $product);

        // Customer pays 96; seller is paid 120 − 12 % × 120 = 105.600; the platform keeps 96 − 105.6 = −9.600
        $this->assertSame('platform', $line->funded_by);
        $this->assertSame('admin', $line->promotion_source);
        $this->assertMoney('24.000', $line->platform_funded_amount);
        $this->assertMoney('96.000', $line->net_total);
        $this->assertMoney('105.600', $line->seller_amount);
        $this->assertMoney('-9.600', $line->commission_amount);

        $so = $this->parcel($res['order_id'], $seller);
        $this->assertParcel($so, ['items' => '96', 'cod' => '104', 'commission' => '-9.6', 'payout' => '105.6', 'agency' => '7', 'margin' => '1']);
        $this->assertMoney('24.000', $so->platform_funded_amount);
        $this->assertMoney('-8.600', $so->platform_profit, 'commission − funded + delivery margin');
        $this->assertSame(104.0, $this->intigoPrice($so), 'the client still pays the discounted price');

        // Seller order details: the discount paid by the platform, commission on the original price
        $detail = $this->as($seller)->getJson("/api/seller/orders/{$so->id}")->assertOk()->json('data');
        $this->assertEquals(24.0, $detail['commission']['total_platform_funded']);
        $this->assertEquals(14.4, $detail['commission']['total_commission_amount']);
        $this->assertEquals(105.6, $detail['commission']['total_seller_net']);
        $this->assertSame('platform', $detail['items'][0]['funded_by']);
        $this->assertEquals(24.0, $detail['items'][0]['platform_funded_amount']);

        // Admin finance shows it
        $kpis = $this->as($this->admin)->getJson('/api/admin/finance/overview?seller_id=' . $seller->id)->assertOk()->json('data.kpis');
        $this->assertEquals(24.0, $kpis['total_platform_funded_discounts']);
        $this->assertEquals(105.6, $kpis['total_seller_payouts']);

        // Seller earnings: commission charged 14.4, funded +24, net 105.6 (never a negative "commission")
        $earn = $this->as($seller)->getJson('/api/seller/earnings/overview?period=all')->assertOk()->json('data.kpis');
        $this->assertEquals(14.4, $earn['total_commission']);
        $this->assertEquals(24.0, $earn['total_platform_funded']);
        $this->assertEquals(105.6, $earn['total_net']);

        // Profit Center (monthly totals + CSV lines): sales + funded − commission = net
        DB::table('seller_orders')->where('id', $so->id)->update(['status' => 'delivered']);
        $revenue = app(\App\Services\Profit\SellerRevenueService::class);
        $month   = $revenue->now()->format('Y-m');
        $start   = $revenue->monthStart($month);
        $m = $revenue->monthly($seller->id, $start, $start->addMonthNoOverflow())[$month];
        $this->assertEquals(14.4, $m['commission']);
        $this->assertEquals(24.0, $m['platform_funded']);
        $this->assertEquals(105.6, $m['net']);
        $csv = collect($revenue->orderLines($seller->id, $start))->firstWhere('order', Order::find($res['order_id'])->order_number);
        $this->assertEquals(14.4, $csv['commission']);
        $this->assertEquals(24.0, $csv['platform_funded']);
        $this->assertEquals(105.6, $csv['net']);

        // The legacy backfill never re-freezes a parcel frozen at checkout
        DB::table('seller_orders')->where('id', $so->id)->update(['commission_amount' => 0]);
        $this->assertSame(0, DB::table('seller_orders')->where('commission_amount', 0)->whereNull('platform_delivery_margin')->where('id', $so->id)->count());
    }

    public function test_seller_funded_admin_promotion_behaves_exactly_like_a_seller_promotion(): void
    {
        $seller = $this->seller();
        $a = $this->product($seller, 120);
        $b = $this->product($seller, 120);
        $this->promo([$a], 'discount', 20);
        $this->promo([$b], 'discount', 20, ['created_by' => Promotion::SOURCE_ADMIN, 'funded_by' => Promotion::FUNDED_SELLER]);
        $this->addToCart($a);
        $this->addToCart($b);

        $res = $this->place();
        $la = $this->line($res['order_id'], $a);
        $lb = $this->line($res['order_id'], $b);
        foreach (['unit_price', 'net_total', 'commission_amount', 'seller_amount', 'commission_percentage', 'platform_funded_amount'] as $col) {
            $this->assertSame((string) $la->$col, (string) $lb->$col, $col);
        }
        $this->assertSame('admin', $lb->promotion_source);
        $this->assertSame('seller', $lb->funded_by);
    }

    public function test_only_admin_promotions_can_be_platform_funded(): void
    {
        $seller  = $this->seller();
        $product = $this->product($seller, 80);
        $promo   = $this->promo([$product], 'discount', 10);

        $this->as($this->admin)->putJson("/api/admin/promotions/{$promo->id}", ['funded_by' => 'platform'])->assertStatus(422);
        $this->assertSame('seller', $promo->fresh()->funded_by);
    }

    // ── M-5: coupon skips promoted items ───────────────────────────────────────

    public function test_coupon_skips_promoted_items_and_uses_the_pre_coupon_price_for_the_bracket(): void
    {
        $seller   = $this->seller();
        $promoted = $this->product($seller, 50);
        $other    = $this->product($seller, 105);
        $this->promo([$promoted], 'discount', 20);                  // 50 → 40
        $coupon = $this->coupon($seller, [$promoted, $other], 'fixed', 10);
        $this->addToCart($promoted);
        $this->addToCart($other);

        $quote  = $this->quote(['coupon_codes' => [$coupon->code]]);
        $parcel = $quote['parcels'][0];
        $this->assertSame([$other->id], $parcel['coupon_product_ids'], 'the coupon applied to the non-promoted item');
        $this->assertSame([$promoted->id], $parcel['coupon_skipped_product_ids']);
        $lines = collect($parcel['lines'])->keyBy('product_id');
        $this->assertTrue($lines[$other->id]['coupon_applied']);
        $this->assertFalse($lines[$promoted->id]['coupon_applied']);
        $this->assertEquals(10.0, $parcel['coupon_discount']);

        $res = $this->place(['coupon_codes' => [$coupon->code]]);
        $lp = $this->line($res['order_id'], $promoted);
        $lo = $this->line($res['order_id'], $other);
        $this->assertSame('promotion', $lp->discount_type);
        $this->assertMoney('0', $lp->discount_amount, 'never stacked on the promoted item');
        $this->assertSame('coupon', $lo->discount_type);
        $this->assertSame($coupon->id, (int) $lo->discount_source_id);
        $this->assertMoney('10.000', $lo->applied_discount_amount);
        // Bracket from 105 (12 %), applied to 95 paid
        $this->assertEquals(12, (float) $lo->commission_percentage);
        $this->assertMoney('11.400', $lo->commission_amount);
        $this->assertMoney('83.600', $lo->seller_amount);

        $so = $this->parcel($res['order_id'], $seller);
        $this->assertParcel($so, ['items' => '145', 'coupon' => '10', 'cod' => '143']);
        $this->assertSame(143.0, $this->intigoPrice($so));
    }

    public function test_multi_seller_order_with_one_sellers_coupon_sends_each_parcels_own_amount_to_intigo(): void
    {
        $s1 = $this->seller();
        $s2 = $this->seller();
        $p1 = $this->product($s1, 30);
        $p2 = $this->product($s2, 50);
        $coupon = $this->coupon($s2, [$p2], 'percentage', 10);
        $this->addToCart($p1);
        $this->addToCart($p2, 2);

        $res = $this->place(['coupon_codes' => [$coupon->code]]);
        $so1 = $this->parcel($res['order_id'], $s1);
        $so2 = $this->parcel($res['order_id'], $s2);

        $this->assertParcel($so1, ['items' => '30', 'coupon' => '0', 'cod' => '38']);
        $this->assertParcel($so2, ['items' => '100', 'coupon' => '10', 'cod' => '98']);
        $this->assertSame(38.0, $this->intigoPrice($so1));
        $this->assertSame(98.0, $this->intigoPrice($so2));
        $this->assertMoney(Order::find($res['order_id'])->total_amount, '136', 'order total = Σ parcel COD');
    }

    // ── L-4: flash sale limit per customer ─────────────────────────────────────

    public function test_flash_sale_limit_per_customer_account(): void
    {
        $seller  = $this->seller();
        $product = $this->product($seller, 40);
        $this->promo([$product], 'flash_sale', 50, ['per_customer_limit' => 2]);

        $row = $this->addToCart($product, 3);
        $this->as($this->customer)->postJson('/api/checkout/quote', [])->assertStatus(422)->assertJsonPath('code', 'flash_customer_limit');

        $row->update(['quantity' => 2]);
        $first = $this->place();
        $this->assertMoney('20.000', $this->line($first['order_id'], $product)->unit_price);

        $this->addToCart($product, 1);
        $this->tryPlace()->assertStatus(422)->assertJsonPath('code', 'flash_customer_limit');

        // A cancelled order gives the units back
        $this->moveTo($this->parcel($first['order_id'], $seller), 'cancelled');
        $this->tryPlace()->assertCreated();
    }

    public function test_flash_limit_is_checked_again_under_lock_inside_the_checkout_transaction(): void
    {
        $seller  = $this->seller();
        $product = $this->product($seller, 40);
        $this->promo([$product], 'flash_sale', 50, ['per_customer_limit' => 2]);

        $this->addToCart($product, 1);
        $first   = $this->place();
        $firstId = $this->line($first['order_id'], $product)->id;

        // Another order of the same customer commits between the quote and this order's lines
        $this->addToCart($product, 1);
        Event::listen('eloquent.creating: ' . Order::class, fn () => DB::table('order_items')->where('id', $firstId)->update(['quantity' => 2]));

        $before = Order::count();
        $this->tryPlace()->assertStatus(422)->assertJsonPath('code', 'flash_customer_limit');
        $this->assertSame($before, Order::count(), 'nothing written');
    }

    public function test_flash_limit_counts_guest_orders_by_phone(): void
    {
        $seller  = $this->seller();
        $product = $this->product($seller, 40);
        $this->promo([$product], 'flash_sale', 50, ['per_customer_limit' => 2]);

        $guest = function (string $token, int $qty) use ($product) {
            $this->flushHeaders();
            $this->app['auth']->forgetGuards();
            $this->withHeaders([GuestCheckout::CART_HEADER => $token])->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => $qty])->assertOk();
            $this->travel(\App\Services\Orders\CheckoutGuard::RECENT_SECONDS + 1)->seconds();
            $address = ['recipient_name' => 'Amel Guest', 'wilaya' => 'Tunis', 'delegation' => 'Bab Bhar',
                'address' => '5 rue de la Kasbah', 'postal_code' => '1000', 'phone' => '22333444'];
            $quote = $this->withHeaders([GuestCheckout::CART_HEADER => $token])->postJson('/api/checkout/quote', ['phone' => '22333444']);
            return $this->withHeaders([GuestCheckout::CART_HEADER => $token])->postJson('/api/checkout', $address + [
                'payment_method' => 'cod', 'expected_total' => $quote->json('data.total'),
            ]);
        };

        $guest(Str::random(40), 2)->assertCreated();
        // Another browser (new cart token), same phone: limit reached
        $guest(Str::random(40), 1)->assertStatus(422)->assertJsonPath('code', 'flash_customer_limit');
    }

    // ── M-2 / M-3: coupon limits under lock ────────────────────────────────────

    public function test_once_per_customer_coupon_cannot_be_used_by_two_simultaneous_orders(): void
    {
        $seller  = $this->seller();
        $product = $this->product($seller, 50);
        $coupon  = $this->coupon($seller, [$product], 'percentage', 10, ['usage_limit_per_customer' => 1]);

        // An earlier order of this customer (no coupon)
        $this->addToCart($product);
        $earlier = $this->place();
        $earlierSo = $this->parcel($earlier['order_id'], $seller);

        // The other order took the customer's only use after this one was quoted
        $this->addToCart($product);
        Event::listen('eloquent.creating: ' . Order::class, fn () => CouponRedemption::create([
            'coupon_id' => $coupon->id, 'order_id' => $earlier['order_id'], 'seller_order_id' => $earlierSo->id,
            'user_id' => $this->customer->id, 'discount_amount' => 5,
        ]));

        $before = Order::count();
        $this->tryPlace(['coupon_codes' => [$coupon->code]])
            ->assertStatus(422)->assertJsonPath('code', 'coupon_unavailable');
        $this->assertSame($before, Order::count());
    }

    public function test_last_total_use_taken_concurrently_is_refused_with_a_clear_message(): void
    {
        $seller  = $this->seller();
        $product = $this->product($seller, 50);
        $coupon  = $this->coupon($seller, [$product], 'percentage', 10, ['usage_limit' => 1]);
        $this->addToCart($product);

        Event::listen('eloquent.creating: ' . Order::class, fn () => DB::table('coupons')->where('id', $coupon->id)->update(['usage_count' => 1]));

        $this->tryPlace(['coupon_codes' => [$coupon->code]])
            ->assertStatus(422)->assertJsonPath('code', 'coupon_unavailable');
        $this->assertSame(0, CouponRedemption::where('coupon_id', $coupon->id)->count());
    }

    // ── H-2: coupon use released on cancel (any path), idempotent ──────────────

    public function test_cancelled_cod_order_gives_the_coupon_use_back_on_every_path(): void
    {
        $seller  = $this->seller();
        $product = $this->product($seller, 50);
        $coupon  = $this->coupon($seller, [$product], 'percentage', 10, ['usage_limit' => 5]);
        $this->addToCart($product);
        $res = $this->place(['coupon_codes' => [$coupon->code]]);
        $so  = $this->parcel($res['order_id'], $seller);
        $this->assertSame(1, $coupon->fresh()->usage_count);

        // Admin cancels the parcel (ParcelStatus + observer): released once
        $this->moveTo($so, 'cancelled');
        $this->assertSame(0, $coupon->fresh()->usage_count);
        $this->assertSame(0, CouponRedemption::where('seller_order_id', $so->id)->count());
        $this->assertSame(0, app(CouponService::class)->releaseForSellerOrders([$so->id]), 'idempotent');
        $this->assertSame(0, $coupon->fresh()->usage_count);
        $this->assertMoney('5.000', $this->parcel($res['order_id'], $seller)->discount_amount, 'the order keeps its price');

        // Re-opened: the use is taken again
        $this->moveTo($so, 'pending');
        $this->assertSame(1, $coupon->fresh()->usage_count);
        $this->assertSame(1, CouponRedemption::where('seller_order_id', $so->id)->count());

        // Cancelled by a raw write (no observer): the every-minute sweep releases it
        DB::table('seller_orders')->where('id', $so->id)->update(['status' => 'cancelled']);
        $this->artisan('promotions:sync')->assertExitCode(0);
        $this->assertSame(0, $coupon->fresh()->usage_count);
        $this->artisan('promotions:sync')->assertExitCode(0);
        $this->assertSame(0, $coupon->fresh()->usage_count, 'idempotent');
    }

    // ── M-1 / L-7: coupon deletion and edits ───────────────────────────────────

    public function test_deleted_coupon_keeps_its_history_and_can_no_longer_be_applied(): void
    {
        $seller  = $this->seller('black');
        $product = $this->product($seller, 50);
        $coupon  = $this->coupon($seller, [$product], 'percentage', 10);
        $this->addToCart($product);
        $this->place(['coupon_codes' => [$coupon->code]]);

        $this->as($seller)->deleteJson("/api/seller/coupons/{$coupon->id}")->assertOk();
        $this->assertSoftDeleted('coupons', ['id' => $coupon->id]);
        $this->assertSame(1, CouponRedemption::where('coupon_id', $coupon->id)->count(), 'usage history kept');
        $this->assertSame(1, (int) $this->as($seller)->getJson('/api/seller/coupons/stats')->json('data.total_redemptions'));

        $this->addToCart($product);
        $this->as($this->customer)->postJson('/api/checkout/quote', ['coupon_codes' => [$coupon->code]])->assertStatus(422);

        // The code can't be created again (limits would restart from zero)
        $this->as($seller)->postJson('/api/seller/coupons', [
            'code' => $coupon->code, 'discount_type' => 'percentage', 'discount_value' => 10, 'product_ids' => [$product->id],
        ])->assertStatus(422);
    }

    public function test_coupon_update_refuses_inactive_or_unapproved_products(): void
    {
        $seller   = $this->seller('black');
        $product  = $this->product($seller, 50);
        $inactive = $this->product($seller, 30);
        $inactive->update(['is_active' => false]);
        $coupon = $this->coupon($seller, [$product]);

        $this->as($seller)->putJson("/api/seller/coupons/{$coupon->id}", ['product_ids' => [$product->id, $inactive->id]])->assertStatus(403);
        $this->assertSame([$product->id], $coupon->products()->pluck('products.id')->map(fn ($i) => (int) $i)->all());
    }
}
