<?php

namespace Tests\Feature\Orders;

use App\Helpers\PlatformUser;
use App\Models\Cart;
use App\Models\Complaint;
use App\Models\ComplaintEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RefundDeliveryTask;
use App\Models\SellerAdjustment;
use App\Models\SellerApplication;
use App\Models\SellerOrder;
use App\Models\User;
use App\Notifications\Buyer\ComplaintNotification;
use App\Notifications\Returns\ReturnAdminNotification;
use App\Notifications\Returns\ReturnSellerNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Return + refund, end to end: request (whole order or items × qty, proof
 * photo) → seller decision → admin → pick-up → reception (restock resaleable
 * units on the exact variant, once) → refund (wallet / transfer / card),
 * with the sale reversed on lines, sub-order, order status and payouts.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Orders/ReturnFlowTest.php
 */
class ReturnFlowTest extends TestCase
{
    use DatabaseTransactions;

    private const ADDRESS = [
        'recipient_name' => 'Sami Ben Salah',
        'phone'          => '22 123 456',
        'wilaya'         => 'Ben Arous',
        'delegation'     => 'El Mourouj',
        'address'        => '12 rue de la Liberté',
        'postal_code'    => '2074',
    ];

    private User $seller;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.shipping_cost' => 8.0, 'platform.return_shipping_fee' => 8.0]);
        Notification::fake();
        Storage::fake('public');

