<?php

namespace Tests\Feature\Discounts;

use App\Models\Cart;
use App\Models\Complaint;
use App\Models\OrderItem;
use App\Models\Pack;
use App\Models\Product;
use App\Models\User;
use App\Services\Returns\ReturnService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AUDIT_DISCOUNTS C-1, H-1, L-5: pack money spread over every pack line,
 * partial returns refund exactly the returned items, legacy packs return
 * whole, pack prices validated.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Discounts/PackDiscountTest.php
 */
class PackDiscountTest extends TestCase
{
    use DatabaseTransactions, DiscountTestHelpers;

    private User $packSeller;
    private Product $a;   // 30 DT, 1 per pack
    private Product $b;   // 20 DT, 2 per pack
    private Pack $bundle; // 50 DT (items alone: 70)

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDiscounts();
        $this->packSeller = $this->seller();
        $this->a = $this->product($this->packSeller, 30);
        $this->b = $this->product($this->packSeller, 20);
        $this->bundle = $this->pack($this->packSeller, 50, [[$this->a, 1], [$this->b, 2]]);
    }

    /** Pack × 2, delivered. */
    private function deliveredPackOrder(): int
    {
        $this->addPack($this->bundle, 2);
        $res = $this->place();
        DB::table('seller_orders')->where('order_id', $res['order_id'])->update(['status' => 'delivered']);
        DB::table('orders')->where('id', $res['order_id'])->update(['status' => 'delivered']);
        return (int) $res['order_id'];
    }

    private function returnRequest(int $orderId, array $items): \Illuminate\Testing\TestResponse
    {
        return $this->as($this->customer)->post('/api/client/complaints', [
            'order_id' => $orderId, 'complaint_type' => 'damaged_product',
            'description' => 'The item arrived broken, the box was crushed.',
            'items' => $items, 'images' => [UploadedFile::fake()->image('proof.jpg', 400, 300)],
        ], ['Accept' => 'application/json']);
    }

    // ── C-1: allocation ────────────────────────────────────────────────────────

    public function test_pack_price_is_spread_over_every_line_by_original_price_and_sums_exactly(): void
    {
        $this->addPack($this->bundle, 2);
        $quote = $this->quote();
        $this->assertCount(1, $quote['parcels'][0]['lines'], 'the storefront still shows one pack line');
        $this->assertEquals(100.0, $quote['parcels'][0]['lines'][0]['total']);

        $res = $this->place();
        $la = $this->line($res['order_id'], $this->a);
        $lb = $this->line($res['order_id'], $this->b);

        // One pack: 50 × 30/70 = 21.429 (half-up), remainder 28.571 on the last line; × 2 packs
        $this->assertMoney('42.858', $la->total);
        $this->assertMoney('57.142', $lb->total);
        $this->assertSame(self::m(100), self::m($la->total) + self::m($lb->total), 'lines sum exactly to the pack price');
        foreach ([$la, $lb] as $line) {
            $this->assertSame('pack', $line->discount_type);
            $this->assertSame($this->bundle->id, (int) $line->pack_id);
            $this->assertSame($this->bundle->id, (int) $line->discount_source_id);
            $this->assertSame($line->total, $line->pack_allocation);
            $this->assertSame($la->pack_group, $line->pack_group);
        }
        $this->assertMoney('60.000', $la->pack_original_value);
        $this->assertMoney('17.142', $la->applied_discount_amount);
        $this->assertMoney('22.858', $lb->applied_discount_amount);
        $this->assertSame(2, (int) $la->quantity);
        $this->assertSame(4, (int) $lb->quantity);

        // Commission per line: bracket from each item's original price, rate on its share
        $this->assertMoney('6.429', $la->commission_amount);
        $this->assertMoney('36.429', $la->seller_amount);
        $this->assertMoney('8.571', $lb->commission_amount);
        $this->assertMoney('48.571', $lb->seller_amount);

        $so = $this->parcel($res['order_id'], $this->packSeller);
        $this->assertParcel($so, ['items' => '100', 'cod' => '108', 'commission' => '15', 'payout' => '85']);
        $this->assertSame(108.0, $this->intigoPrice($so));
    }

    public function test_partial_pack_return_refunds_the_returned_items_share_and_reverses_commission(): void
    {
        $orderId = $this->deliveredPackOrder();
        $lb = OrderItem::where('order_id', $orderId)->where('product_id', $this->b->id)->firstOrFail();

        // 1 of the 4 units of B: 57.142 / 4 = 14.2855 → 14.286 (before: 0, all the money sat on A)
        $res = $this->returnRequest($orderId, [['order_item_id' => $lb->id, 'quantity' => 1]])->assertCreated();
        $c = Complaint::findOrFail($res->json('data.id'));
        $this->assertMoney('14.286', $c->items_amount);
        $this->assertMoney('14.286', $c->refund_amount, 'seller at fault: nothing deducted');

        app(ReturnService::class)->applyFinance($c, $this->admin);

        $lb = $lb->fresh();
        $this->assertSame(3, (int) $lb->quantity);
        $this->assertMoney('42.856', $lb->net_total);
        $this->assertMoney('6.428', $lb->commission_amount);      // 8.571 − 2.143
        $this->assertMoney('36.428', $lb->seller_amount);         // 48.571 − (14.286 − 2.143)
        $this->assertSame(self::m($lb->net_total), self::m($lb->commission_amount) + self::m($lb->seller_amount), 'line still balances');

        $so = $this->parcel($orderId, $this->packSeller);
        $this->assertMoney('85.714', $so->subtotal);
        $this->assertMoney('12.857', $so->commission_amount);
        $this->assertMoney('72.857', $so->seller_net_amount);
    }

    public function test_returning_one_item_of_the_pack_never_refunds_the_whole_pack(): void
    {
        $orderId = $this->deliveredPackOrder();
        $la = OrderItem::where('order_id', $orderId)->where('product_id', $this->a->id)->firstOrFail();

        $c = Complaint::findOrFail($this->returnRequest($orderId, [['order_item_id' => $la->id, 'quantity' => 1]])->assertCreated()->json('data.id'));
        $this->assertMoney('21.429', $c->items_amount, 'one A = its share of one pack, not 100');
    }

    public function test_legacy_pack_order_can_only_be_returned_whole_and_is_never_modified(): void
    {
        $orderId = $this->deliveredPackOrder();
        $la = OrderItem::where('order_id', $orderId)->where('product_id', $this->a->id)->firstOrFail();
        $lb = OrderItem::where('order_id', $orderId)->where('product_id', $this->b->id)->firstOrFail();
        // Shape of an order placed before the fix: no pack_id, all the money on the first line
        DB::table('order_items')->where('id', $la->id)->update(['pack_id' => null, 'pack_group' => null, 'total' => 100, 'net_total' => 100, 'commission_amount' => 15, 'seller_amount' => 85]);
        DB::table('order_items')->where('id', $lb->id)->update(['pack_id' => null, 'pack_group' => null, 'total' => 0, 'net_total' => 0, 'commission_amount' => 0, 'seller_amount' => 0]);

        $this->returnRequest($orderId, [['order_item_id' => $la->id, 'quantity' => 1]])->assertStatus(422);
        $this->returnRequest($orderId, [['order_item_id' => $lb->id, 'quantity' => 4]])->assertStatus(422);
        $this->assertSame(0, Complaint::where('order_id', $orderId)->count());
        $this->assertMoney('100.000', $la->fresh()->total, 'legacy lines untouched');

        $c = Complaint::findOrFail($this->returnRequest($orderId, [
            ['order_item_id' => $la->id, 'quantity' => 2], ['order_item_id' => $lb->id, 'quantity' => 4],
        ])->assertCreated()->json('data.id'));
        $this->assertMoney('100.000', $c->items_amount, 'whole pack: exactly what was paid');
    }

    public function test_report_lists_legacy_pack_orders_with_a_partial_return_without_changing_them(): void
    {
        $orderId = $this->deliveredPackOrder();
        $la = OrderItem::where('order_id', $orderId)->where('product_id', $this->a->id)->firstOrFail();
        $lb = OrderItem::where('order_id', $orderId)->where('product_id', $this->b->id)->firstOrFail();
        // Legacy shape, and an old partial return that refunded the whole pack for one A
        DB::table('order_items')->where('id', $la->id)->update(['pack_id' => null, 'quantity' => 1, 'returned_quantity' => 1, 'returned_amount' => 100, 'total' => 0, 'net_total' => 0]);
        DB::table('order_items')->where('id', $lb->id)->update(['pack_id' => null, 'total' => 0, 'net_total' => 0]);
        $before = DB::table('order_items')->where('order_id', $orderId)->orderBy('id')->get()->toJson();

        $order = \App\Models\Order::find($orderId);
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('discounts:legacy-pack-returns'));
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString($order->order_number, $out);
        $this->assertStringContainsString('legacy pack order(s) had a partial return', $out);
        $this->assertStringContainsString('100.000', $out, 'what was refunded');
        $this->assertStringContainsString('21.429', $out, 'pro-rata estimate for one A');
        $this->assertSame($before, DB::table('order_items')->where('order_id', $orderId)->orderBy('id')->get()->toJson(), 'read-only');

        // New pack orders (pack_id set) are never listed
        DB::table('order_items')->where('order_id', $orderId)->update(['pack_id' => $this->bundle->id]);
        $this->artisan('discounts:legacy-pack-returns')->expectsOutput('No legacy pack order with a partial return.')->assertExitCode(0);
    }

    // ── H-1 / L-5: pack price rules ────────────────────────────────────────────

    public function test_pack_price_must_be_positive_and_below_the_items_on_create_and_edit(): void
    {
        $items = [['product_id' => $this->a->id, 'quantity' => 1], ['product_id' => $this->b->id, 'quantity' => 2]];
        $seller = $this->as($this->packSeller);

        $seller->postJson('/api/seller/packs', ['name' => 'Zero', 'pack_price' => 0, 'items' => $items])->assertStatus(422);
        $this->as($this->packSeller)->postJson('/api/seller/packs', ['name' => 'Same', 'pack_price' => 70, 'items' => $items])->assertStatus(422);
        $this->as($this->packSeller)->postJson('/api/seller/packs', ['name' => 'Dearer', 'pack_price' => 80, 'items' => $items])->assertStatus(422);
        $id = $this->as($this->packSeller)->postJson('/api/seller/packs', ['name' => 'Good', 'pack_price' => 69.999, 'items' => $items])
            ->assertCreated()->json('data.id');

        $this->as($this->packSeller)->putJson("/api/seller/packs/{$id}", ['pack_price' => -5])->assertStatus(422);
        $this->as($this->packSeller)->putJson("/api/seller/packs/{$id}", ['pack_price' => 'abc'])->assertStatus(422);
        $this->as($this->packSeller)->putJson("/api/seller/packs/{$id}", ['pack_price' => 70])->assertStatus(422);
        // New items worth 30: the current 69.999 is now too high
        $this->as($this->packSeller)->putJson("/api/seller/packs/{$id}", ['items' => [['product_id' => $this->a->id, 'quantity' => 1]]])->assertStatus(422);
        $this->assertMoney('69.999', Pack::find($id)->pack_price);

        $this->as($this->packSeller)->putJson("/api/seller/packs/{$id}", ['pack_price' => 25, 'items' => [['product_id' => $this->a->id, 'quantity' => 1]]])->assertOk();
        $this->assertMoney('25.000', Pack::find($id)->pack_price);
    }

    public function test_a_pack_without_a_valid_price_or_with_the_buyers_own_products_cannot_be_ordered(): void
    {
        $this->bundle->forceFill(['pack_price' => 0])->save();
        $this->addPack($this->bundle, 1);
        $this->as($this->customer)->postJson('/api/checkout/quote', [])->assertStatus(422)
            ->assertJsonPath('message', __('messages.checkout.pack_invalid_price', ['pack' => $this->bundle->name]));

        $this->bundle->forceFill(['pack_price' => 50])->save();
        Cart::create([
            'user_id' => $this->packSeller->id, 'pack_id' => $this->bundle->id, 'quantity' => 1,
            'pack_price_snapshot' => 50, 'pack_name' => $this->bundle->name, 'pack_selections' => [],
        ]);
        $this->as($this->packSeller)->postJson('/api/checkout/quote', [])->assertStatus(422)
            ->assertJsonPath('message', __('messages.checkout.own_pack'));
    }
}
