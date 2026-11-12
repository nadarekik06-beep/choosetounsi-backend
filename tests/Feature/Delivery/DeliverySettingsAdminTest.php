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
        'refused_parcel_agency_fee' => 7, 'refused_parcel_fee_paid_by' => 'platform',
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
        // Seller-offered free delivery is gone: no contribution, one margin (client fee − Intigo cost)
        $this->assertArrayNotHasKey('seller_free_delivery_contribution', $data['settings']);
        $this->assertEquals(1, $data['margins']['normal']);
        $this->assertArrayNotHasKey('free_delivery', $data['margins']);
        $this->assertCount(0, $data['warnings']);
        $this->assertEquals(8, $data['sizes']['L']['client_fee']);
        $this->assertSame(4, $data['sizes']['L']['package_size']);
        $this->assertNotEmpty($data['history']);
    }

    public function test_validation_rejects_negatives_and_more_than_three_decimals(): void
    {
        foreach ([['client_delivery_fee' => -1], ['agency_delivery_cost' => '7.0001'], ['refused_parcel_agency_fee' => 'abc'], ['refused_parcel_fee_paid_by' => 'client']] as $bad) {
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
        // The flat fields are size S
        $log = DB::table('delivery_setting_changes')->where('field', 'S.client_fee')->orderByDesc('id')->first();
        $this->assertSame('8.000', $log->old_value);
        $this->assertSame('9.500', $log->new_value);
        $this->assertSame($this->admin->id, (int) $log->changed_by);
        $this->assertNotNull($log->created_at);

        // Nothing is charged to sellers for delivery any more (endpoint kept for older dashboards);
        // storefront products show the new fee inside their price at once
        $seller = $this->seller('free');
        $this->as($seller)->getJson('/api/seller/shipping-cost')->assertOk()
            ->assertJsonPath('data.free_delivery_contribution', 0)
            ->assertJsonPath('data.delivery_included', true);
        $product = $this->product($seller, 50);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/products/{$product->slug}")->assertOk()->assertJsonPath('data.final_price', 59.5);
    }

    public function test_exchange_parcel_switch_is_off_by_default_saved_and_logged(): void
    {
        $this->as($this->admin)->getJson('/api/admin/delivery-settings')->assertOk()
            ->assertJsonPath('data.settings.intigo_is_exchange', false)
            ->assertJsonPath('data.intigo.is_exchange', false);

        $this->as($this->admin)->putJson('/api/admin/delivery-settings', ['intigo_is_exchange' => true] + self::VALID)->assertOk()
            ->assertJsonPath('data.settings.intigo_is_exchange', true)
            ->assertJsonPath('data.intigo.is_exchange', true)
            ->assertJsonPath('data.history.0.field', 'intigo_is_exchange');
        $this->assertTrue(app(DeliverySettings::class)->isExchange());

        $log = DB::table('delivery_setting_changes')->where('field', 'intigo_is_exchange')->orderByDesc('id')->first();
        $this->assertSame('no', $log->old_value);
        $this->assertSame('yes', $log->new_value);
        $this->assertSame($this->admin->id, (int) $log->changed_by);
        $this->assertNotNull($log->created_at);

        // Saving again without a change logs nothing; omitting the field keeps it
        $count = DB::table('delivery_setting_changes')->where('field', 'intigo_is_exchange')->count();
        $this->as($this->admin)->putJson('/api/admin/delivery-settings', ['intigo_is_exchange' => true] + self::VALID)->assertOk();
        $this->as($this->admin)->putJson('/api/admin/delivery-settings', self::VALID)->assertOk()
            ->assertJsonPath('data.settings.intigo_is_exchange', true);
        $this->assertSame($count, DB::table('delivery_setting_changes')->where('field', 'intigo_is_exchange')->count());

        // Back off: logged yes → no
        $this->as($this->admin)->putJson('/api/admin/delivery-settings', ['intigo_is_exchange' => false] + self::VALID)->assertOk();
        $log = DB::table('delivery_setting_changes')->where('field', 'intigo_is_exchange')->orderByDesc('id')->first();
        $this->assertSame(['yes', 'no'], [$log->old_value, $log->new_value]);
        $this->assertFalse(app(DeliverySettings::class)->isExchange());

        $this->as($this->admin)->putJson('/api/admin/delivery-settings', ['intigo_is_exchange' => 'maybe'] + self::VALID)->assertStatus(422);
    }

    public function test_after_sale_fees_per_size_come_from_the_settings(): void
    {
        $sizes = [];
        foreach (['S' => 8, 'L' => 10, 'XL' => 16] as $size => $agency) {
            $sizes[$size] = ['client_fee' => 8, 'agency_cost' => $agency, 'return_fee' => '4.280', 'seller_contribution' => 6,
                'after_sale_use_agency_cost' => $size !== 'XL', 'after_sale_return_fee' => '5.250', 'exchange_fee' => $agency + 1];
        }
        $this->as($this->admin)->putJson('/api/admin/delivery-settings', ['sizes' => $sizes] + self::VALID)->assertOk()
            ->assertJsonPath('data.sizes.L.after_sale_return_fee', 10)        // follows the Intigo cost
            ->assertJsonPath('data.sizes.XL.after_sale_return_fee', 5.25)     // custom value
            ->assertJsonPath('data.sizes.XL.after_sale_use_agency_cost', false)
            ->assertJsonPath('data.sizes.S.exchange_fee', 9);

        $fees = app(\App\Services\Returns\ReturnService::class)->afterSaleFees();
        $this->assertSame(['return' => 10.0, 'exchange' => 11.0], $fees['L']);
        $this->assertSame(5.25, $fees['XL']['return']);

        // Older admin builds (no after-sale fields) keep working and change nothing there
        $this->as($this->admin)->putJson('/api/admin/delivery-settings', ['client_delivery_fee' => 9] + self::VALID)->assertOk();
        $this->assertSame(5.25, app(\App\Services\Returns\ReturnService::class)->afterSaleFees()['XL']['return']);
    }

    public function test_custom_product_fees_are_gone(): void
    {
        $seller  = $this->seller('free');
        $product = $this->product($seller, 50);
        // No per-product fee and no seller-offered free delivery: the field is ignored
        $this->as($this->admin)->putJson("/api/admin/products/{$product->id}", ['delivery_fee' => 12])->assertOk();
        $this->as($this->admin)->putJson("/api/admin/products/{$product->id}", ['delivery_fee' => 0])->assertOk();
        $this->assertNull($product->fresh()->delivery_fee);
        $this->assertFalse($product->fresh()->isFreeDelivery());
        $this->assertArrayNotHasKey('effective_delivery_fee', $product->fresh()->toArray());
        $this->assertArrayNotHasKey('is_free_delivery', $product->fresh()->toArray());
    }

    public function test_per_size_prices_are_saved_and_priced_at_checkout(): void
    {
        $body = self::VALID + ['intigo_can_open' => true, 'sizes' => [
            'S'  => ['client_fee' => 7, 'agency_cost' => 6, 'return_fee' => 4, 'seller_contribution' => 5],
            'L'  => ['client_fee' => '9.500', 'agency_cost' => 8, 'return_fee' => 5, 'seller_contribution' => 7],
            'XL' => ['client_fee' => 14, 'agency_cost' => 12, 'return_fee' => 9, 'seller_contribution' => 11],
        ]];
        $this->as($this->admin)->putJson('/api/admin/delivery-settings', $body)->assertOk()
            ->assertJsonPath('data.sizes.L.client_fee', 9.5)
            ->assertJsonPath('data.sizes.XL.margin_normal', 2)
            ->assertJsonPath('data.settings.client_delivery_fee', 7)   // flat = S
            ->assertJsonPath('data.settings.intigo_can_open', true);

        $s = app(DeliverySettings::class);
        $this->assertSame(9500, $s->clientFee('L'));
        $this->assertSame(9000, $s->refusedFee('XL'));
        $this->assertTrue($s->canOpen());

        // A missing size is rejected
        $bad = $body;
        unset($bad['sizes']['XL']['return_fee']);
        $this->as($this->admin)->putJson('/api/admin/delivery-settings', $bad)->assertStatus(422);
    }
}