        if (!DB::table('users')->where('id', 1)->exists()) {
            DB::table('users')->insert([
                'id' => 1, 'name' => "CHOOSE'Tounsi", 'email' => 'platform-' . Str::random(6) . '@test.local',
                'password' => bcrypt('x'), 'role' => 'seller', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        PlatformUser::reset();
        // These flows cover online-paid orders too (card / D17 / wallet refunds):
        // the methods are switched on here, they are "Coming soon" by default.
        \App\Models\PlatformSetting::flushCache();
        app(\App\Services\Payments\CheckoutPaymentMethods::class)->set(['card' => true, 'd17' => true, 'wallet' => true], null);

        $this->seller = $this->makeUser('seller');
        SellerApplication::create([
            'user_id' => $this->seller->id, 'full_name' => 'Mohamed Trabelsi', 'phone_number' => '55111222',
            'business_name' => 'Atelier ' . Str::random(5), 'business_category' => 'crafts', 'business_description' => 'Test shop',
            'wilaya' => 'Sfax', 'city' => 'Sakiet Ezzit', 'pickup_address' => 'Route de Tunis km 5',
            'pickup_postal_code' => '3021', 'status' => 'approved',
        ]);
        $this->admin = $this->makeUser('admin');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $role): User
    {
        return $this->withCompleteProfile(User::create([
            'name' => ucfirst($role) . ' ' . Str::random(5), 'email' => $role . '_' . Str::random(10) . '@test.local',
            'password' => bcrypt('secret-password'), 'role' => $role, 'is_active' => true, 'locale' => 'fr',
        ]));
    }

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken]);
    }

    /** A product with two variants (stock 10 each) — returns [product, variantA, variantB]. */
    private function product(float $price = 30): array
    {
        $name = 'Sac ' . Str::random(6);
        $p = Product::create([
            'seller_id' => $this->seller->id, 'name' => $name, 'slug' => Str::slug($name),
            'price' => $price, 'stock' => 20, 'is_approved' => true, 'is_active' => true,
        ]);
        $a = ProductVariant::create(['product_id' => $p->id, 'sku' => 'A-' . Str::random(6), 'stock' => 10, 'is_active' => true]);
        $b = ProductVariant::create(['product_id' => $p->id, 'sku' => 'B-' . Str::random(6), 'stock' => 10, 'is_active' => true]);
        return [$p, $a, $b];
    }

    /** @param array<array{0: Product, 1: ?ProductVariant, 2: int}> $lines */
    private function deliveredOrder(array $lines, string $payment = 'cod', ?User $buyer = null): Order
    {
        $buyer ??= $this->makeUser('client');
        if ($payment === 'wallet') {
            $buyer->forceFill(['wallet_balance' => 1000])->save();
        }
        foreach ($lines as [$product, $variant, $qty]) {
            Cart::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant?->id, 'quantity' => $qty]);
        }
        $res = $this->as($buyer)->postJson('/api/checkout', self::ADDRESS + ['payment_method' => $payment])->assertCreated();

        $order = Order::findOrFail($res->json('order_id'));
        DB::table('seller_orders')->where('order_id', $order->id)->update(['status' => 'delivered']);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'delivered', 'updated_at' => now()]);
        return $order->fresh();
    }

    private function line(Order $order, ProductVariant $variant): OrderItem
    {
        return OrderItem::where('order_id', $order->id)->where('variant_id', $variant->id)->firstOrFail();
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('proof.jpg', 400, 300);
    }

    private function request(Order $order, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->as($order->user)->post('/api/client/complaints', $payload + [
            'order_id'       => $order->id,
            'complaint_type' => 'damaged_product',
            'description'    => 'The item arrived broken, the box was crushed.',
            'images'         => [$this->photo()],
        ], ['Accept' => 'application/json']);
    }

    /** requested → seller accepts → admin approves → pick-up → picked up. */
    private function bringBack(Complaint $c): Complaint
    {
        $this->as($this->seller)->patchJson("/api/seller/complaints/{$c->id}/approve", ['seller_note' => 'Sorry'])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/approve")->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/schedule-pickup", ['carrier' => 'Aramex'])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/picked-up")->assertOk();
        return $c->fresh();
    }

    // ── Request ───────────────────────────────────────────────────────────────

    public function test_exchange_is_gone_and_a_proof_photo_is_required(): void
    {
        [$p, $a] = $this->product();
        $order = $this->deliveredOrder([[$p, $a, 1]]);
        $line  = $this->line($order, $a);

        $this->request($order, ['resolution_type' => 'exchange', 'items' => [['order_item_id' => $line->id, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('resolution_type');

        $this->as($order->user)->postJson('/api/client/complaints', [
            'order_id' => $order->id, 'complaint_type' => 'damaged_product',
            'description' => 'The item arrived broken, the box was crushed.',
            'items' => [['order_item_id' => $line->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('images');

        $this->assertSame(0, Complaint::where('order_id', $order->id)->count());
    }

    public function test_quantity_cannot_exceed_what_is_left_to_return(): void
    {
        [$p, $a] = $this->product();
        $order = $this->deliveredOrder([[$p, $a, 2]]);
        $line  = $this->line($order, $a);

        $this->request($order, ['items' => [['order_item_id' => $line->id, 'quantity' => 3]]])->assertStatus(422);
        $this->request($order, ['items' => [['order_item_id' => $line->id, 'quantity' => 1]]])->assertCreated();
        // 1 unit already in a live return: only 1 left
        $this->request($order, ['items' => [['order_item_id' => $line->id, 'quantity' => 2]]])->assertStatus(422);
        $this->request($order, ['items' => [['order_item_id' => $line->id, 'quantity' => 1]]])->assertCreated();

        $eligible = collect($this->as($order->user)->getJson('/api/client/complaints/eligible-orders')->assertOk()->json('data'));
        $this->assertNull($eligible->firstWhere('id', $order->id), 'nothing left to return');
    }

    // ── Full return, COD → wallet ─────────────────────────────────────────────

    public function test_full_return_cod_paid_back_in_cash_by_the_courier_and_restocks_only_resaleable_variant(): void
    {
        [$p, $a, $b] = $this->product();
        $order = $this->deliveredOrder([[$p, $a, 1], [$p, $b, 2]]);
        $la = $this->line($order, $a);
        $lb = $this->line($order, $b);
        $paid = round((float) $la->net_total + (float) $lb->net_total, 3);

        $res = $this->request($order, ['return_all' => 1])->assertCreated();
        $c = Complaint::findOrFail($res->json('data.id'));
        $this->assertSame('full', $c->return_scope);
        $this->assertSame('seller', $c->shipping_payer, 'damaged item: the seller pays the return shipping');
        $this->assertEqualsWithDelta($paid, (float) $c->refund_amount, 0.001);
        $this->assertStringStartsWith('RET-', $c->reference);
        Notification::assertSentTo($order->user, ComplaintNotification::class, fn($n) => $n->event === 'received');

        // Seller accepts → admin notified (bell + e-mail) with a link to the return
        $this->as($this->seller)->patchJson("/api/seller/complaints/{$c->id}/approve")->assertOk();
        Notification::assertSentTo($this->admin, ReturnAdminNotification::class, function ($n, $channels) use ($c) {
            $db = $n->toDatabase($this->admin);
            return in_array('mail', $channels) && $db['link'] === '/complaints?id=' . $c->id && $db['type'] === 'return_seller_accepted';
        });

        // Steps can't be skipped: no refund nor reception before approval / pick-up
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/refund", ['method' => 'wallet'])->assertStatus(422);
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/approve")->assertOk();
        $this->assertNotNull(RefundDeliveryTask::where('complaint_id', $c->id)->first(), 'pick-up task in the delivery app');
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/receive", ['conditions' => [$la->id => 'resaleable', $lb->id => 'damaged']])->assertStatus(422);

        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/schedule-pickup")->assertOk();
        $this->assertNull(RefundDeliveryTask::where('complaint_id', $c->id)->first(), 'outside carrier: the unassigned courier task is dropped');
        $balance = (float) $order->user->fresh()->wallet_balance;

        // Cash on delivery: the courier checks the item and pays the client back in cash at pick-up
        $this->as($this->admin)->getJson("/api/admin/complaints/{$c->id}")->assertJsonPath('data.refund_methods', ['cash'])->assertJsonPath('data.cash_refund', true);
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/picked-up", ['courier' => 'Karim (Aramex)'])->assertOk();
        $c->refresh();
        $this->assertSame(Complaint::STATUS_PICKED_UP, $c->status);
        $this->assertSame('cash', $c->refund_method);
        $this->assertSame('CASH · Karim (Aramex)', $c->refund_reference);
        $this->assertNotNull($c->refunded_at);
        $this->assertEqualsWithDelta($balance, (float) $order->user->fresh()->wallet_balance, 0.001, 'no wallet credit: paid in cash');
        $this->assertSame('refunded', $order->fresh()->status, 'sale reversed when the cash is paid');
        Notification::assertSentTo($order->user, ComplaintNotification::class,
            fn($n) => $n->event === 'refunded' && str_contains($n->toDatabase($order->user)['body'], 'en espèces'));

        // Nothing else can be refunded
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/refund", ['method' => 'wallet'])->assertStatus(422);

        $stockA = (int) $a->fresh()->stock;
        $stockB = (int) $b->fresh()->stock;
        $this->as($this->seller)->patchJson("/api/seller/complaints/{$c->id}/receive", [
            'conditions' => [$la->id => 'resaleable', $lb->id => 'damaged'],
        ])->assertOk();
        $this->assertSame($stockA + 1, (int) $a->fresh()->stock, 'resaleable unit back on its own variant');
        $this->assertSame($stockB, (int) $b->fresh()->stock, 'damaged units are not restocked');

        // Idempotent: a second reception is refused and restocks nothing
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/receive", ['conditions' => [$la->id => 'resaleable', $lb->id => 'resaleable']])->assertStatus(422);
        $this->assertSame($stockA + 1, (int) $a->fresh()->stock);

        // Inspected: the return is complete (the client was already paid back)
        $c->refresh();
        $this->assertSame(Complaint::STATUS_REFUNDED, $c->status);
        $this->assertEqualsWithDelta($paid, (float) $c->refund_amount, 0.001);
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $order->user_id, 'reason' => 'return_refund']);

        // Order + sub-order: "Returned (Refunded)", sale reversed
        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertSame('full', $order->return_status);
        $so = SellerOrder::where('order_id', $order->id)->first();
        $this->assertSame('refunded', $so->status);
        $this->assertEquals(0, (float) $so->subtotal);
        $this->assertEquals(0, (float) $so->getAttribute('commission_amount'));
        $this->assertSame('cancelled', $so->getAttribute('payout_status'));
        $this->assertSame(0, (int) $la->fresh()->quantity);
        $this->assertSame(2, (int) $lb->fresh()->returned_quantity);
        $this->assertSame('returned', $lb->fresh()->return_state);

        // Return shipping charged to the seller as an adjustment (seller at fault)
        $this->assertEquals(-8.0, (float) SellerAdjustment::where('complaint_id', $c->id)->where('type', 'return_shipping')->value('amount'));

        // Refund is final
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/refund", ['method' => 'wallet'])->assertStatus(422);

        // Client notified at every key step; the tracking shows the whole timeline
        foreach (['seller_accepted', 'approved', 'pickup_scheduled', 'refunded', 'returned'] as $event) {
            Notification::assertSentTo($order->user, ComplaintNotification::class, fn($n) => $n->event === $event);
        }
        $timeline = $this->as($order->user)->getJson("/api/client/complaints/{$c->id}")->assertOk()->json('data.timeline');
        $this->assertTrue(collect($timeline)->every(fn($s) => $s['done']));

        $orders = $this->as($order->user)->getJson("/api/client/orders/{$order->id}")->assertOk();
        $this->assertSame('refunded', $orders->json('data.display_status'));
        $this->assertSame($c->reference, $orders->json('data.returns.0.reference'));
    }

    // ── Partial return with quantity + coupon, client pays the return ─────────

    public function test_partial_return_reverses_the_price_actually_paid_proportionally(): void
    {
        [$p, $a, $b] = $this->product(50);
        $order = $this->deliveredOrder([[$p, $a, 3], [$p, $b, 1]]);
        $la = $this->line($order, $a);
        $so = SellerOrder::where('order_id', $order->id)->first();

        // A seller coupon took 15 DT off line A (3 × 50 = 150 → 135 paid)
        DB::table('order_items')->where('id', $la->id)->update(['discount_amount' => 15, 'net_total' => 135, 'commission_amount' => 13.5, 'seller_amount' => 121.5]);
        DB::table('seller_orders')->where('id', $so->id)->update(['discount_amount' => 15]);
        $so->refresh();
        $commissionBefore = (float) $so->getAttribute('commission_amount');
        $subtotalBefore   = (float) $so->subtotal;

        $res = $this->request($order, [
            'complaint_type' => 'other', 'other_reason' => 'Changed my mind',
            'items' => [['order_item_id' => $la->id, 'quantity' => 1]],
        ])->assertCreated();
        $c = Complaint::findOrFail($res->json('data.id'));
        $this->assertSame('partial', $c->return_scope);
        $this->assertSame('client', $c->shipping_payer);
        $this->assertEqualsWithDelta(45.0, (float) $c->items_amount, 0.001, '1 of 3 units at the price paid after coupon');
        $this->assertEqualsWithDelta(37.0, (float) $c->refund_amount, 0.001, 'minus the 8 DT return shipping');

        $c = $this->bringBack($c);
        $this->assertSame('cash', $c->refund_method, 'COD: 37 DT handed back by the courier at pick-up');
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/refund", ['method' => 'd17', 'reference' => 'D17-1'])->assertStatus(422);
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/receive", ['conditions' => [$la->id => 'resaleable']])->assertOk();
        $this->assertSame(Complaint::STATUS_REFUNDED, $c->fresh()->status);

        $line = $la->fresh();
        $this->assertSame(2, (int) $line->quantity);
        $this->assertSame(1, (int) $line->returned_quantity);
        $this->assertSame(3, $line->ordered_quantity);
        $this->assertSame('partially_returned', $line->return_state);
        $this->assertEqualsWithDelta(90.0, (float) $line->net_total, 0.001);
        $this->assertEqualsWithDelta(9.0, (float) $line->commission_amount, 0.001);

        $so->refresh();
        $this->assertSame('delivered', $so->status, 'kept items still count as a sale');
        $this->assertSame('partial', $so->return_status);
        $this->assertSame('partially_returned', $so->display_status);
        $this->assertEqualsWithDelta($subtotalBefore - 50, (float) $so->subtotal, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $so->discount_amount, 0.001);
        $this->assertEqualsWithDelta($commissionBefore - 4.5, (float) $so->getAttribute('commission_amount'), 0.001);

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame('partially_returned', $order->display_status);
        $this->assertSame(0, SellerAdjustment::where('complaint_id', $c->id)->count(), 'client paid the return, seller not charged');

        // Filters
        $ids = collect($this->as($this->admin)->getJson('/api/admin/orders?status=partially_returned&per_page=100')->assertOk()->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($order->id));
        $ids = collect($this->as($this->seller)->getJson('/api/seller/orders?status=partially_returned')->assertOk()->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($so->id));
    }

    // ── Already paid out → debit on the next settlement ───────────────────────

    public function test_return_after_payout_creates_a_debit_on_the_next_settlement(): void
    {
        [$p, $a] = $this->product(40);
        $order = $this->deliveredOrder([[$p, $a, 1]]);
        $la = $this->line($order, $a);
        $so = SellerOrder::where('order_id', $order->id)->first();
        DB::table('seller_orders')->where('id', $so->id)->update(['payout_status' => 'paid', 'money_received_at' => now(), 'settled_at' => now()]);
        $netBefore = (float) DB::table('seller_orders')->where('id', $so->id)->value('seller_net_amount');

        $c = Complaint::findOrFail($this->request($order, ['return_all' => 1])->assertCreated()->json('data.id'));
        $c = $this->bringBack($c);
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/receive", ['conditions' => [$la->id => 'resaleable']])->assertOk();
        $this->assertSame(Complaint::STATUS_REFUNDED, $c->fresh()->status);

        $this->assertEqualsWithDelta($netBefore, (float) DB::table('seller_orders')->where('id', $so->id)->value('seller_net_amount'), 0.001, 'paid history untouched');
        $debit = SellerAdjustment::where('complaint_id', $c->id)->where('type', 'return_debit')->first();
        $this->assertNotNull($debit);
        $this->assertEqualsWithDelta(-$netBefore, (float) $debit->amount, 0.001);
        $this->assertSame($this->admin->id, $debit->created_by);

        // Next settlement carries the debits
        [$p2, $a2] = $this->product(100);
        $next = $this->deliveredOrder([[$p2, $a2, 1]]);
        $nextSo = SellerOrder::where('order_id', $next->id)->first();
        DB::table('seller_orders')->where('id', $nextSo->id)->update(['payout_status' => 'ready', 'money_received_at' => now()]);
        $nextNet = (float) DB::table('seller_orders')->where('id', $nextSo->id)->value('seller_net_amount');

        $batch = $this->as($this->admin)->postJson('/api/admin/settlements/create', ['seller_id' => $this->seller->id, 'batch_date' => now()->addDays(3)->toDateString()])
            ->assertOk()->json('data');
        $debits = (float) SellerAdjustment::where('seller_id', $this->seller->id)->sum('amount');
        $this->assertEqualsWithDelta($debits, (float) $batch['total_adjustments'], 0.001);
        $this->assertEqualsWithDelta($nextNet + $debits, (float) $batch['total_seller_payout'], 0.001);

        // Cancelling a draft releases orders + debits (it used to mark the batch paid)
        $this->as($this->admin)->postJson("/api/admin/settlements/{$batch['id']}/cancel")->assertOk();
        $this->assertSame('cancelled', DB::table('settlement_batches')->where('id', $batch['id'])->value('status'));
        $this->assertSame(0, SellerAdjustment::where('settlement_batch_id', $batch['id'])->count());
    }

    // ── Seller refusal → client escalates → admin overrides ───────────────────

    public function test_seller_refusal_can_be_escalated_and_overridden_by_admin(): void
    {
        [$p, $a] = $this->product();
        $order = $this->deliveredOrder([[$p, $a, 1]]);
        $c = Complaint::findOrFail($this->request($order, ['return_all' => 1])->assertCreated()->json('data.id'));

        // Another seller / client can't touch it
        $other = $this->makeUser('seller');
        $this->as($other)->patchJson("/api/seller/complaints/{$c->id}/reject", ['rejection_reason' => 'Not my product at all.'])->assertNotFound();
        $this->as($this->makeUser('client'))->patchJson("/api/client/complaints/{$c->id}/escalate")->assertNotFound();
        $this->as($order->user)->patchJson("/api/admin/complaints/{$c->id}/approve")->assertForbidden();

        // The client can't escalate before a refusal
        $this->as($order->user)->patchJson("/api/client/complaints/{$c->id}/escalate")->assertStatus(422);

        $this->as($this->seller)->patchJson("/api/seller/complaints/{$c->id}/reject", ['rejection_reason' => 'The photo shows normal wear.'])->assertOk();
        Notification::assertSentTo($order->user, ComplaintNotification::class,
            fn($n) => $n->event === 'seller_rejected' && $n->toDatabase($order->user)['data']['rejection_reason'] === 'The photo shows normal wear.');
        $this->assertTrue($c->fresh()->can_escalate);

        $this->as($order->user)->patchJson("/api/client/complaints/{$c->id}/escalate", ['note' => 'It was broken on arrival.'])->assertOk();
        Notification::assertSentTo($this->admin, ReturnAdminNotification::class, fn($n) => $n->toDatabase($this->admin)['type'] === 'return_escalated');

        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/approve", ['note' => 'Photo is clear'])->assertOk();
        $this->assertSame(Complaint::STATUS_ADMIN_APPROVED, $c->fresh()->status);
        Notification::assertSentTo($this->seller, ReturnSellerNotification::class);

        $statuses = ComplaintEvent::where('complaint_id', $c->id)->orderBy('id')->pluck('status')->all();
        $this->assertSame(['requested', 'seller_rejected', 'escalated', 'admin_approved'], $statuses, 'audit trail');
        $this->assertSame($this->admin->id, ComplaintEvent::where('complaint_id', $c->id)->where('status', 'admin_approved')->value('actor_id'));
    }

    public function test_admin_can_override_a_seller_acceptance(): void
    {
        [$p, $a] = $this->product();
        $order = $this->deliveredOrder([[$p, $a, 1]]);
        $c = Complaint::findOrFail($this->request($order, ['return_all' => 1])->assertCreated()->json('data.id'));

        $this->as($this->seller)->patchJson("/api/seller/complaints/{$c->id}/approve")->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/reject", ['rejection_reason' => 'Return window abuse detected.'])->assertOk();
        $this->assertSame(Complaint::STATUS_REJECTED, $c->fresh()->status);
        Notification::assertSentTo($order->user, ComplaintNotification::class, fn($n) => $n->event === 'rejected');
    }

    // ── Prepaid orders ────────────────────────────────────────────────────────

    public function test_wallet_paid_order_is_refunded_to_the_wallet_only(): void
    {
        [$p, $a] = $this->product(25);
        $order = $this->deliveredOrder([[$p, $a, 2]], 'wallet');
        $la = $this->line($order, $a);

        $c = Complaint::findOrFail($this->request($order, ['items' => [['order_item_id' => $la->id, 'quantity' => 1]]])->assertCreated()->json('data.id'));
        $c = $this->bringBack($c);
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/receive", ['conditions' => [$la->id => 'damaged']])->assertOk();

        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/refund", ['method' => 'bank_transfer', 'reference' => 'X'])->assertStatus(422);

        $before = (float) $order->user->fresh()->wallet_balance;
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/refund", ['method' => 'wallet'])->assertOk();
        $this->assertEqualsWithDelta($before + 25, (float) $order->user->fresh()->wallet_balance, 0.001);
        $this->assertSame('partially_returned', $order->fresh()->display_status);
    }

    // ── Delivery app (in-house courier) ───────────────────────────────────────

    public function test_delivery_app_task_drives_pickup_statuses_and_asks_the_seller_to_inspect(): void
    {
        [$p, $a] = $this->product();
        $order = $this->deliveredOrder([[$p, $a, 1]]);
        $c = Complaint::findOrFail($this->request($order, ['return_all' => 1])->assertCreated()->json('data.id'));
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/approve")->assertOk();
        $task = RefundDeliveryTask::where('complaint_id', $c->id)->firstOrFail();

        $dispatcher = $this->makeUser('delivery_admin');
        $courier    = $this->makeUser('delivery_guy');

        $this->as($dispatcher)->postJson("/api/delivery/refunds/{$task->id}/assign", ['delivery_guy_id' => $courier->id])->assertOk();
        $this->assertSame(Complaint::STATUS_PICKUP_SCHEDULED, $c->fresh()->status);
        $this->assertSame($c->reference, $this->as($courier)->getJson('/api/delivery/my-refunds')->assertOk()->json('data.data.0.reference'));

        $this->assertEqualsWithDelta((float) $c->refund_amount, $this->as($courier)->getJson('/api/delivery/my-refunds')->json('data.data.0.cash_to_pay'), 0.001);
        $this->as($courier)->putJson("/api/delivery/refunds/{$task->id}/status", ['status' => 'picked_up'])->assertOk();
        $this->assertSame(Complaint::STATUS_PICKED_UP, $c->fresh()->status);
        $this->assertSame('CASH · ' . $courier->name, $c->fresh()->refund_reference, 'the courier paid the client back');

        // Parcel at the shop: nothing refunded yet, the seller must inspect it
        $this->as($courier)->putJson("/api/delivery/refunds/{$task->id}/status", ['status' => 'completed'])->assertOk();
        $this->assertSame(Complaint::STATUS_PICKED_UP, $c->fresh()->status);
        Notification::assertSentTo($this->seller, ReturnSellerNotification::class,
            fn($n) => $n->toDatabase($this->seller)['type'] === 'return_delivered_to_seller');
    }

    // ── Return slip ───────────────────────────────────────────────────────────

    public function test_admin_detail_and_return_slip_pdf(): void
    {
        [$p, $a] = $this->product();
        $order = $this->deliveredOrder([[$p, $a, 2]]);
        $c = Complaint::findOrFail($this->request($order, ['return_all' => 1])->assertCreated()->json('data.id'));
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/approve")->assertOk();

        $show = $this->as($this->admin)->getJson("/api/admin/complaints/{$c->id}")->assertOk();
        $this->assertSame($order->order_number, $show->json('data.order.order_number'));
        $this->assertSame(2, $show->json('data.complained_items.0.return_quantity'));
        $this->assertSame('Sami Ben Salah', $show->json('data.client_address.name'));
        $this->assertSame('Route de Tunis km 5', $show->json('data.seller_pickup.address'));
        $this->assertSame(['cash'], $show->json('data.refund_methods'));
        $this->assertCount(1, $show->json('data.image_urls'));

        $pdf = $this->as($this->admin)->get("/api/admin/complaints/{$c->id}/return-slip")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertSame(1, preg_match_all('#/Type\s*/Page[^s]#', $pdf->getContent()), 'one page');

        $this->as($this->seller)->get("/api/admin/complaints/{$c->id}/return-slip")->assertForbidden();
    }

    public function test_full_return_of_a_free_delivery_parcel_keeps_the_contribution_charged(): void
    {
        [$p, $a] = $this->product(40);
        $p->update(['delivery_fee' => 0]);   // the seller offers free delivery
        $order = $this->deliveredOrder([[$p, $a, 1]]);
        $so    = \App\Models\SellerOrder::where('order_id', $order->id)->firstOrFail();
        $contribution = (float) $so->getAttribute('seller_shipping_charge');
        $this->assertGreaterThan(0, $contribution);
        $this->assertTrue((bool) $so->getAttribute('is_free_delivery'));

        $c = Complaint::findOrFail($this->request($order, ['return_all' => 1])->assertCreated()->json('data.id'));
        $this->bringBack($c);   // COD: cash paid back at pick-up, sale reversed

        $so->refresh();
        $this->assertSame('refunded', $so->status);
        // The delivery was made and the agency paid: the contribution stays charged
        $this->assertEqualsWithDelta($contribution, (float) $so->getAttribute('seller_shipping_charge'), 0.0005);
        $this->assertEqualsWithDelta(0, (float) $so->getAttribute('seller_net_amount'), 0.0005);
        $this->assertSame('cancelled', $so->getAttribute('payout_status'));
        $debit = SellerAdjustment::where('seller_order_id', $so->id)->where('type', SellerAdjustment::TYPE_FREE_DELIVERY)->firstOrFail();
        $this->assertEqualsWithDelta(-$contribution, (float) $debit->amount, 0.0005);
        $this->assertNull($debit->settlement_batch_id, 'deducted from the next settlement');
    }

    // ── Refunds vs payouts, through the real money flow ───────────────────────
    // (delivered by the admin → remittance confirmed → settlement batch paid)

    /** Real COD order, delivered through the parcel workflow (cash collected). */
    private function deliveredForReal(array $lines): Order
    {
        $buyer = $this->makeUser('client');
        foreach ($lines as [$product, $variant, $qty]) {
            Cart::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant?->id, 'quantity' => $qty]);
        }
        $order = Order::findOrFail($this->as($buyer)->postJson('/api/checkout', self::ADDRESS + ['payment_method' => 'cod'])->assertCreated()->json('order_id'));
        $so = SellerOrder::where('order_id', $order->id)->firstOrFail();
        foreach (['confirmed', 'out_for_delivery'] as $step) {
            $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/status", ['status' => $step])->assertOk();
        }
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/delivered")->assertOk();
        return $order->fresh();
    }

    private function remit(SellerOrder $so): void
    {
        $this->as($this->admin)->postJson("/api/admin/finance/confirm-money/{$so->id}")->assertOk();
    }

    /** Settles everything payable for the seller and confirms (pays) the batch. */
    private function settle(string $date): array
    {
        $batch = $this->as($this->admin)->postJson('/api/admin/settlements/create', ['seller_id' => $this->seller->id, 'batch_date' => $date])->assertOk()->json('data');
        $this->as($this->admin)->postJson("/api/admin/settlements/{$batch['id']}/confirm")->assertOk();
        return $batch;
    }

    public function test_full_refund_after_the_payout_was_paid_is_debited_on_the_next_settlement(): void
    {
        [$p, $a] = $this->product(40);
        $order = $this->deliveredForReal([[$p, $a, 1]]);
        $la = $this->line($order, $a);
        $so = SellerOrder::where('order_id', $order->id)->firstOrFail();
        $this->remit($so);
        $first = $this->settle(now()->toDateString());
        $so->refresh();
        $this->assertSame('paid', $so->getAttribute('payout_status'));
        $paidNet = (float) $so->getAttribute('seller_net_amount');
        $this->assertGreaterThan(0, $paidNet);
        $this->assertEqualsWithDelta($paidNet, (float) $first['total_seller_payout'], 0.001);

        // Full return, refunded (COD: cash back at pick-up), inspected
        $c = Complaint::findOrFail($this->request($order, ['return_all' => 1])->assertCreated()->json('data.id'));
        $c = $this->bringBack($c);
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/receive", ['conditions' => [$la->id => 'resaleable']])->assertOk();

        // Paid history untouched, the parcel is "refunded" (never "cancelled")
        $so->refresh();
        $this->assertSame('refunded', $so->status);
        $this->assertSame('paid', $so->getAttribute('payout_status'));
        $this->assertEqualsWithDelta($paidNet, (float) $so->getAttribute('seller_net_amount'), 0.001);

        // The whole paid share comes back as a debit (plus the seller-paid return shipping)
        $debit = SellerAdjustment::where('complaint_id', $c->id)->where('type', SellerAdjustment::TYPE_RETURN_DEBIT)->sole();
        $this->assertEqualsWithDelta(-$paidNet, (float) $debit->amount, 0.001);
        $owed = (float) SellerAdjustment::where('seller_id', $this->seller->id)->whereNull('applied_at')->sum('amount');
        $this->assertEqualsWithDelta(-$paidNet - 8.0, $owed, 0.001, 'debit + return shipping, nothing lost');

        // …deducted from the next settlement, once
        [$p2, $a2] = $this->product(100);
        $next = SellerOrder::where('order_id', $this->deliveredForReal([[$p2, $a2, 1]])->id)->firstOrFail();
        $this->remit($next);
        $nextNet = (float) $next->fresh()->getAttribute('seller_net_amount');
        $batch = $this->settle(now()->addDay()->toDateString());
        $this->assertEqualsWithDelta($owed, (float) $batch['total_adjustments'], 0.001);
        $this->assertEqualsWithDelta($nextNet + $owed, (float) $batch['total_seller_payout'], 0.001);
        $this->assertSame(0, SellerAdjustment::where('seller_id', $this->seller->id)->whereNull('applied_at')->count(), 'every debit applied');
        $this->assertNotNull($debit->fresh()->applied_at);
        $this->assertSame((int) $batch['id'], (int) $debit->fresh()->settlement_batch_id);
    }

    public function test_full_refund_before_the_payout_cancels_it_and_the_seller_gets_nothing(): void
    {
        [$p, $a] = $this->product(40);
        $order = $this->deliveredForReal([[$p, $a, 1]]);
        $la = $this->line($order, $a);
        $so = SellerOrder::where('order_id', $order->id)->firstOrFail();
        $this->remit($so);
        $this->assertSame('ready', $so->fresh()->getAttribute('payout_status'), 'payable, not paid yet');

        $c = Complaint::findOrFail($this->request($order, ['return_all' => 1])->assertCreated()->json('data.id'));
        $c = $this->bringBack($c);
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/receive", ['conditions' => [$la->id => 'resaleable']])->assertOk();

        $so->refresh();
        $this->assertSame('refunded', $so->status);
        $this->assertSame('cancelled', $so->getAttribute('payout_status'));
        $this->assertEqualsWithDelta(0, (float) $so->getAttribute('seller_net_amount'), 0.001);
        $this->assertEqualsWithDelta(0, (float) $so->getAttribute('commission_amount'), 0.001);
        $this->assertSame(0, SellerAdjustment::where('complaint_id', $c->id)->where('type', SellerAdjustment::TYPE_RETURN_DEBIT)->count(), 'nothing was paid, nothing to claw back');

        // Not payable any more: no settlement can include it
        $this->assertSame([], collect($this->as($this->admin)->getJson("/api/admin/finance/pending-payouts?seller_id={$this->seller->id}")->json('data.data'))->pluck('id')->all());
        $this->as($this->admin)->postJson('/api/admin/settlements/create', ['seller_id' => $this->seller->id, 'batch_date' => now()->toDateString()])->assertStatus(422);
        $this->assertNull($so->fresh()->getAttribute('settlement_batch_id'));
    }

    public function test_partial_refund_reduces_the_payout_by_the_refunded_units_seller_share(): void
    {
        [$p, $a, $b] = $this->product(50);
        $order = $this->deliveredForReal([[$p, $a, 3], [$p, $b, 1]]);
        $la = $this->line($order, $a);
        $so = SellerOrder::where('order_id', $order->id)->firstOrFail();
        $netBefore   = (float) $so->getAttribute('seller_net_amount');
        $unitShare   = round((float) $la->seller_amount / 3, 3);   // seller's share of one unit of line A
        $this->assertGreaterThan(0, $unitShare);

        $c = Complaint::findOrFail($this->request($order, [
            'complaint_type' => 'other', 'other_reason' => 'Changed my mind',
            'items' => [['order_item_id' => $la->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id'));
        $c = $this->bringBack($c);
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/receive", ['conditions' => [$la->id => 'resaleable']])->assertOk();

        $so->refresh();
        $this->assertSame('delivered', $so->status);
        $this->assertSame('partial', $so->return_status);
        $this->assertSame('pending', $so->getAttribute('payout_status'), 'kept units are still owed');
        $this->assertEqualsWithDelta($netBefore - $unitShare, (float) $so->getAttribute('seller_net_amount'), 0.001);
        $this->assertEqualsWithDelta(round((float) $la->seller_amount - $unitShare, 3), (float) $la->fresh()->seller_amount, 0.001);

        // Settled at the reduced amount, with no extra debit
        $this->remit($so);
        $batch = $this->settle(now()->toDateString());
        $this->assertEqualsWithDelta($netBefore - $unitShare, (float) $batch['total_seller_payout'], 0.001);
        $this->assertSame(0, SellerAdjustment::where('complaint_id', $c->id)->count(), 'client paid the return shipping');
    }
}
