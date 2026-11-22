<?php

namespace Tests\Feature\Commission;

use App\Models\ProductCommissionOverride as Override;
use App\Models\SubscriptionPlan;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Discounts\DiscountTestHelpers;
use Tests\TestCase;

/**
 * Regression: an admin special commission created from the admin form (dates sent as Tunis
 * time with a "+01:00" offset) was stored one hour late, so it stayed "scheduled" and every
 * seller screen kept showing the normal rate. These tests drive the real HTTP endpoints.
 *
 * Grid (setUpDiscounts): up to 100 -> 15 %, 100.01-200 -> 12 %, floor 3; plans: free -0, red -3, black -6.
 */
class SpecialCommissionAppliesImmediatelyTest extends TestCase
{
    use DatabaseTransactions, DiscountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDiscounts();
        SubscriptionPlan::flushCache();
    }

    protected function tearDown(): void
    {
        SubscriptionPlan::flushCache();
        parent::tearDown();
    }

    /** What the admin form's fromLocalInput() sends for "now" typed in Tunis time. */
    private function tunisNowAsForm(int $addMinutes = 0): string
    {
        return Carbon::now('Africa/Tunis')->addMinutes($addMinutes)->format('Y-m-d\TH:i:s') . '+01:00';
    }

    private function create(array $productIds, array $extra = [])
    {
        return $this->as($this->admin)->postJson('/api/admin/special-commissions', [
            'product_ids' => $productIds, 'type' => 'fixed_rate', 'value' => 5,
        ] + $extra);
    }

    private function calc($seller, $product, float $price)
    {
        return $this->as($seller)->postJson('/api/seller/commission/calculate', ['price' => $price, 'quantity' => 1, 'product_id' => $product->id]);
    }

    public function test_override_created_now_from_the_admin_form_is_active_immediately(): void
    {
        $seller = $this->seller('black');
        $p = $this->product($seller, 100);

        $res = $this->create([$p->id], ['starts_at' => $this->tunisNowAsForm(), 'ends_at' => $this->tunisNowAsForm(60 * 48)])->assertCreated();
        $res->assertJsonPath('data.0.status', 'active');

        $o = Override::findOrFail($res->json('data.0.id'));
        $this->assertLessThan(120, abs($o->starts_at->diffInSeconds(now(), false)), 'stored in UTC, not as Tunis wall-clock digits');
        $this->assertTrue($o->isLiveAt(now()));

        // Zone-less value (datetime-local without offset) is read as Tunis time too
        $p2 = $this->product($seller, 100);
        $this->create([$p2->id], ['starts_at' => Carbon::now('Africa/Tunis')->format('Y-m-d\TH:i')])
            ->assertCreated()->assertJsonPath('data.0.status', 'active');

        // A start one hour from now stays scheduled
        $p3 = $this->product($seller, 100);
        $this->create([$p3->id], ['starts_at' => $this->tunisNowAsForm(60)])->assertCreated()->assertJsonPath('data.0.status', 'scheduled');

        // Editing keeps the same rule
        $this->as($this->admin)->putJson('/api/admin/special-commissions/' . $res->json('data.0.id'), ['starts_at' => $this->tunisNowAsForm(-5)])
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_seller_preview_badge_offers_and_promotion_preview_follow_the_override_at_once_and_back(): void
    {
        $seller = $this->seller('black');                     // normal rate at 100 DT: 15 - 6 = 9
        $special = $this->product($seller, 100);
        $normal  = $this->product($seller, 100);

        // Warm any cache: normal answers first
        $this->calc($seller, $special, 100)->assertOk()->assertJsonPath('data.commission_percentage', 9);
        $this->as($seller)->getJson('/api/seller/commission/offers')->assertOk()->assertJsonCount(0, 'data');

        $id = $this->create([$special->id], ['starts_at' => $this->tunisNowAsForm()])->assertCreated()->json('data.0.id');

        // Promotion page: one promotion, two products, each gets its own rate
        $r1 = $this->as($seller)->postJson('/api/seller/commission/calculate', ['price' => 80, 'tier_price' => 100, 'quantity' => 1, 'product_id' => $special->id])->assertOk();
        $r2 = $this->as($seller)->postJson('/api/seller/commission/calculate', ['price' => 80, 'tier_price' => 100, 'quantity' => 1, 'product_id' => $normal->id])->assertOk();
        $this->assertEquals(5, $r1->json('data.commission_percentage'));
        $this->assertSame('product_override', $r1->json('data.commission_source'));
        $this->assertEquals(9, $r1->json('data.normal_rate'));
        $this->assertEquals(9, $r2->json('data.commission_percentage'));
        $this->assertNull($r2->json('data.special_offer'));

        // Single-product form preview + product-list badge feed
        $this->calc($seller, $special, 100)->assertJsonPath('data.commission_percentage', 5);
        $offers = $this->as($seller)->getJson('/api/seller/commission/offers')->assertOk();
        $offers->assertJsonCount(1, 'data')->assertJsonPath('data.0.product_id', $special->id)->assertJsonPath('data.0.status', 'active');
        $this->assertEquals(5, $offers->json('data.0.rate'));

        // Pack form
        $pack = $this->as($seller)->postJson('/api/seller/commission/pack-preview', ['pack_price' => 100, 'items' => [['product_id' => $special->id, 'quantity' => 1]]])->assertOk();
        $this->assertTrue((bool) $pack->json('data.special_offer'));

        // Ending it brings the normal rate back at once
        $this->as($this->admin)->postJson("/api/admin/special-commissions/{$id}/end")->assertOk();
        $this->calc($seller, $special, 100)->assertJsonPath('data.commission_percentage', 9);
        $this->as($seller)->getJson('/api/seller/commission/offers')->assertJsonCount(0, 'data');
    }

    public function test_new_orders_use_the_override_old_orders_never_change_and_ending_restores_the_normal_rate(): void
    {
        $seller = $this->seller('black');
        $p = $this->product($seller, 100);

        $this->addToCart($p, 1);
        $old = $this->line($this->place()['order_id'], $p);
        $this->assertEquals(9, (float) $old->commission_percentage);

        $id = $this->create([$p->id], ['starts_at' => $this->tunisNowAsForm()])->assertCreated()->json('data.0.id');

        $this->addToCart($p, 1);
        $new = $this->line($this->place()['order_id'], $p);
        $this->assertEquals(5, (float) $new->commission_percentage);
        $this->assertSame('product_override', $new->commission_source);
        $this->assertSame($id, (int) $new->commission_override_id);

        $this->as($this->admin)->postJson("/api/admin/special-commissions/{$id}/end")->assertOk();
        $this->addToCart($p, 1);
        $after = $this->line($this->place()['order_id'], $p);
        $this->assertEquals(9, (float) $after->commission_percentage);
        $this->assertNull($after->commission_override_id);

        // Old order untouched by all of it
        $oldAgain = $this->line($old->order_id, $p);
        $this->assertSame($old->commission_amount, $oldAgain->commission_amount);
        $this->assertNull($oldAgain->commission_override_id);
        $this->assertSame($new->commission_amount, $this->line($new->order_id, $p)->commission_amount);
    }
}
