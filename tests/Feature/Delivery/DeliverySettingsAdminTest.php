<?php

namespace Tests\Feature\Delivery;

use App\Services\Delivery\DeliverySettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Admin "Delivery & Fees": validation, live margins, history, cache, seller-facing cost. */
class DeliverySettingsAdminTest extends TestCase
{
    use DatabaseTransactions, DeliveryTestHelpers;

    private const VALID = [
        'client_delivery_fee' => 8, 'agency_delivery_cost' => 7, 'seller_free_delivery_contribution' => 6,
        'return_shipping_fee' => 8, 'refused_parcel_agency_fee' => 7, 'refused_parcel_fee_paid_by' => 'platform',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDelivery();
    }

    public function test_show_returns_values_margins_and_history(): void
    {
        $data = $this->as($this->admin)->getJson('/api/admin/delivery-settings')->assertOk()->json('data');
        $this->assertEquals(8, $data['settings']['client_delivery_fee']);
        $this->assertEquals(7, $data['settings']['agency_delivery_cost']);
        $this->assertEquals(6, $data['settings']['seller_free_delivery_contribution']);
        $this->assertEquals(1, $data['margins']['normal']);
        $this->assertEquals(-1, $data['margins']['free_delivery']);
        $this->assertCount(1, $data['warnings']);   // negative margin: warned, not blocked
        $this->assertNotEmpty($data['history']);
    }

    public function test_validation_rejects_negatives_and_more_than_three_decimals(): void
    {
        foreach ([['client_delivery_fee' => -1], ['agency_delivery_cost' => '7.0001'], ['seller_free_delivery_contribution' => 'abc'], ['refused_parcel_fee_paid_by' => 'client']] as $bad) {
            $this->as($this->admin)->putJson('/api/admin/delivery-settings', $bad + self::VALID)->assertStatus(422);
        }
        $this->as($this->seller('free'))->putJson('/api/admin/delivery-settings', self::VALID)->assertStatus(403);
    }

    public function test_update_logs_who_when_old_and_new_and_flushes_the_cache(): void
    {
        $this->assertSame(8000, app(DeliverySettings::class)->clientFee());   // cached

        $this->as($this->admin)->putJson('/api/admin/delivery-settings', ['client_delivery_fee' => '9.500'] + self::VALID)->assertOk()
            ->assertJsonPath('data.settings.client_delivery_fee', 9.5)
            ->assertJsonPath('data.margins.normal', 2.5);

        $this->assertSame(9500, app(DeliverySettings::class)->clientFee());
        $log = DB::table('delivery_setting_changes')->where('field', 'client_delivery_fee')->orderByDesc('id')->first();
        $this->assertSame('8.000', $log->old_value);
        $this->assertSame('9.500', $log->new_value);
        $this->assertSame($this->admin->id, (int) $log->changed_by);
        $this->assertNotNull($log->created_at);

        // Seller sees the new cost of free delivery; storefront products the new fee
        $seller = $this->seller('free');
        $this->as($seller)->getJson('/api/seller/shipping-cost')->assertOk()
            ->assertJsonPath('data.free_delivery_contribution', 6)
            ->assertJsonPath('data.customer_delivery_fee', 9.5);
    }

    public function test_return_fee_comes_from_the_settings(): void
    {
        $this->as($this->admin)->putJson('/api/admin/delivery-settings', ['return_shipping_fee' => '5.250'] + self::VALID)->assertOk();
        $this->assertSame(5.25, app(\App\Services\Returns\ReturnService::class)->returnShippingFee());
    }

    public function test_custom_product_fees_are_gone(): void
    {
        $seller  = $this->seller('free');
        $product = $this->product($seller, 50);
        $this->as($this->admin)->putJson("/api/admin/products/{$product->id}", ['delivery_fee' => 12])->assertStatus(422);
        $this->as($this->admin)->putJson("/api/admin/products/{$product->id}", ['delivery_fee' => 0])->assertOk();
        $this->assertTrue($product->fresh()->isFreeDelivery());
        $this->assertEquals(0, $product->fresh()->effective_delivery_fee);
        $this->assertEquals(8, $this->product($seller, 10)->effective_delivery_fee);
    }
}
