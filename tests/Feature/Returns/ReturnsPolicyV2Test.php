<?php

namespace Tests\Feature\Returns;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Complaint;
use App\Models\ComplaintItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerAdjustment;
use App\Models\User;
use App\Notifications\Returns\ReturnAdminNotification;
use App\Notifications\Returns\SettlementDraftChangedNotification;
use App\Services\Delivery\DeliverySettings;
use App\Services\Returns\ReturnPolicy;
use App\Support\Millimes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Delivery\DeliveryTestHelpers;
use Tests\TestCase;

/**
 * Exchange-first returns policy (v2): the scenarios of the brief to the millime, the 48 h
 * window from the real delivered date, 2-3 photos, the frozen refund the courier pays,
 * the payout freeze, inspection, disputes, and an old complaint finishing the old flow.
 *
 * Settings: client fee S 9.000 / L 11.000, after-sale return fee 4.280, exchange fee 9.000.
 * Commission: free plan 15 % (unit price ≤ 100 DT). Dress 60 (S) shows 69.000.
 *
 * Run:  php vendor/phpunit/phpunit/phpunit tests/Feature/Returns
 */
class ReturnsPolicyV2Test extends TestCase
{
    use DatabaseTransactions, DeliveryTestHelpers;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('public');
        config(['intigo.mode' => 'fake', 'intigo.fake_exchange_failure' => null]);
        $this->setUpDelivery();

        $sizes = [];
        foreach (['S' => 9, 'L' => 11, 'XL' => 17] as $size => $fee) {
            $sizes[$size] = [
                'client_fee' => Millimes::of($fee), 'agency_cost' => Millimes::of($fee), 'return_fee' => Millimes::of('4.280'),
                'after_sale_use_agency_cost' => false, 'after_sale_return_fee' => Millimes::of('4.280'),
                'exchange_fee' => Millimes::of('9'),
            ];
        }
        app(DeliverySettings::class)->update(['sizes' => $sizes], $this->admin->id);

        $this->seller = $this->seller('free');
        $this->seller->forceFill(['intigo_pickup_index' => random_int(1000, 999999)])->save();
        $this->enable();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function enable(bool $on = true): void
    {
        app(ReturnPolicy::class)->update(['v2_enabled' => $on], $this->admin->id);
    }

    /** A product with N variants (stock each), same price. @return array{0: Product, 1: ProductVariant[]} */
    private function dress($price = 60, string $size = 'S', int $variants = 2, int $stock = 5, ?User $seller = null, array $extra = []): array
    {
        $name = 'Gizmo ' . Str::random(6);
        $p = Product::create([
            'seller_id' => ($seller ?? $this->seller)->id, 'name' => $name, 'slug' => Str::slug($name), 'parcel_size' => $size,
            'price' => $price, 'stock' => $variants * $stock, 'is_approved' => true, 'is_active' => true,
        ] + $extra);
        $vs = [];
        for ($i = 0; $i < $variants; $i++) {
            $vs[] = ProductVariant::create(['product_id' => $p->id, 'sku' => 'V' . $i . '-' . Str::random(6), 'stock' => $stock, 'is_active' => true]);
        }
        return [$p, $vs];
    }

    /** Orders [product, variant|null, qty] lines, checks out as the customer, marks every parcel delivered. */
    private function orderDelivered(array $lines, $at = null): Order
    {
        foreach ($lines as [$product, $variant, $qty]) {
            Cart::create(['user_id' => $this->customer->id, 'product_id' => $product->id, 'variant_id' => $variant?->id, 'quantity' => $qty]);
        }
        $res = $this->checkout();
        $this->travel(\App\Services\Orders\CheckoutGuard::RECENT_SECONDS + 1)->seconds();
        return $this->markDelivered((int) $res['order_id'], $at);
    }

    private function markDelivered(int $orderId, $at = null): Order
    {
        foreach (DB::table('seller_orders')->where('order_id', $orderId)->pluck('id') as $soId) {
            DB::table('seller_orders')->where('id', $soId)->update([
                'status' => 'delivered', 'delivery_confirmed_at' => $at ?? now(),
                'intigo_nid' => 'NID-' . $soId . '-' . Str::random(4), 'intigo_status_code' => 5000, 'intigo_sync_status' => 'sent',
                'payout_status' => 'ready', 'money_received_at' => now(), 'cash_collected_at' => now(),
            ]);
        }
        DB::table('orders')->where('id', $orderId)->update(['status' => 'delivered', 'updated_at' => now()]);
        return Order::findOrFail($orderId);
    }

    private function line(Order $order, Product $product): OrderItem
    {
        return OrderItem::where('order_id', $order->id)->where('product_id', $product->id)->firstOrFail();
    }

