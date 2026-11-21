<?php

namespace Tests\Feature\Commission;

use App\Models\PlatformSetting;
use App\Models\SellerSubscription;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Delivery\DeliveryTestHelpers;
use Tests\Support\LegacyCommissionService;
use Tests\TestCase;

/**
 * A product WITHOUT a special commission must be priced EXACTLY as before the feature:
 * the live CommissionService is compared, key by key, to a frozen copy of the old one
 * (tests/Support/LegacyCommissionService.php) for every bracket edge × every plan ×
 * quantity / discount / original-price / platform-funded combination.
 */
class CommissionRegressionTest extends TestCase
{
    use DatabaseTransactions, DeliveryTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::setValue('commission.default', [
            'tiers' => [
                ['min' => 0,       'max' => 100,  'rate' => 15],
                ['min' => 100.01,  'max' => 200,  'rate' => 12],
                ['min' => 200.01,  'max' => 300,  'rate' => 10],
                ['min' => 300.01,  'max' => 500,  'rate' => 8],
                ['min' => 500.01,  'max' => 1000, 'rate' => 5],
                ['min' => 1000.01, 'max' => null, 'rate' => 3],
            ],
            'floor' => 3,
        ]);
        PlatformSetting::flushCache();
        $this->setUpDelivery();
    }

    /** Bracket edges and middles, in DT. */
    private const PRICES = [1, 12.5, 50, 99.99, 100, 100.01, 150, 199.999, 200, 200.01, 250, 300, 300.01, 450, 500, 500.01, 750, 1000, 1000.01, 2500];

    public function test_no_override_gives_the_exact_legacy_numbers_for_every_bracket_and_plan(): void
    {
        $old = new LegacyCommissionService();
        $new = app(CommissionService::class);
        $checked = 0;

        foreach (['free', 'red', 'black'] as $plan) {
            $seller  = $this->seller($plan);
            $product = $this->product($seller, 10);

            foreach (self::PRICES as $price) {
                foreach ([[1, 0.0, null, 0], [3, 0.0, null, 0], [2, 7.5, null, 0], [1, 0.0, $price * 1.5, 0], [2, 3.3, $price * 2, 0], [1, 0.0, $price * 1.25, 1250]] as [$qty, $discount, $tier, $funded]) {
                    $expected = $old->calculateForSeller($seller->id, (float) $price, $qty, $discount, $tier, $funded);
                    // Same call, with and without the product id: no override exists, nothing may change
                    foreach ([null, $product->id] as $productId) {
                        $actual = $new->calculateForSeller($seller->id, (float) $price, $qty, $discount, $tier, $funded, $productId);
                        foreach ($expected as $key => $value) {
                            $this->assertSame($value, $actual[$key], "{$plan} @ {$price} qty {$qty} disc {$discount} tier " . json_encode($tier) . " funded {$funded}: {$key}");
                        }
                        $this->assertNull($actual['commission_override_id']);
                        $this->assertSame($expected['commission_percentage'], $actual['normal_rate']);
                        $checked++;
                    }
                }
                $this->assertSame($old->calculate((float) $price, $plan), $new->calculate((float) $price, $plan), "calculate() {$plan} @ {$price}");
                $this->assertSame($old->resolveForSeller($seller->id, (float) $price), $new->resolveForSeller($seller->id, (float) $price));
            }
        }
        $this->assertGreaterThan(300, $checked);
    }

    public function test_the_published_rates_of_each_plan_are_unchanged(): void
    {
        $new = app(CommissionService::class);
        $free = $this->seller('free');
        $red  = $this->seller('red');
        $black = $this->seller('black');

        // base table 15/12/10/8/5/3, floor 3; red −3, black −6 (the platform grid of the test DB)
        $this->assertSame(15.0, $new->rateFor(null, $free->id, 100)['rate']);
        $this->assertSame(12.0, $new->rateFor(null, $red->id, 100)['rate']);
        $this->assertSame(9.0,  $new->rateFor(null, $black->id, 100)['rate']);
        $this->assertSame(3.0,  $new->rateFor(null, $black->id, 1000.01)['rate'], 'floor still applies without an override');
        $this->assertSame('plan', $new->rateFor(null, $red->id, 100)['source']);
    }

    public function test_a_seller_level_override_still_wins_over_the_plan_when_the_product_has_none(): void
    {
        $seller = $this->seller('red');
        $sub = SellerSubscription::create([
            'seller_application_id' => \App\Models\SellerApplication::where('user_id', $seller->id)->value('id'),
            'user_id' => $seller->id, 'current_plan' => 'red', 'status' => 'active',
            'commission_override' => 8, 'commission_override_expires_at' => now()->addDay(),
        ]);
        $old = (new LegacyCommissionService())->calculateForSeller($seller->id, 120.0);
        $new = app(CommissionService::class)->calculateForSeller($seller->id, 120.0, 1, 0.0, null, 0, $this->product($seller, 120)->id);

        $this->assertSame(8.0, $new['commission_percentage']);
        $this->assertSame('override', $new['commission_source'], "the existing 'override' value keeps meaning seller-level");
        $this->assertSame($old['commission_amount'], $new['commission_amount']);
        $this->assertNotNull($sub->id);
    }
}
