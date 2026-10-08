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

    public function test_full_return_cod_refunded_to_wallet_restocks_only_resaleable_variant(): void
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
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/picked-up")->assertOk();

        // Never refunded before the item is back
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

        $balance = (float) $order->user->fresh()->wallet_balance;
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/refund", ['method' => 'wallet'])->assertOk();

        $c->refresh();
        $this->assertSame(Complaint::STATUS_REFUNDED, $c->status);
        $this->assertStringStartsWith('WALLET-TX-', $c->refund_reference);
        $this->assertEqualsWithDelta($balance + $paid, (float) $order->user->fresh()->wallet_balance, 0.001);
        $this->assertDatabaseHas('wallet_transactions', ['user_id' => $order->user_id, 'reason' => 'return_refund', 'order_id' => $order->id]);

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
        foreach (['seller_accepted', 'approved', 'pickup_scheduled', 'picked_up', 'returned', 'refunded'] as $event) {
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
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/receive", ['conditions' => [$la->id => 'resaleable']])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/refund", ['method' => 'd17'])->assertStatus(422); // reference required
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/refund", ['method' => 'd17', 'reference' => 'D17-778899'])->assertOk();

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
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/refund", ['method' => 'bank_transfer', 'reference' => 'VIR-2026-001'])->assertOk();

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
        $this->assertSame(['wallet', 'bank_transfer', 'd17'], $show->json('data.refund_methods'));
        $this->assertCount(1, $show->json('data.image_urls'));

        $pdf = $this->as($this->admin)->get("/api/admin/complaints/{$c->id}/return-slip")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->as($this->seller)->get("/api/admin/complaints/{$c->id}/return-slip")->assertForbidden();
    }
}
