<?php

namespace Tests\Feature\Commission;

use App\Models\Complaint;
use App\Models\OrderItem;
use App\Models\ProductCommissionOverride as Override;
use App\Services\ProductCommissionOverrideService;
use App\Services\Returns\ReturnService;
use App\Services\Returns\Settlements;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Discounts\DiscountTestHelpers;
use Tests\TestCase;

/**
 * One complete example, traced end to end with the numbers printed at each step:
 * product 150 DT · seller on Red Pepper (−3 pts) · special commission 5 %.
 *
 * Run (prints the trace):  php vendor/bin/phpunit tests/Feature/Commission/EndToEndTraceTest.php --testdox
 * Grid pinned by the helpers: 100.01–200 DT → 12 %, Red −3 → 9 % normally.
 */
class EndToEndTraceTest extends TestCase
{
    use DatabaseTransactions, DiscountTestHelpers;

    private function say(string $line): void
    {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDiscounts();
        \App\Models\SubscriptionPlan::flushCache();
    }

    public function test_trace_150_dt_red_pepper_override_5_percent_from_preview_to_refund(): void
    {
        $svc    = app(ProductCommissionOverrideService::class);
        $seller = $this->seller('red');
        $p      = $this->product($seller, 150);
        $this->say("\n══ TRACE ══ product 150.000 DT · seller Red Pepper · special commission fixed 5 %");

        // 1 — admin preview, before saving
        $row = $this->as($this->admin)->postJson('/api/admin/special-commissions/preview', ['product_ids' => [$p->id], 'type' => 'fixed_rate', 'value' => 5])->json('data.0');
        $this->say(sprintf('1. ADMIN PREVIEW   normal rate %s%% (%s) → commission %s, seller net %s | new rate %s%% → commission %s, seller net %s (seller gains %s)',
            $row['normal']['rate'], $row['normal']['source'], $row['normal']['commission'], $row['normal']['seller_amount'],
            $row['proposed']['rate'], $row['proposed']['commission'], $row['proposed']['seller_amount'], $row['proposed']['seller_gain']));
        $this->assertEquals(9, $row['normal']['rate']);
        $this->assertMoney('13.500', $row['normal']['commission']);
        $this->assertMoney('7.500', $row['proposed']['commission']);
        $this->assertMoney('142.500', $row['proposed']['seller_amount']);

        $o = $svc->create(['product_id' => $p->id, 'type' => 'fixed_rate', 'value' => 5, 'note' => 'trace'], $this->admin);

        // 2 — seller add/edit product form preview (backend)
        $form = $this->as($seller)->postJson('/api/seller/commission/calculate', ['price' => 150, 'product_id' => $p->id])->json('data');
        $this->say(sprintf('2. SELLER FORM     rate %s%% (normally %s%%, source %s) → commission %s, you receive %s',
            $form['commission_percentage'], $form['normal_rate'], $form['commission_source'], $form['commission_amount'], $form['seller_amount']));
        $this->assertMoney('7.500', $form['commission_amount']);

        // 3 — checkout (quantity 2)
        $this->addToCart($p, 2);
        $res  = $this->place();
        $line = $this->line($res['order_id'], $p);
        $this->say(sprintf('3. CHECKOUT       2 × 150 = %s | line snapshot: rate %s%% source %s override #%s (normal %s%%) → commission %s, seller %s',
            $line->total, $line->commission_percentage, $line->commission_source, $line->commission_override_id, $line->commission_normal_rate, $line->commission_amount, $line->seller_amount));
        $this->assertMoney('15.000', $line->commission_amount);
        $this->assertMoney('285.000', $line->seller_amount);

        // 4 — seller_order
        $so = $this->parcel($res['order_id'], $seller);
        $this->say(sprintf('4. SELLER ORDER   items %s + delivery %s = COD %s | commission %s (%s%%, %s), payout %s, platform profit %s',
            $so->subtotal, $so->delivery_fee, $so->cod_amount, $so->commission_amount, $so->commission_rate, $so->commission_source, $so->seller_net_amount, $so->platform_profit));
        $this->assertParcel($so, ['items' => '300', 'cod' => '308', 'commission' => '15', 'payout' => '285']);

        // 5 — seller finance
        DB::table('seller_orders')->where('id', $so->id)->update(['status' => 'delivered']);
        DB::table('orders')->where('id', $res['order_id'])->update(['status' => 'delivered']);
        $sellerView = $this->as($seller)->getJson("/api/seller/earnings/orders/{$so->id}/details")->assertOk()->json('data');
        $this->say(sprintf('5. SELLER FINANCE earnings: commission %s (%s%%), net %s | line special_offer=%s',
            $sellerView['earnings']['commission_amount'], $sellerView['earnings']['commission_rate'], $sellerView['earnings']['seller_net_amount'] ?? $sellerView['earnings']['net'] ?? '?',
            json_encode($sellerView['items'][0]['special_offer'])));
        $this->assertEquals(15.0, $sellerView['earnings']['commission_amount']);
        $this->assertTrue($sellerView['items'][0]['special_offer']);

        // 6 — admin finance
        $adminView = $this->as($this->admin)->getJson("/api/admin/finance/orders/{$so->id}/details")->assertOk()->json('data');
        $this->say(sprintf('6. ADMIN FINANCE   commission %s (%s%%) | item: source %s, override #%s, normal rate %s%%, source_unknown=%s',
            $adminView['financials']['commission_amount'], $adminView['financials']['commission_rate'],
            $adminView['items'][0]['commission_source'], $adminView['items'][0]['commission_override_id'], $adminView['items'][0]['commission_normal_rate'], json_encode($adminView['items'][0]['source_unknown'])));
        $this->assertEquals(15.0, $adminView['financials']['commission_amount']);

        // 7 — settlement totals, with the override deleted first (the snapshot must still rule)
        $svc->delete($o->fresh(), $this->admin);
        $batchId = DB::table('settlement_batches')->insertGetId([
            'batch_reference' => 'TRACE-' . uniqid(), 'seller_id' => $seller->id, 'batch_date' => now()->toDateString(), 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('seller_orders')->where('id', $so->id)->update(['settlement_batch_id' => $batchId]);
        Settlements::recomputeDraft($batchId);
        $b = DB::table('settlement_batches')->find($batchId);
        $this->say(sprintf('7. SETTLEMENT     (override deleted first) commission %s, seller payout %s, platform profit %s',
            $b->total_commission, $b->total_seller_payout, $b->total_platform_profit));
        $this->assertMoney('15.000', $b->total_commission);
        $this->assertMoney('285.000', $b->total_seller_payout);

        // 8 — refund of 1 unit of 2
        $item = OrderItem::where('order_id', $res['order_id'])->firstOrFail();
        $r = $this->as($this->customer)->post('/api/client/complaints', [
            'order_id' => $res['order_id'], 'complaint_type' => 'damaged_product', 'description' => 'The item arrived broken, the box was crushed.',
            'items' => [['order_item_id' => $item->id, 'quantity' => 1]], 'images' => [UploadedFile::fake()->image('p.jpg', 400, 300)],
        ], ['Accept' => 'application/json'])->assertCreated();
        $c = Complaint::findOrFail($r->json('data.id'));
        app(ReturnService::class)->applyFinance($c, $this->admin);
        $after = $item->fresh();
        $soAfter = $this->parcel($res['order_id'], $seller);
        $this->say(sprintf('8. REFUND 1 unit   line: quantity %s, commission %s (was 15.000), seller %s (was 285.000), rate still %s%% | seller order commission %s, payout %s',
            $after->quantity, $after->commission_amount, $after->seller_amount, $after->commission_percentage, $soAfter->commission_amount, $soAfter->seller_net_amount));
        $this->assertMoney('7.500', $after->commission_amount);
        $this->assertMoney('142.500', $after->seller_amount);
        $this->assertMoney('7.500', $soAfter->commission_amount);

        // 9 — platform-funded promotion on a 120 DT product + 5 % override
        $q = $this->product($seller, 120);
        $this->as($this->admin)->postJson('/api/admin/promotions', [
            'name' => 'Trace BF', 'type' => 'discount', 'discount_type' => 'percentage', 'discount_value' => 20,
            'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addDays(3)->toIso8601String(),
            'product_ids' => [$q->id], 'funded_by' => 'platform',
        ])->assertCreated();
        $svc->create(['product_id' => $q->id, 'type' => 'fixed_rate', 'value' => 5], $this->admin);
        $this->addToCart($q);
        $res2 = $this->place();
        $l2 = $this->line($res2['order_id'], $q);
        $so2 = $this->parcel($res2['order_id'], $seller);
        $this->say(sprintf("9. PLATFORM-FUNDED 120 DT, −20%% paid by Choose'Tounsi, override 5%%: customer pays %s | seller paid on the ORIGINAL 120: %s (120 − 5%% × 120 = 114) | platform keeps %s (= 96 − 114) | charged commission %s",
            $l2->net_total, $l2->seller_amount, $l2->commission_amount, number_format((float) $l2->commission_amount + (float) $l2->platform_funded_amount, 3, '.', '')));
        $this->assertMoney('114.000', $l2->seller_amount);
        $this->assertMoney('-18.000', $l2->commission_amount);
        $this->assertNotNull($so2);
        $this->say('══ END ══');
    }
}
