<?php

namespace Tests\Feature\Discounts;

use App\Models\Coupon;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\PriceHistory;
use App\Services\PromotionService;
use App\Services\Returns\ReturnService;
use App\Support\Millimes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AUDIT_DISCOUNTS M-6, L-1, L-2, L-3, L-8: the seller preview is what buyers
 * pay, promotion prices in millimes match SQL, dates are Tunis time, starts
 * can't be in the past, partial-return shares balance.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Discounts/PromotionRulesTest.php
 */
class PromotionRulesTest extends TestCase
{
    use DatabaseTransactions, DiscountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDiscounts();
    }

    // ── M-6: seller preview = checkout ─────────────────────────────────────────

    public function test_seller_preview_shows_exactly_what_checkout_charges(): void
    {
        $seller  = $this->seller('black');
        $product = $this->product($seller, 100);
        $product->update(['price' => 120]);   // raised this month: discounts start from the current 120
        PriceHistory::flush();

        $preview = $this->as($seller)->postJson('/api/seller/promotions/preview', [
            'product_ids' => [$product->id], 'discount_type' => 'percentage', 'discount_value' => 10,
        ])->assertOk()->json('data.0');
        $this->assertEquals(120.0, $preview['original_price']);
        $this->assertEquals(108.0, $preview['final_price'], '10 % off the current 120, not off the old 100');

        $this->promo([$product], 'discount', 10);
        PriceHistory::flush();
        $this->addToCart($product);
        $line = collect($this->quote()['parcels'][0]['lines'])->firstWhere('product_id', $product->id);
        // Checkout shows what the client sees: the seller's preview prices + the delivery included
        $this->assertEquals($preview['client_final_price'], $line['unit_price']);
        $this->assertEquals($preview['client_original_price'], $line['original_unit_price']);
        $this->assertEquals($preview['final_price'] + 8, $line['unit_price']);

        // Commission preview: bracket from the original price
        $calc = $this->as($seller)->postJson('/api/seller/commission/calculate', ['price' => 108, 'tier_price' => 120])->assertOk()->json('data');
        $this->assertEquals(120.0, $calc['tier_price']);
    }

    public function test_seller_preview_only_prices_the_sellers_own_products(): void
    {
        $seller = $this->seller('black');
        $other  = $this->product($this->seller(), 50);
        $this->as($seller)->postJson('/api/seller/promotions/preview', [
            'product_ids' => [$other->id], 'discount_type' => 'percentage', 'discount_value' => 10,
        ])->assertOk()->assertJsonCount(0, 'data');
    }

    // ── L-1: millimes, same as SQL ─────────────────────────────────────────────

    public function test_promotion_price_in_millimes_matches_the_sql_list_price_on_half_way_values(): void
    {
        $seller = $this->seller();
        $cases = [[19.995, 'percentage', 33.333], [12.30, 'percentage', 12.5], [0.999, 'percentage', 12.5], [33.333, 'fixed', 0.001], [7.777, 'percentage', 10]];
        $ids = [];
        foreach ($cases as [$price, $type, $value]) {
            $p = $this->product($seller, $price);
            $this->promo([$p], 'discount', $value, ['discount_type' => $type]);
            $ids[] = $p->id;
        }
        PriceHistory::flush();

        [$sql, $bindings] = app(PromotionService::class)->finalPriceSql();
        $rows = Product::whereIn('id', $ids)->select('id', 'price')->selectRaw("({$sql}) AS sql_final", $bindings)->get();
        $php  = app(PromotionService::class)->priceMany($rows);
        foreach ($rows as $r) {
            $this->assertSame(Millimes::of($r->sql_final), Millimes::of($php[$r->id]['final_price']), "product {$r->id}");
        }
        // Priced from the stored price (products.price keeps 2 decimals): 12.30 × 87.5 % = 10.7625 → 10.763 (half-up, once)
        $this->assertEquals(10.763, $php[$ids[1]]['final_price']);
    }

    // ── L-3 / L-8: Tunis time ──────────────────────────────────────────────────

    public function test_dates_typed_in_tunis_time_are_stored_in_utc_whatever_the_format(): void
    {
        $product = $this->product($this->seller(), 80);
        $make = function (string $start, string $end) use ($product) {
            $name = 'TZ ' . Str::random(5);
            $this->as($this->admin)->postJson('/api/admin/promotions', [
                'name' => $name, 'type' => 'discount', 'discount_type' => 'percentage', 'discount_value' => 10,
                'starts_at' => $start, 'ends_at' => $end, 'product_ids' => [$product->id], 'replace_conflicts' => true,
            ])->assertCreated();
            return Promotion::where('name', $name)->firstOrFail();
        };

        $naive = $make('2027-03-01T10:00', '2027-03-02T10:00');            // no offset: Tunis time
        $this->assertSame('2027-03-01 09:00:00', $naive->starts_at->utc()->toDateTimeString());
        $offset = $make('2027-04-01T10:00:00+01:00', '2027-04-02T10:00:00+01:00');
        $this->assertSame('2027-04-01 09:00:00', $offset->starts_at->utc()->toDateTimeString());
        $zulu = $make('2027-05-01T09:00:00.000Z', '2027-05-02T09:00:00.000Z');
        $this->assertSame('2027-05-01 09:00:00', $zulu->starts_at->utc()->toDateTimeString());
    }

    public function test_seller_promotion_cannot_start_in_the_past_and_uses_tunis_time(): void
    {
        $seller  = $this->seller('black');
        $product = $this->product($seller, 80);
        $body = fn ($start, $end) => [
            'name' => 'Seller ' . Str::random(4), 'type' => 'discount', 'discount_type' => 'percentage', 'discount_value' => 10,
            'starts_at' => $start, 'ends_at' => $end, 'product_ids' => [$product->id],
        ];

        $this->as($seller)->postJson('/api/seller/promotions', $body(now()->subHours(3)->toIso8601String(), now()->addDays(3)->toIso8601String()))
            ->assertStatus(422)->assertJsonValidationErrors('starts_at');

        $start = now()->addDay()->setTimezone('Africa/Tunis')->format('Y-m-d\TH:i');   // what the form's input holds
        $id = $this->as($seller)->postJson('/api/seller/promotions', $body($start, now()->addDays(4)->setTimezone('Africa/Tunis')->format('Y-m-d\TH:i')))
            ->assertCreated()->json('data.id');
        $this->assertSame($start, Promotion::find($id)->starts_at->setTimezone('Africa/Tunis')->format('Y-m-d\TH:i'));
        $this->assertNull(Promotion::find($id)->per_customer_limit, 'only flash sales have a per-customer limit');
    }

    public function test_flash_sales_get_a_per_customer_limit_of_two_by_default(): void
    {
        $product = $this->product($this->seller(), 80);
        $this->as($this->admin)->postJson('/api/admin/promotions', [
            'name' => 'Flash default', 'type' => 'flash_sale', 'discount_type' => 'percentage', 'discount_value' => 30,
            'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addHours(5)->toIso8601String(), 'product_ids' => [$product->id],
        ])->assertCreated();
        $this->assertSame(2, Promotion::where('name', 'Flash default')->value('per_customer_limit'));
    }

    public function test_coupon_expiry_end_of_day_in_tunis_is_stored_in_utc(): void
    {
        $seller  = $this->seller('black');
        $product = $this->product($seller, 50);
        $code = 'TZ' . Str::upper(Str::random(6));
        $this->as($seller)->postJson('/api/seller/coupons', [
            'code' => $code, 'discount_type' => 'percentage', 'discount_value' => 10, 'product_ids' => [$product->id],
            'expires_at' => '2027-12-31T23:59:59+01:00',
        ])->assertCreated();
        $this->assertSame('2027-12-31 22:59:59', Coupon::where('code', $code)->first()->expires_at->utc()->toDateTimeString());
    }

    // ── L-2: partial return shares in millimes ─────────────────────────────────

    public function test_partial_return_shares_are_exact_millimes_and_balance(): void
    {
        $line = new OrderItem([
            'quantity' => 3, 'unit_price' => '33.334', 'total' => '100.000', 'discount_amount' => '0.000',
            'net_total' => '100.000', 'commission_amount' => '15.000', 'seller_amount' => '85.000',
        ]);
        $line->syncOriginal();

        $one = app(ReturnService::class)->share($line, 1);
        $this->assertSame(33.333, $one['net_amount']);
        $this->assertSame(5.0, $one['commission_amount']);
        $this->assertSame(28.333, $one['seller_amount']);
        $this->assertSame(Millimes::of($one['net_amount']), Millimes::of($one['commission_amount']) + Millimes::of($one['seller_amount']));

        $all = app(ReturnService::class)->share($line, 3);
        $this->assertSame(100.0, $all['net_amount']);
        $this->assertSame(85.0, $all['seller_amount']);
    }
}