    private function photos(int $n = 2): array
    {
        return array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 400, 300), range(1, $n));
    }

    /** Files a v2 request (2 photos by default). */
    private function ask(Order $order, string $type, array $items, string $resolution = 'return_refund', int $photos = 2, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->as($this->customer)->post('/api/client/complaints', [
            'order_id' => $order->id, 'complaint_type' => $type, 'resolution_type' => $resolution,
            'description' => 'Detailed reason for this request, long enough to be valid.',
            'items' => $items, 'images' => $this->photos($photos),
            'item_unused_confirmed' => $type === 'changed_mind' ? 1 : null,
        ] + $extra, ['Accept' => 'application/json']);
    }

    private function complaint(\Illuminate\Testing\TestResponse $res): Complaint
    {
        return Complaint::findOrFail($res->assertCreated()->json('data.id'));
    }

    private function item(OrderItem $line, int $qty = 1, ?ProductVariant $replacement = null): array
    {
        return ['order_item_id' => $line->id, 'quantity' => $qty] + ($replacement ? ['replacement_variant_id' => $replacement->id] : []);
    }

    private function approve(Complaint $c): void
    {
        $this->as($this->seller)->patchJson("/api/seller/complaints/{$c->id}/approve", ['seller_note' => 'ok'])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/approve")->assertOk();
    }

    /** Request → accepted → approved → pick-up scheduled → picked up (the courier pays cash). */
    private function pickedUp(Order $order, string $type, array $items, int $photos = 2): Complaint
    {
        $c = $this->complaint($this->ask($order, $type, $items, 'return_refund', $photos));
        $this->approve($c);
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/schedule-pickup", ['carrier' => 'Intigo'])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/picked-up")->assertOk();
        return $c->fresh();
    }

    private function received(Complaint $c, ?User $by = null): void
    {
        $this->as($by ?? $this->seller)->patchJson(($by && $by->role === 'admin' ? "/api/admin/complaints/{$c->id}/start-inspection" : "/api/seller/complaints/{$c->id}/received"))->assertOk();
    }

    private function inspect(Complaint $c, string $result, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->as($this->seller)->post("/api/seller/complaints/{$c->id}/inspect", ['result' => $result] + $extra, ['Accept' => 'application/json']);
    }

    private function so(Order $order): object
    {
        return DB::table('seller_orders')->where('order_id', $order->id)->where('seller_id', $this->seller->id)->first();
    }

    private function adjustments(Complaint $c): array
    {
        return SellerAdjustment::where('complaint_id', $c->id)->pluck('amount', 'type')->map(fn ($v) => self::m($v))->all();
    }

    /** How many admin notices of that type were sent (the flow sends other admin notices too). */
    private function adminNotices(string $type): int
    {
        return Notification::sent($this->admin, ReturnAdminNotification::class)
            ->filter(fn ($n) => ($n->toDatabase($this->admin)['type'] ?? null) === $type)->count();
    }

    private function stock(ProductVariant $v): int
    {
        return (int) DB::table('product_variants')->where('id', $v->id)->value('stock');
    }

    // ── Switch off / on, window, photos ───────────────────────────────────────

    public function test_policy_is_off_by_default_and_behaves_as_before(): void
    {
        $this->enable(false);
        [$p, [$a]] = $this->dress();
        $order = $this->orderDelivered([[$p, $a, 1]]);

        $eligible = $this->as($this->customer)->getJson('/api/client/complaints/eligible-orders')->assertOk();
        $this->assertFalse($eligible->json('policy.v2'));
        $this->assertSame(1, $eligible->json('policy.min_photos'));

        // One photo is enough, a complaint is stamped with no policy version
        $c = $this->complaint($this->ask($order, 'damaged_product', [$this->item($this->line($order, $p))], 'return_refund', 1));
        $this->assertNull($c->policy_version);
    }

    public function test_window_counts_48_hours_from_the_real_delivered_date_not_the_order_update(): void
    {
        [$p, [$a]] = $this->dress();
        $fresh = $this->orderDelivered([[$p, $a, 1]], now()->subHours(47));
        $eligible = $this->as($this->customer)->getJson('/api/client/complaints/eligible-orders')->assertOk();
        $this->assertTrue($eligible->json('policy.v2'));
        $this->assertContains($fresh->id, array_column($eligible->json('data'), 'id'));

        // Delivered 49 h ago, but the order row was touched just now (any later write): closed anyway
        [$p2, [$a2]] = $this->dress();
        $old = $this->orderDelivered([[$p2, $a2, 1]], now()->subHours(49));
        $eligible = $this->as($this->customer)->getJson('/api/client/complaints/eligible-orders')->assertOk();
        $this->assertNotContains($old->id, array_column($eligible->json('data'), 'id'));
        $closed = collect($eligible->json('closed_orders'))->firstWhere('id', $old->id);
        $this->assertNotNull($closed, 'the orders page explains why the button is closed');
        $this->assertStringContainsString('48', $closed['closed_reason']);

        $this->ask($old, 'damaged_product', [$this->item($this->line($old, $p2))])->assertStatus(422);
    }

    public function test_every_reason_needs_two_or_three_photos(): void
    {
        [$p, [$a]] = $this->dress(60, 'S', 1, 20);
        $order = $this->orderDelivered([[$p, $a, 3]]);
        $line = $this->line($order, $p);

        foreach (['changed_mind', 'damaged_product', 'wrong_product', 'doesnt_fit'] as $type) {
            $this->ask($order, $type, [$this->item($line)], 'return_refund', 1)->assertStatus(422);
            $this->ask($order, $type, [$this->item($line)], 'return_refund', 4)->assertStatus(422);
        }
        $c = $this->complaint($this->ask($order, 'changed_mind', [$this->item($line)], 'return_refund', 3));
        $this->assertSame(2, $c->policy_version);
        $this->assertCount(3, $c->image_paths);
    }

    // ── Scenarios ─────────────────────────────────────────────────────────────

    public function test_scenario_1_wrong_size_exchange_keeps_the_payout_and_restocks_after_inspection(): void
    {
        [$p, [$m, $l]] = $this->dress();
        $order = $this->orderDelivered([[$p, $m, 1]]);
        $line = $this->line($order, $p);
        $netBefore = self::m($this->so($order)->seller_net_amount);
        $this->assertSame(4, $this->stock($m));

        $c = $this->complaint($this->ask($order, 'doesnt_fit', [$this->item($line, 1, $l)], 'exchange'));
        $this->assertTrue($c->isExchange());
        $this->assertSame('client', $c->shipping_payer, 'the client pays the exchange parcel');
        $this->assertSame(self::m('9'), self::m($c->clientFee()));
        $this->assertEquals(0.0, (float) $c->refund_amount, 'no refund');

        $this->approve($c);
        $c->refresh();
        $this->assertSame(Complaint::STATUS_EXCHANGE_IN_TRANSIT, $c->status);
        $this->assertSame(4, $this->stock($l), 'new size −1 at approval');

        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/exchange/delivered")->assertOk();
        $this->assertSame(4, $this->stock($m), 'old size not back before the inspection');

        $this->inspect($c, 'conform')->assertOk();
        $c->refresh();
        $this->assertSame(Complaint::STATUS_EXCHANGE_COMPLETED, $c->status);
        $this->assertSame(5, $this->stock($m), 'M +1 after inspection');
        $this->assertSame(4, $this->stock($l));
        $this->assertSame($netBefore, self::m($this->so($order)->seller_net_amount), 'the seller keeps the sale');
        $this->assertSame(0, SellerAdjustment::where('complaint_id', $c->id)->count());
    }

    public function test_scenario_2_defective_without_stock_is_refunded_69_and_the_seller_pays_9_plus_4_28(): void
    {
        [$p, [$a]] = $this->dress(60, 'S', 1, 1);
        $order = $this->orderDelivered([[$p, $a, 1]]);
        $this->assertSame(0, $this->stock($a), 'zero stock');
        $line = $this->line($order, $p);

        $preview = $this->as($this->customer)->postJson('/api/client/complaints/preview', [
            'order_id' => $order->id, 'complaint_type' => 'doesnt_fit', 'resolution_type' => 'return_refund', 'items' => [$this->item($line)],
        ])->assertOk()->json('data');
        $this->assertFalse($preview['policy']['damaged_product']['exchange_allowed'], 'exchange impossible: no stock');
        $this->assertTrue($preview['policy']['doesnt_fit']['refund_allowed'], 'so the refund is offered');
        $this->assertEquals(69.0, $preview['refund_amount']);

        $c = $this->pickedUp($order, 'damaged_product', [$this->item($line)]);
        $this->assertSame('seller', $c->shipping_payer);
        $this->assertSame(self::m('69'), self::m($c->refund_amount));
        $this->assertSame(self::m('69'), self::m($c->refund_amount_locked));
        $this->assertSame('cash', $c->refund_method, 'the courier pays the client at pick-up');

        // Item not back yet: the sale is not reversed, the payout is frozen
        $this->assertGreaterThan(0, self::m($this->so($order)->seller_net_amount));
        $this->assertTrue($c->payoutIsFrozen());
        $this->assertSame(0, $this->adjustments($c) ? 1 : 0);

        $this->received($c);
        $this->inspect($c, 'conform')->assertOk();
        $c->refresh();

        $this->assertSame(Complaint::STATUS_REFUNDED, $c->status);
        $so = $this->so($order);
        $this->assertSame(self::m('0'), self::m($so->seller_net_amount), 'no payout');
        $this->assertSame(self::m('0'), self::m($so->commission_amount), 'commission reversed');
        $adj = $this->adjustments($c);
        $this->assertSame(self::m('-9'), $adj[SellerAdjustment::TYPE_RETURN_PARCEL_FEE]);
        $this->assertSame(self::m('-4.280'), $adj[SellerAdjustment::TYPE_RETURN_SHIPPING]);
        $this->assertCount(2, $adj);
        $this->assertSame(self::m('69'), self::m($c->refund_amount));
        $this->assertSame(1, $this->stock($a), 'Conforme: +1 on the exact variant');
        $this->assertNotNull($c->payout_released_at);
    }

    public function test_scenario_3_changed_mind_is_refunded_64_720_and_the_seller_loses_the_payout_only(): void
    {
        [$p, [$a]] = $this->dress();
        $order = $this->orderDelivered([[$p, $a, 1]]);
        $line = $this->line($order, $p);

        $preview = $this->as($this->customer)->postJson('/api/client/complaints/preview', [
            'order_id' => $order->id, 'complaint_type' => 'changed_mind', 'resolution_type' => 'return_refund', 'items' => [$this->item($line)],
        ])->assertOk()->json('data');
        $this->assertEquals(64.72, $preview['refund_amount']);
        $this->assertEquals(69.0, $preview['items_amount']);
        $this->assertEquals(4.28, $preview['client_fee']);
        $this->assertSame(-4.28, (float) $preview['deductions'][0]['amount']);

        $c = $this->pickedUp($order, 'changed_mind', [$this->item($line)]);
        $this->assertSame('client', $c->shipping_payer);
        $this->assertSame(self::m('64.720'), self::m($c->refund_amount));

        $this->received($c);
        $this->inspect($c, 'conform')->assertOk();

        $so = $this->so($order);
        $this->assertSame(self::m('0'), self::m($so->seller_net_amount));
        $this->assertSame(self::m('0'), self::m($so->commission_amount));
        $this->assertSame(0, SellerAdjustment::where('complaint_id', $c->id)->count(), 'nothing else charged');
        $this->assertSame(self::m('64.720'), self::m($c->fresh()->refund_amount));
    }

    public function test_scenario_4_changed_mind_is_disabled_for_an_excluded_category_but_defects_stay_open(): void
    {
        $cat = Category::create(['name' => 'Lingerie ' . Str::random(4), 'name_ar' => 'x', 'name_fr' => 'x', 'no_changed_mind_return' => true]);
        [$p, [$a]] = $this->dress(60, 'S', 2, 5, null, ['category_id' => $cat->id]);
        $order = $this->orderDelivered([[$p, $a, 1]]);
        $line = $this->line($order, $p);

        $preview = $this->as($this->customer)->postJson('/api/client/complaints/preview', [
            'order_id' => $order->id, 'complaint_type' => 'changed_mind', 'items' => [$this->item($line)],
        ])->assertOk()->json('data.policy');
        $this->assertTrue($preview['changed_mind']['disabled']);
        $this->assertMatchesRegularExpression('/défectueux|defective/', $preview['changed_mind']['disabled_reason']);
        $this->assertFalse($preview['damaged_product']['disabled']);
        $this->assertTrue($preview['wrong_product']['refund_allowed']);

        $this->ask($order, 'changed_mind', [$this->item($line)])->assertStatus(422);
        $this->ask($order, 'changed_mind', [$this->item($line, 1, null)], 'exchange')->assertStatus(422);
        $this->complaint($this->ask($order, 'damaged_product', [$this->item($line)]));

        // Product page line
        $mode = (new ReturnPolicy())->returnsMode($p->fresh());
        $this->assertSame('defect_only', $mode);
    }

    public function test_subcategory_exception_overrides_the_category_flag(): void
    {
        $cat = Category::create(['name' => 'Beaute ' . Str::random(4), 'name_ar' => 'x', 'name_fr' => 'x', 'no_changed_mind_return' => true]);
        $sub = \App\Models\Subcategory::create(['category_id' => $cat->id, 'name' => 'Appareils ' . Str::random(4), 'slug' => 'app-' . Str::random(6), 'no_changed_mind_return' => false]);
        [$p] = $this->dress(60, 'S', 1, 5, null, ['category_id' => $cat->id, 'subcategory_id' => $sub->id]);
        $this->assertSame('exchange_easy', (new ReturnPolicy())->returnsMode($p));
    }

    public function test_scenario_5_abuse_non_conforming_item_is_sent_back_and_the_seller_keeps_the_payout(): void
    {
        [$p, [$a]] = $this->dress();
        $order = $this->orderDelivered([[$p, $a, 1]]);
        $line = $this->line($order, $p);
        $netBefore = self::m($this->so($order)->seller_net_amount);

        $c = $this->pickedUp($order, 'changed_mind', [$this->item($line)]);
        $this->received($c);

        // Photos and a comment are required for Non conforme
        $this->inspect($c, 'nonconform')->assertStatus(422);
        $this->inspect($c, 'nonconform', ['comment' => 'Worn, stains on the collar'])->assertStatus(422);
        $this->inspect($c, 'nonconform', ['comment' => 'Worn, stains on the collar', 'photos' => $this->photos(2)])->assertOk();
        $c->refresh();

        $this->assertSame('open', $c->dispute_status);
        $this->assertSame(Complaint::STATUS_RETURNED_TO_SELLER, $c->status);
        $this->assertSame(0, $this->stock($a) - 4, 'no stock back for a non-conforming item');
        $this->assertTrue($c->payoutIsFrozen(), 'payout stays frozen until the admin decides');
        $this->assertSame($netBefore, self::m($this->so($order)->seller_net_amount));
        Notification::assertSentTo($this->admin, ReturnAdminNotification::class, fn ($n) => true);

        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/dispute", ['decision' => 'send_back', 'nid' => 'RET-123', 'note' => 'Worn'])->assertOk();
        $c->refresh();
        $this->assertSame('resolved', $c->dispute_status);
        $this->assertSame(Complaint::STATUS_REFUNDED, $c->status);
        $this->assertSame(self::m('4.280'), self::m($c->sent_back_fee), 'the client pays that delivery');
        $this->assertNull($c->finance_applied_at);
        $this->assertFalse($c->payoutIsFrozen());
        $this->assertSame($netBefore, self::m($this->so($order)->seller_net_amount), 'the seller keeps the payout');
        $this->assertSame(0, SellerAdjustment::where('complaint_id', $c->id)->count());
        $this->assertSame(1, DB::table('complaint_costs')->where('complaint_id', $c->id)->where('kind', 'sent_back')->count());

        // The client sees the photos and the reason
        $show = $this->as($this->customer)->getJson("/api/client/complaints/{$c->id}")->assertOk()->json('data');
        $this->assertSame('Worn, stains on the collar', $show['sent_back']['reason']);
        $this->assertCount(2, $show['sent_back']['photos']);
    }

    public function test_dispute_can_charge_the_seller_absorb_or_treat_the_item_as_conform(): void
    {
        [$p, [$a, $b]] = $this->dress();
        $order = $this->orderDelivered([[$p, $a, 2]]);
        $line = $this->line($order, $p);

        $charge = $this->pickedUp($order, 'changed_mind', [$this->item($line, 1)]);
        $this->received($charge);
        $this->inspect($charge, 'nonconform', ['comment' => 'Damaged by the client', 'photos' => $this->photos(1)])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$charge->id}/dispute", ['decision' => 'contact_client', 'note' => 'call'])->assertOk();
        $this->assertSame('open', $charge->fresh()->dispute_status, 'contacting the client keeps the dispute open');
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$charge->id}/dispute", ['decision' => 'charge_seller', 'amount' => 20, 'note' => 'ok'])->assertOk();
        $this->assertSame(self::m('-20'), $this->adjustments($charge->fresh())[SellerAdjustment::TYPE_RETURN_DISPUTE]);
        $this->assertSame(Complaint::STATUS_REFUNDED, $charge->fresh()->status);

        $absorb = $this->pickedUp($order->fresh(), 'changed_mind', [$this->item($line, 1)]);
        $this->received($absorb);
        $this->inspect($absorb, 'nonconform', ['comment' => 'Not resellable', 'photos' => $this->photos(1)])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$absorb->id}/dispute", ['decision' => 'absorb'])->assertOk();
        $this->assertSame(0, SellerAdjustment::where('complaint_id', $absorb->id)->count());
        $this->assertSame(self::m('55.720'), self::m($absorb->fresh()->dispute_amount), 'what the courier paid (R7 on 2 units: 129 − 69, minus 4.280)');

        // Unknown decision / nothing to decide
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$absorb->id}/dispute", ['decision' => 'absorb'])->assertStatus(422);
    }

    public function test_dispute_treat_as_conform_restocks_and_reverses_the_sale(): void
    {
        [$p, [$a]] = $this->dress();
        $order = $this->orderDelivered([[$p, $a, 1]]);
        $c = $this->pickedUp($order, 'changed_mind', [$this->item($this->line($order, $p))]);
        $this->received($c);
        $this->inspect($c, 'nonconform', ['comment' => 'Looks used', 'photos' => $this->photos(1)])->assertOk();
        $this->assertSame(4, $this->stock($a));

        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/dispute", ['decision' => 'treat_as_conform'])->assertOk();
        $this->assertSame(5, $this->stock($a), 'the admin overrode the seller: item back in stock');
        $this->assertSame(self::m('0'), self::m($this->so($order)->seller_net_amount));
        $this->assertNotNull($c->fresh()->finance_applied_at);
        $this->assertSame(Complaint::STATUS_REFUNDED, $c->fresh()->status);
    }

    public function test_scenario_6_partial_seller_fault_refund_30_and_the_seller_pays_9_plus_4_28(): void
    {
        [$tshirt] = $this->dress(30, 'S', 1, 5);
        [$shoes]  = $this->dress(80, 'L', 1, 5);
        $order = $this->orderDelivered([[$tshirt, null, 1], [$shoes, null, 1]]);
        $this->assertSame(self::m('121'), self::m($order->total_amount));

        $c = $this->pickedUp($order, 'damaged_product', [$this->item($this->line($order, $tshirt))]);
        $this->assertSame('partial', $c->return_scope);
        $this->assertSame(self::m('30'), self::m($c->refund_amount), '121.000 − 91.000');

        $this->received($c);
        $this->inspect($c, 'conform')->assertOk();

        $adj = $this->adjustments($c);
        $this->assertSame(self::m('-9'), $adj[SellerAdjustment::TYPE_RETURN_ITEM_FEE], "the T-shirt's included fee");
        $this->assertSame(self::m('-4.280'), $adj[SellerAdjustment::TYPE_RETURN_SHIPPING]);
        $this->assertCount(2, $adj);
        // T-shirt payout and commission gone: payout 110 − 16.5 → 80 − 12 = 68
        $so = $this->so($order);
        $this->assertSame(self::m('68'), self::m($so->seller_net_amount));
        $this->assertSame(self::m('12'), self::m($so->commission_amount));
    }

    public function test_scenario_7_partial_changed_mind_on_the_largest_item_refunds_77_720(): void
    {
        [$tshirt] = $this->dress(30, 'S', 1, 5);
        [$shoes]  = $this->dress(80, 'L', 1, 5);
        $order = $this->orderDelivered([[$tshirt, null, 1], [$shoes, null, 1]]);

        $c = $this->pickedUp($order, 'changed_mind', [$this->item($this->line($order, $shoes))]);
        $this->assertSame(self::m('77.720'), self::m($c->refund_amount), '82.000 − 4.280');

        $this->received($c);
        $this->inspect($c, 'conform')->assertOk();

        $this->assertSame(0, SellerAdjustment::where('complaint_id', $c->id)->count(), 'nothing else charged');
        $so = $this->so($order);
        $this->assertSame(self::m('25.5'), self::m($so->seller_net_amount), 'shoes payout and commission reversed (30 − 15 %)');
        $this->assertSame(self::m('4.5'), self::m($so->commission_amount));
    }

    // ── Frozen refund, freeze, settlement ─────────────────────────────────────

    public function test_the_refund_is_frozen_at_approval_and_that_exact_amount_is_what_the_courier_pays(): void
    {
        [$p, [$a]] = $this->dress();
        $order = $this->orderDelivered([[$p, $a, 1]]);
        $c = $this->complaint($this->ask($order, 'changed_mind', [$this->item($this->line($order, $p))]));
        $this->approve($c);
        $c->refresh();
        $this->assertSame(self::m('64.720'), self::m($c->refund_amount_locked));

        // The after-sale fee changes before the pick-up: what was announced is what is paid
        app(DeliverySettings::class)->update(['sizes' => ['S' => ['after_sale_return_fee' => Millimes::of('6')]]], $this->admin->id);
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/schedule-pickup", ['carrier' => 'Intigo'])->assertOk();

        $task = $this->as($this->admin)->getJson("/api/admin/complaints/{$c->id}")->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/picked-up")->assertOk();
        $c->refresh();
        $this->assertSame(self::m('64.720'), self::m($c->refund_amount));
        $this->assertSame('cash', $c->refund_method);
        $this->assertSame(self::m('64.720'), self::m(app(\App\Services\Returns\ReturnService::class)->cashToPay($c)));
    }

    public function test_a_settlement_batch_created_between_pickup_and_inspection_skips_the_frozen_parcel(): void
    {
        [$p, [$a]] = $this->dress();
        $order = $this->orderDelivered([[$p, $a, 1]]);
        $c = $this->pickedUp($order, 'changed_mind', [$this->item($this->line($order, $p))]);
        $this->assertTrue($c->payoutIsFrozen());

        // Another parcel of the same seller is ready: only that one is settled
        [$p2, [$a2]] = $this->dress(10, 'S', 1, 5);
        $other = $this->orderDelivered([[$p2, $a2, 1]]);
        $soFrozen = $this->so($order);
        $soOther = $this->so($other);

        $batch = $this->as($this->admin)->postJson('/api/admin/settlements/create', [
            'seller_id' => $this->seller->id, 'batch_date' => now()->toDateString(),
        ])->assertOk()->json('data');

        $this->assertSame(1, (int) $batch['orders_count']);
        $this->assertNull($this->so($order)->settlement_batch_id, 'the frozen parcel is not in the batch');
        $this->assertSame((int) $batch['id'], (int) $this->so($other)->settlement_batch_id);
        $this->assertNotNull($soFrozen->id.$soOther->id);

        // When it is the only ready parcel there is nothing to settle
        $this->as($this->admin)->postJson("/api/admin/settlements/{$batch['id']}/cancel")->assertOk();
        DB::table('seller_orders')->where('id', $soOther->id)->update(['payout_status' => 'paid']);
        $this->as($this->admin)->postJson('/api/admin/settlements/create', [
            'seller_id' => $this->seller->id, 'batch_date' => now()->addDay()->toDateString(),
        ])->assertStatus(422);

        // Both finance pages call it « En attente de retour »
        $earnings = $this->as($this->seller)->getJson('/api/seller/earnings/overview?period=all')->assertOk()->json('data.kpis');
        $this->assertSame(1, $earnings['awaiting_return_count']);
        $this->assertEquals(round(self::m($soFrozen->seller_net_amount) / 1000, 3), $earnings['awaiting_return_amount']);
        $pending = $this->as($this->admin)->getJson('/api/admin/finance/orders?per_page=50')->assertOk()->json('data.data');
        $row = collect($pending)->firstWhere('id', $soFrozen->id);
        $this->assertTrue((bool) $row['payout_held']);

        // After the inspection the freeze is over (the reversal settles it)
        $this->received($c);
        $this->inspect($c, 'conform')->assertOk();
        $this->assertFalse((bool) collect($this->as($this->admin)->getJson('/api/admin/finance/orders?per_page=50')->json('data.data'))->firstWhere('id', $soFrozen->id)['payout_held']);
    }

    public function test_pickup_pulls_the_parcel_out_of_a_draft_batch_recomputes_it_and_tells_the_admin(): void
    {
        app()->setLocale('fr');
        [$p, [$a]] = $this->dress();
        [$p2, [$a2]] = $this->dress(10, 'S', 1, 5);
        $order = $this->orderDelivered([[$p, $a, 1]]);
        $other = $this->orderDelivered([[$p2, $a2, 1]]);
        $soReturned = $this->so($order);
        $soOther = $this->so($other);

        $batch = $this->as($this->admin)->postJson('/api/admin/settlements/create', [
            'seller_id' => $this->seller->id, 'batch_date' => now()->toDateString(),
        ])->assertOk()->json('data');
        $this->assertSame(2, (int) $batch['orders_count']);

        $c = $this->pickedUp($order, 'changed_mind', [$this->item($this->line($order, $p))]);

        $this->assertNull($this->so($order)->settlement_batch_id);
        $draft = DB::table('settlement_batches')->where('id', $batch['id'])->first();
        $this->assertSame(1, (int) $draft->orders_count, 'draft recomputed');
        $this->assertSame(self::m($soOther->seller_net_amount), self::m($draft->total_seller_payout));
        Notification::assertSentTo($this->admin, SettlementDraftChangedNotification::class, function ($n, $ch) use ($order) {
            $row = $n->toDatabase($this->admin);
            return $row['type'] === 'settlement_draft_changed' && $row['data']['order_number'] === $order->order_number && $row['data']['draft_deleted'] === false;
        });
        app()->setLocale('fr');
        $this->assertStringContainsString('retirée du règlement brouillon : retour en cours', __('returns_policy.order_removed_from_draft', ['order' => '#X']));

        // The last parcel leaves: the empty draft is deleted so it can't be confirmed
        $c2 = $this->pickedUp($other, 'changed_mind', [$this->item($this->line($other, $p2))]);
        $this->assertNull(DB::table('settlement_batches')->where('id', $batch['id'])->first(), 'empty draft deleted');
    }

    // ── Exchange impossible ───────────────────────────────────────────────────

    public function test_seller_marks_the_exchange_impossible_and_the_client_switches_to_a_refund_in_one_click(): void
    {
        [$p, [$m, $l]] = $this->dress();
        $order = $this->orderDelivered([[$p, $m, 1]]);
        $c = $this->complaint($this->ask($order, 'doesnt_fit', [$this->item($this->line($order, $p), 1, $l)], 'exchange'));

        $this->as($this->seller)->patchJson("/api/seller/complaints/{$c->id}/exchange-impossible", ['reason' => 'The last L was damaged in the warehouse'])->assertOk();
        $c->refresh();
        $this->assertNotNull($c->exchange_impossible_at);

        // The shop can no longer accept the exchange; the client is told
        $this->as($this->seller)->patchJson("/api/seller/complaints/{$c->id}/approve")->assertStatus(422);
        $show = $this->as($this->customer)->getJson("/api/client/complaints/{$c->id}")->assertOk()->json('data');
        $this->assertTrue($show['exchange_impossible']['can_switch_to_refund']);

        $this->as($this->customer)->patchJson("/api/client/complaints/{$c->id}/switch-to-refund")->assertOk();
        $c->refresh();
        $this->assertSame('return_refund', $c->resolution_type);
        $this->assertSame('seller', $c->shipping_payer, 'the stock-out is the shop\'s: 100 % refund');
        $this->assertSame(self::m('69'), self::m($c->refund_amount));
        $this->assertNotNull($c->switched_to_refund_at);

        // Then the normal refund flow
        $this->approve($c);
        $this->assertSame(self::m('69'), self::m($c->fresh()->refund_amount_locked));
    }

    public function test_changed_mind_exchange_impossible_needs_the_unused_confirmation_to_switch(): void
    {
        [$p, [$m, $l]] = $this->dress();
        $order = $this->orderDelivered([[$p, $m, 1]]);
        $c = $this->complaint($this->ask($order, 'changed_mind', [$this->item($this->line($order, $p), 1, $l)], 'exchange'));
        $this->as($this->seller)->patchJson("/api/seller/complaints/{$c->id}/exchange-impossible", ['reason' => 'Out of stock for good'])->assertOk();

        $this->as($this->customer)->patchJson("/api/client/complaints/{$c->id}/switch-to-refund")->assertStatus(422);
        $this->as($this->customer)->patchJson("/api/client/complaints/{$c->id}/switch-to-refund", ['item_unused_confirmed' => true])->assertOk();
        $c->refresh();
        $this->assertSame('client', $c->shipping_payer);
        $this->assertSame(self::m('64.720'), self::m($c->refund_amount), 'return fee deducted for a changed mind');
    }

    // ── Inspection clock ──────────────────────────────────────────────────────

    public function test_admin_is_notified_when_the_shop_never_confirms_reception_and_can_start_the_inspection(): void
    {
        [$p, [$a]] = $this->dress();
        $order = $this->orderDelivered([[$p, $a, 1]]);
        $c = $this->pickedUp($order, 'changed_mind', [$this->item($this->line($order, $p))]);

        $this->artisan('returns:watch')->assertExitCode(0);
        $this->assertSame(0, $this->adminNotices('return_receive_timeout'));

        DB::table('complaints')->where('id', $c->id)->update(['picked_up_at' => now()->subDays(4)]);
        $this->artisan('returns:watch')->assertExitCode(0);
        $this->artisan('returns:watch')->assertExitCode(0);
        $this->assertSame(1, $this->adminNotices('return_receive_timeout'));
        $this->assertNotNull($c->fresh()->receive_timeout_notified_at, 'once only');

        // The admin starts it with the button: the inspection clock runs
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/start-inspection")->assertOk();
        $c->refresh();
        $this->assertSame(Complaint::STATUS_RETURNED_TO_SELLER, $c->status);
        $this->assertNotNull($c->inspection_due_at);

        // Not inspected by the due date → one more admin notice
        DB::table('complaints')->where('id', $c->id)->update(['inspection_due_at' => now()->subHour()]);
        $this->artisan('returns:watch')->assertExitCode(0);
        $this->artisan('returns:watch')->assertExitCode(0);
        $this->assertNotNull($c->fresh()->inspection_timeout_notified_at);
        $this->assertSame(1, $this->adminNotices('return_inspection_timeout'));
    }

    public function test_inspection_cannot_happen_before_reception_or_twice(): void
    {
        [$p, [$a]] = $this->dress();
        $order = $this->orderDelivered([[$p, $a, 1]]);
        $c = $this->pickedUp($order, 'changed_mind', [$this->item($this->line($order, $p))]);

        $this->inspect($c, 'conform')->assertStatus(422);               // « confirmez d'abord la réception »
        $this->received($c);
        $this->inspect($c, 'conform')->assertOk();
        $this->inspect($c, 'conform')->assertStatus(422);               // already inspected
        $this->assertSame(5, $this->stock($a));
        // The old one-step reception is refused for new requests
        $this->as($this->seller)->patchJson("/api/seller/complaints/{$c->id}/receive", ['conditions' => [1 => 'resaleable']])->assertStatus(422);
    }

    // ── Old complaints finish with the old flow ───────────────────────────────

    public function test_a_complaint_filed_before_the_policy_finishes_with_the_old_flow(): void
    {
        $this->enable(false);
        [$p, [$a]] = $this->dress();
        $order = $this->orderDelivered([[$p, $a, 1]]);
        $line = $this->line($order, $p);

        $c = $this->complaint($this->ask($order, 'changed_mind', [$this->item($line)], 'return_refund', 1));
        $this->assertNull($c->policy_version);
        $this->enable(true);                                           // the policy goes live while it is open

        $this->approve($c);
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/schedule-pickup", ['carrier' => 'Intigo'])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/picked-up")->assertOk();
        $c->refresh();

        // Old flow: cash at pick-up AND the sale reversed at pick-up, no freeze, no locked amount
        $this->assertSame('cash', $c->refund_method);
        $this->assertNotNull($c->finance_applied_at);
        $this->assertNull($c->payout_frozen_at);
        $this->assertNull($c->refund_amount_locked);
        $this->assertSame(self::m('0'), self::m($this->so($order)->seller_net_amount));

        // The shop confirms reception with conditions, as before
        $this->as($this->seller)->patchJson("/api/seller/complaints/{$c->id}/receive", ['conditions' => [$line->id => 'resaleable']])->assertOk();
        $this->assertSame(Complaint::STATUS_REFUNDED, $c->fresh()->status);
        $this->assertSame(5, $this->stock($a));
        // and the new endpoints do not apply to it
        $this->as($this->seller)->post("/api/seller/complaints/{$c->id}/inspect", ['result' => 'conform'], ['Accept' => 'application/json'])->assertStatus(422);
    }

    // ── Tools ─────────────────────────────────────────────────────────────────

    public function test_flag_categories_command_is_a_dry_run_until_applied(): void
    {
        $food = Category::firstOrCreate(['slug' => 'food-grocery'], ['name' => 'Alimentation test ' . Str::random(3), 'name_ar' => 'x', 'name_fr' => 'x']);
        $before = (bool) $food->fresh()->no_changed_mind_return;

        $this->artisan('returns:flag-categories')->assertExitCode(0);
        $this->assertSame($before, (bool) $food->fresh()->no_changed_mind_return, 'dry run writes nothing');

        $this->artisan('returns:flag-categories', ['--apply' => true])->assertExitCode(0);
        $this->assertTrue((bool) $food->fresh()->no_changed_mind_return);
    }

    public function test_policy_texts_exist_in_the_three_languages_with_the_same_keys(): void
    {
        $fr = array_keys(require resource_path('lang/fr/returns_policy.php'));
        $this->assertSame($fr, array_keys(require resource_path('lang/en/returns_policy.php')));
        $this->assertSame($fr, array_keys(require resource_path('lang/ar/returns_policy.php')));
        $this->assertStringContainsString('48h', trans('returns_policy.promise', [], 'fr'));
        $this->assertStringContainsString('48h', trans('returns_policy.promise', [], 'en'));
    }

    public function test_admin_finance_lists_each_return_with_refund_payer_charges_and_dispute(): void
    {
        [$p, [$a]] = $this->dress(60, 'S', 1, 1);
        $order = $this->orderDelivered([[$p, $a, 1]]);
        $c = $this->pickedUp($order, 'damaged_product', [$this->item($this->line($order, $p))]);

        $row = collect($this->as($this->admin)->getJson('/api/admin/finance/returns?per_page=50')->assertOk()->json('data.data'))->firstWhere('id', $c->id);
        $this->assertSame('cash', $row['refund_method']);
        $this->assertSame('seller', $row['shipping_payer']);
        $this->assertEquals(69.0, $row['refund_amount']);
        $this->assertTrue($row['payout_held'], 'frozen until the inspection');
        $this->assertEquals(0.0, (float) $row['seller_charges']);

        $this->received($c);
        $this->inspect($c, 'conform')->assertOk();
        $row = collect($this->as($this->admin)->getJson('/api/admin/finance/returns?per_page=50')->json('data.data'))->firstWhere('id', $c->id);
        $this->assertFalse($row['payout_held']);
        $this->assertEquals(-13.28, (float) $row['seller_charges'], '9.000 + 4.280');
    }

    public function test_send_back_parcel_collects_the_refund_already_paid_plus_the_delivery_and_the_admin_can_edit_it(): void
    {
        [$p, [$a]] = $this->dress(60, 'S', 1, 5);
        $order = $this->orderDelivered([[$p, $a, 2]]);
        $line = $this->line($order, $p);

        $c = $this->pickedUp($order, 'changed_mind', [$this->item($line, 1)]);
        $this->received($c);
        $this->inspect($c, 'nonconform', ['comment' => 'Worn', 'photos' => $this->photos(1)])->assertOk();
        $paid = self::m($c->fresh()->refund_amount);

        // The modal shows what it will collect before the admin books the parcel
        $sug = $this->as($this->admin)->getJson("/api/admin/complaints/{$c->id}")->assertOk()->json('data.v2.send_back_suggested');
        $this->assertSame($paid, self::m($sug['refund']));
        $this->assertSame(self::m('4.280'), self::m($sug['fee']));
        $this->assertSame($paid + self::m('4.280'), self::m($sug['cod']), 'refund paid + delivery fee');

        // Default: exactly the suggestion
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/dispute", ['decision' => 'send_back', 'nid' => 'RET-1'])->assertOk();
        $c->refresh();
        $this->assertSame($paid + self::m('4.280'), self::m($c->sent_back_cod));
        $this->assertSame(self::m('4.280'), self::m($c->sent_back_fee));

        // The client is told what the courier will ask, and why
        $show = $this->as($this->customer)->getJson("/api/client/complaints/{$c->id}")->assertOk()->json('data.sent_back');
        $this->assertSame($paid + self::m('4.280'), self::m($show['cod']));
        $this->assertSame($paid, self::m($show['refunded']));

        // Editable by the admin
        $second = $this->pickedUp($order->fresh(), 'changed_mind', [$this->item($line, 1)]);
        $this->received($second);
        $this->inspect($second, 'nonconform', ['comment' => 'Stained', 'photos' => $this->photos(1)])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$second->id}/dispute", ['decision' => 'send_back', 'cod_amount' => 50, 'nid' => 'RET-2'])->assertOk();
        $this->assertSame(self::m('50'), self::m($second->fresh()->sent_back_cod));
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$second->id}/dispute", ['decision' => 'send_back', 'cod_amount' => -1])->assertStatus(422);
    }

    public function test_client_refusing_the_sent_back_parcel_reopens_the_dispute_and_the_admin_decides_again(): void
    {
        [$p, [$a]] = $this->dress();
        $order = $this->orderDelivered([[$p, $a, 1]]);
        $c = $this->pickedUp($order, 'changed_mind', [$this->item($this->line($order, $p))]);
        $this->received($c);
        $this->inspect($c, 'nonconform', ['comment' => 'Worn', 'photos' => $this->photos(1)])->assertOk();
        $netBefore = self::m($this->so($order)->seller_net_amount);

        // Nothing to refuse before a send-back was decided
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/sent-back-refused")->assertStatus(422);

        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/dispute", ['decision' => 'send_back', 'nid' => 'RET-9'])->assertOk();
        $this->assertSame('resolved', $c->fresh()->dispute_status);

        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/sent-back-refused", ['note' => 'Client said no at the door'])->assertOk();
        $c->refresh();

        $this->assertSame('open', $c->dispute_status, 'the item is back at the seller: decide again');
        $this->assertNotNull($c->sent_back_refused_at);
        $this->assertSame(1, $this->adminNotices('return_sent_back_refused'), 'the admin is notified');
        $this->assertNull($this->as($this->customer)->getJson("/api/client/complaints/{$c->id}")->json('data.sent_back'), 'no more send-back notice for the client');
        $this->assertSame($netBefore, self::m($this->so($order)->seller_net_amount), 'seller payout untouched');
        $this->assertSame(0, SellerAdjustment::where('complaint_id', $c->id)->count());

        // Refusing twice is not possible, and the admin can now take any decision
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/sent-back-refused")->assertStatus(422);
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/dispute", ['decision' => 'charge_seller', 'amount' => 20])->assertOk();
        $this->assertSame(self::m('-20'), $this->adjustments($c->fresh())[SellerAdjustment::TYPE_RETURN_DISPUTE]);
        $this->assertSame('resolved', $c->fresh()->dispute_status);
    }
    public function test_admin_can_read_and_change_the_policy_settings(): void
    {
        $this->as($this->admin)->putJson('/api/admin/returns-policy', ['v2_enabled' => false, 'inspection_days' => 5])->assertOk();
        $s = $this->as($this->admin)->getJson('/api/admin/returns-policy')->assertOk()->json('data');
        $this->assertFalse($s['v2_enabled']);
        $this->assertSame(5, $s['inspection_days']);
        $this->as($this->customer)->getJson('/api/admin/returns-policy')->assertStatus(403);
    }
}
