<?php

namespace Tests\Feature\Orders;

use App\Helpers\PlatformUser;
use App\Models\Cart;
use App\Models\Complaint;
use App\Models\ComplaintCost;
use App\Models\ComplaintEvent;
use App\Models\ComplaintItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerAdjustment;
use App\Models\SellerApplication;
use App\Models\User;
use App\Notifications\Returns\ReturnAdminNotification;
use App\Services\Delivery\DeliverySettings;
use App\Services\Intigo\IntigoStatusSync;
use App\Support\Millimes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MakesPickupAddresses;
use Tests\TestCase;

/**
 * After-sale fees per parcel size + the exchange flow (Intigo exchange parcel).
 *
 *   fees      S 8.560 / L 10.700 / XL 16.050 (after-sale return = exchange = Intigo delivery cost)
 *   return    seller at fault: client pays 0, seller debited; client's choice: fee off the refund
 *   exchange  replacement held at approval, Intigo parcel with COD = client fee (0 if seller
 *             at fault), webhook 5000 → exchanged, seller confirms the old item → completed
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Orders/ExchangeFlowTest.php
 */
class ExchangeFlowTest extends TestCase
{
    use DatabaseTransactions, MakesPickupAddresses;

    private const ADDRESS = [
        'recipient_name' => 'Sami Ben Salah',
        'phone'          => '22 123 456',
        'wilaya'         => 'Ben Arous',
        'delegation'     => 'El Mourouj',
        'address'        => '12 rue de la Liberté',
        'postal_code'    => '2074',
    ];

    private const FEES = ['S' => '8.560', 'L' => '10.700', 'XL' => '16.050'];

    private User $seller;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('public');
        config(['intigo.mode' => 'fake', 'intigo.fake_exchange_failure' => null]);

        if (!DB::table('users')->where('id', 1)->exists()) {
            DB::table('users')->insert([
                'id' => 1, 'name' => "CHOOSE'Tounsi", 'email' => 'platform-' . Str::random(6) . '@test.local',
                'password' => bcrypt('x'), 'role' => 'seller', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        PlatformUser::reset();
        \App\Models\PlatformSetting::flushCache();

        $this->seller = $this->makeUser('seller');
        $this->seller->forceFill(['intigo_pickup_index' => random_int(1000, 999999)])->save();
        SellerApplication::create([
            'user_id' => $this->seller->id, 'full_name' => 'Mohamed Trabelsi', 'phone_number' => '55111222',
            'business_name' => 'Atelier ' . Str::random(5), 'business_category' => 'crafts', 'business_description' => 'Test shop',
            'wilaya' => 'Sfax', 'city' => 'Sakiet Ezzit', 'pickup_address' => 'Route de Tunis km 5',
            'pickup_postal_code' => '3021', 'status' => 'approved',
        ]);
        $this->givePickup($this->seller, true, ['contact_name' => 'Mohamed Trabelsi']);
        $this->admin = $this->makeUser('admin');

        // Intigo delivery cost per size; after-sale return follows it, exchange fee = same by default
        $s = app(DeliverySettings::class);
        $s->flush();
        $sizes = [];
        foreach (self::FEES as $size => $cost) {
            $sizes[$size] = [
                'client_fee' => Millimes::of('8'), 'agency_cost' => Millimes::of($cost), 'return_fee' => Millimes::of('4.280'),
                'seller_contribution' => Millimes::of('8'), 'after_sale_use_agency_cost' => true, 'exchange_fee' => Millimes::of($cost),
            ];
        }
        $s->update(['sizes' => $sizes], $this->admin->id);
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

    /** A product of that parcel size with two variants (stock 10 each, same price). */
    private function product(string $size = 'S', float $price = 30): array
    {
        $name = 'Robe ' . Str::random(6);
        $p = Product::create([
            'seller_id' => $this->seller->id, 'name' => $name, 'slug' => Str::slug($name), 'parcel_size' => $size,
            'price' => $price, 'stock' => 20, 'is_approved' => true, 'is_active' => true,
        ]);
        $a = ProductVariant::create(['product_id' => $p->id, 'sku' => 'A-' . Str::random(6), 'stock' => 10, 'is_active' => true]);
        $b = ProductVariant::create(['product_id' => $p->id, 'sku' => 'B-' . Str::random(6), 'stock' => 10, 'is_active' => true]);
        return [$p, $a, $b];
    }

    /** Delivered COD order whose parcel went through Intigo (nid, status 5000). */
    private function deliveredOrder(array $lines): Order
    {
        $buyer = $this->makeUser('client');
        foreach ($lines as [$product, $variant, $qty]) {
            Cart::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant?->id, 'quantity' => $qty]);
        }
        $res = $this->as($buyer)->postJson('/api/checkout', self::ADDRESS + ['payment_method' => 'cod'])->assertCreated();
        $order = Order::findOrFail($res->json('order_id'));
        foreach (DB::table('seller_orders')->where('order_id', $order->id)->pluck('id') as $soId) {
            DB::table('seller_orders')->where('id', $soId)->update([
                'status' => 'delivered', 'intigo_nid' => 'NID-' . $soId . '-' . Str::random(4),
                'intigo_status_code' => 5000, 'intigo_sync_status' => 'sent',
            ]);
        }
        DB::table('orders')->where('id', $order->id)->update(['status' => 'delivered', 'updated_at' => now()]);
        return $order->fresh();
    }

    private function line(Order $order, ProductVariant $variant): OrderItem
    {
        return OrderItem::where('order_id', $order->id)->where('variant_id', $variant->id)->firstOrFail();
    }

    private function ask(Order $order, string $type, array $items, string $resolution = 'return_refund'): \Illuminate\Testing\TestResponse
    {
        return $this->as($order->user)->post('/api/client/complaints', [
            'order_id'        => $order->id,
            'complaint_type'  => $type,
            'other_reason'    => $type === 'other' ? 'Stitching came apart' : null,
            'resolution_type' => $resolution,
            'description'     => 'Not what I expected, please see the photos attached.',
            'items'           => $items,
            'images'          => [UploadedFile::fake()->image('proof.jpg', 400, 300)],
        ], ['Accept' => 'application/json']);
    }

    private function complaint(\Illuminate\Testing\TestResponse $res): Complaint
    {
        return Complaint::findOrFail($res->assertCreated()->json('data.id'));
    }

    private function approve(Complaint $c, ?string $payer = null): \Illuminate\Testing\TestResponse
    {
        $this->as($this->seller)->patchJson("/api/seller/complaints/{$c->id}/approve")->assertOk();
        return $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/approve", array_filter(['shipping_payer' => $payer]));
    }

    private function refundCash(Complaint $c): Complaint
    {
        $this->approve($c)->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/schedule-pickup", ['carrier' => 'Intigo'])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/picked-up")->assertOk();
        return $c->fresh();
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    public function test_after_sale_fees_are_per_size_and_follow_the_delivery_cost_until_unchecked(): void
    {
        $s = app(DeliverySettings::class);
        $this->assertSame(Millimes::of('10.700'), $s->afterSaleReturnFee('L'));
        $this->assertSame(Millimes::of('16.050'), $s->exchangeFee('XL'));

        // Intigo cost changes: the synced after-sale fee follows
        $s->update(['sizes' => ['L' => ['agency_cost' => Millimes::of('11.000')]]], $this->admin->id);
        $this->assertSame(Millimes::of('11.000'), $s->afterSaleReturnFee('L'));

        // Unchecked: the admin's own value
        $s->update(['sizes' => ['L' => ['after_sale_use_agency_cost' => false, 'after_sale_return_fee' => Millimes::of('9.000')]]], $this->admin->id);
        $this->assertSame(Millimes::of('9.000'), $s->afterSaleReturnFee('L'));
        $this->assertSame(Millimes::of('11.000'), $s->agencyCost('L'));

        $data = $this->as($this->admin)->getJson('/api/admin/delivery-settings')->assertOk()->json('data');
        $this->assertFalse($data['sizes']['L']['after_sale_use_agency_cost']);
        $this->assertEquals(9, $data['sizes']['L']['after_sale_return_fee']);
        $this->assertArrayNotHasKey('return_shipping_fee', $data['settings']);
        $this->assertTrue(DB::table('delivery_setting_changes')->where('field', 'L.after_sale_return_fee')->exists());
    }

    // ── Returns ───────────────────────────────────────────────────────────────

    public function test_seller_fault_return_costs_the_client_nothing_and_debits_the_seller_the_size_fee(): void
    {
        [$p, $a] = $this->product('L');
        $order = $this->deliveredOrder([[$p, $a, 1]]);
        $c = $this->complaint($this->ask($order, 'damaged_product', [['order_item_id' => $this->line($order, $a)->id, 'quantity' => 1]]));

        $this->assertSame('seller', $c->shipping_payer);
        $this->assertSame('L', $c->fee_size);
        $this->assertEquals(10.7, (float) $c->return_shipping_fee);
        $this->assertEquals(30.0, (float) $c->refund_amount);

        $c = $this->refundCash($c);
        $this->assertEquals(30.0, (float) $c->refund_amount);
        $adj = SellerAdjustment::where('complaint_id', $c->id)->where('type', SellerAdjustment::TYPE_RETURN_SHIPPING)->firstOrFail();
        $this->assertEquals(-10.7, (float) $adj->amount);

        $cost = ComplaintCost::where('complaint_id', $c->id)->where('kind', 'return')->firstOrFail();
        $this->assertEquals(10.7, (float) $cost->intigo_cost);
        $this->assertEquals(0.0, (float) $cost->recovered_client);
        $this->assertEquals(10.7, (float) $cost->recovered_seller);
    }

    public function test_client_choice_return_deducts_the_fee_of_each_size_from_the_refund(): void
    {
        foreach (['S' => 8.56, 'L' => 10.7, 'XL' => 16.05] as $size => $fee) {
            [$p, $a] = $this->product($size, 50);
            $order = $this->deliveredOrder([[$p, $a, 1]]);
            $c = $this->complaint($this->ask($order, 'changed_mind', [['order_item_id' => $this->line($order, $a)->id, 'quantity' => 1]]));

            $this->assertSame('client', $c->shipping_payer, $size);
            $this->assertSame($size, $c->fee_size);
            $this->assertEquals(round(50 - $fee, 3), (float) $c->refund_amount, "size {$size}");

            $c = $this->refundCash($c);
            $this->assertEquals(round(50 - $fee, 3), (float) $c->refund_amount, "size {$size} after refund");
            $this->assertFalse(SellerAdjustment::where('complaint_id', $c->id)->where('type', SellerAdjustment::TYPE_RETURN_SHIPPING)->exists());
            $this->assertEquals($fee, (float) ComplaintCost::where('complaint_id', $c->id)->value('recovered_client'));
        }
    }

    public function test_one_parcel_with_mixed_sizes_pays_one_fee_at_the_largest_size(): void
    {
        [$small, $sa] = $this->product('S', 40);
        [$big, $ba]   = $this->product('XL', 60);
        $order = $this->deliveredOrder([[$small, $sa, 1], [$big, $ba, 1]]);

        $c = $this->complaint($this->ask($order, 'doesnt_fit', [
            ['order_item_id' => $this->line($order, $sa)->id, 'quantity' => 1],
            ['order_item_id' => $this->line($order, $ba)->id, 'quantity' => 1],
        ]));

        $this->assertSame(1, Complaint::where('order_id', $order->id)->count(), 'one shop = one parcel = one complaint');
        $this->assertSame('XL', $c->fee_size);
        $this->assertEquals(16.05, (float) $c->return_shipping_fee);
        $this->assertEquals(round(100 - 16.05, 3), (float) $c->refund_amount);
    }

    public function test_return_fee_above_the_items_value_refunds_zero_and_flags_the_admin(): void
    {
        [$p, $a] = $this->product('XL', 12);
        $order = $this->deliveredOrder([[$p, $a, 1]]);
        $c = $this->complaint($this->ask($order, 'changed_mind', [['order_item_id' => $this->line($order, $a)->id, 'quantity' => 1]]));

        $this->assertEquals(0.0, (float) $c->refund_amount);
        $this->assertTrue($c->fee_exceeds_refund);
        Notification::assertSentTo($this->admin, ReturnAdminNotification::class);
        $this->assertSame(1, $this->as($this->admin)->getJson('/api/admin/complaints?fee_exceeds_refund=1&search=' . $c->reference)->json('data.total'));

        $c = $this->refundCash($c);
        $this->assertEquals(0.0, (float) $c->refund_amount);
        // Only what the items were worth could be recovered
        $this->assertEquals(12.0, (float) ComplaintCost::where('complaint_id', $c->id)->value('recovered_client'));
    }

    public function test_admin_can_switch_the_payer_before_approving_and_it_is_logged(): void
    {
        [$p, $a] = $this->product('L', 50);
        $order = $this->deliveredOrder([[$p, $a, 1]]);
        $c = $this->complaint($this->ask($order, 'other', [['order_item_id' => $this->line($order, $a)->id, 'quantity' => 1]]));
        $this->assertEquals(39.3, (float) $c->refund_amount);

        $this->approve($c, 'seller')->assertOk();
        $c->refresh();
        $this->assertSame('seller', $c->shipping_payer);
        $this->assertEquals(50.0, (float) $c->refund_amount);
        $event = ComplaintEvent::where('complaint_id', $c->id)->where('status', 'payer_overridden')->firstOrFail();
        $this->assertSame(['from' => 'client', 'to' => 'seller'], array_intersect_key($event->meta, ['from' => 1, 'to' => 1]));

        // Never shown to the client
        $public = collect($this->as($order->user)->getJson("/api/client/complaints/{$c->id}")->json('data.public_events'))->pluck('status');
        $this->assertNotContains('payer_overridden', $public);
    }

    // ── Exchange ──────────────────────────────────────────────────────────────

    public function test_client_choice_exchange_books_intigo_with_the_exchange_fee_as_cod_and_completes(): void
    {
        [$p, $a, $b] = $this->product('L');
        $order = $this->deliveredOrder([[$p, $a, 1]]);
        $line  = $this->line($order, $a);

        // The form gets the in-stock same-price variants and the fees per size
        $eligible = $this->as($order->user)->getJson('/api/client/complaints/eligible-orders')->assertOk();
        $item = collect($eligible->json('data.0.items'))->firstWhere('id', $line->id);
        $this->assertSame('L', $item['parcel_size']);
        $this->assertContains($b->id, array_column($item['exchange_options'], 'id'));
        $this->assertEquals(10.7, $eligible->json('after_sale_fees.L.exchange'));

        $c = $this->complaint($this->ask($order, 'prefer_other_color', [['order_item_id' => $line->id, 'quantity' => 1, 'replacement_variant_id' => $b->id]], 'exchange'));
        $this->assertTrue($c->isExchange());
        $this->assertEquals(0.0, (float) $c->refund_amount);
        $this->assertEquals(10.7, $c->clientFee());
        $this->assertSame($b->id, (int) ComplaintItem::where('complaint_id', $c->id)->value('replacement_variant_id'));
        $this->assertSame(10, (int) $b->fresh()->stock, 'nothing held before approval');

        $this->approve($c)->assertOk();
        $c->refresh();
        $this->assertSame(Complaint::STATUS_EXCHANGE_IN_TRANSIT, $c->status);
        $this->assertSame(Complaint::EXCHANGE_SENT, $c->exchange_sync_status);
        $this->assertStringStartsWith('FAKE-EX-', $c->intigo_exchange_nid);
        $this->assertSame(9, (int) $b->fresh()->stock, 'replacement held at approval');

        $log = DB::table('intigo_logs')->where('endpoint', '/parcels/exchange')->orderByDesc('id')->first();
        $sent = json_decode($log->request ?? '{}', true);
        $this->assertEquals(10.7, $sent['exchange_price']);
        $this->assertSame(4, $sent['package_size']);
        $this->assertSame((int) $this->seller->intigo_pickup_index, $sent['pickup_index']);

        // Commission / payout untouched by the exchange
        $so = DB::table('seller_orders')->where('order_id', $order->id)->first();

        // Intigo: delivered → exchanged
        app(IntigoStatusSync::class)->apply($c->intigo_exchange_nid, 5000, 'Livré');
        $c->refresh();
        $this->assertSame(Complaint::STATUS_EXCHANGED, $c->status);
        $cost = ComplaintCost::where('complaint_id', $c->id)->where('kind', 'exchange')->firstOrFail();
        $this->assertEquals(10.7, (float) $cost->intigo_cost);
        $this->assertEquals(10.7, (float) $cost->recovered_client);
        $this->assertFalse(SellerAdjustment::where('complaint_id', $c->id)->exists());

        // The seller confirms the old item: case closed, old variant restocked
        $this->as($this->seller)->patchJson("/api/seller/complaints/{$c->id}/receive", ['conditions' => [$line->id => 'resaleable']])->assertOk();
        $c->refresh();
        $this->assertSame(Complaint::STATUS_EXCHANGE_COMPLETED, $c->status);
        $this->assertNotNull($c->old_item_received_at);
        $this->assertSame(10, (int) $a->fresh()->stock, 'old item back on its own variant');

        $after = DB::table('seller_orders')->where('order_id', $order->id)->first();
        $this->assertEquals((float) $so->commission_amount, (float) $after->commission_amount);
        $this->assertEquals((float) $so->seller_net_amount, (float) $after->seller_net_amount);
        $this->assertSame(1, (int) OrderItem::whereKey($line->id)->value('quantity'), 'same sale');
    }

    public function test_seller_fault_exchange_collects_nothing_and_debits_the_seller(): void
    {
        [$p, $a] = $this->product('XL');
        $order = $this->deliveredOrder([[$p, $a, 1]]);
        $line  = $this->line($order, $a);

        // Defective: the same variant again
        $c = $this->complaint($this->ask($order, 'damaged_product', [['order_item_id' => $line->id, 'quantity' => 1, 'replacement_variant_id' => $a->id]], 'exchange'));
        $this->assertEquals(0.0, $c->clientFee());

        $this->approve($c)->assertOk();
        $c->refresh();
        $sent = json_decode(DB::table('intigo_logs')->where('endpoint', '/parcels/exchange')->orderByDesc('id')->value('request') ?? '{}', true);
        $this->assertEquals(0, $sent['exchange_price']);
        $this->assertSame(5, $sent['package_size']);

        app(IntigoStatusSync::class)->apply($c->intigo_exchange_nid, 5000, 'Livré');
        $adj = SellerAdjustment::where('complaint_id', $c->id)->where('type', SellerAdjustment::TYPE_EXCHANGE_SHIPPING)->firstOrFail();
        $this->assertEquals(-16.05, (float) $adj->amount);
        $this->assertEquals(16.05, (float) ComplaintCost::where('complaint_id', $c->id)->value('recovered_seller'));
    }

    public function test_replacement_out_of_stock_or_at_another_price_is_refused_with_a_clear_message(): void
    {
        [$p, $a, $b] = $this->product('S');
        $order = $this->deliveredOrder([[$p, $a, 1]]);
        $line  = $this->line($order, $a);

        $b->update(['stock' => 0]);
        $this->ask($order, 'doesnt_fit', [['order_item_id' => $line->id, 'quantity' => 1, 'replacement_variant_id' => $b->id]], 'exchange')
            ->assertStatus(422)->assertJsonFragment(['message' => __('messages.complaint.exchange_out_of_stock', ['item' => $line->product_name . ($b->fresh()->label ? ' — ' . $b->fresh()->label : '')])]);

        $b->update(['stock' => 5, 'price_override' => 45]);
        $this->ask($order, 'doesnt_fit', [['order_item_id' => $line->id, 'quantity' => 1, 'replacement_variant_id' => $b->id]], 'exchange')
            ->assertStatus(422)->assertJsonFragment(['message' => __('messages.complaint.exchange_price_differs', ['item' => $line->product_name])]);

        $this->ask($order, 'doesnt_fit', [['order_item_id' => $line->id, 'quantity' => 1]], 'exchange')->assertStatus(422);
        $this->assertSame(0, Complaint::where('order_id', $order->id)->count());

        // Sold out between the request and the approval: the approval is refused, nothing moves
        $b->update(['price_override' => null]);
        $c = $this->complaint($this->ask($order, 'doesnt_fit', [['order_item_id' => $line->id, 'quantity' => 1, 'replacement_variant_id' => $b->id]], 'exchange'));
        $b->update(['stock' => 0]);
        $this->approve($c)->assertStatus(422);
        $this->assertSame(Complaint::STATUS_SELLER_ACCEPTED, $c->fresh()->status);
    }

    public function test_failed_exchange_returns_to_the_admin_who_can_switch_it_to_a_return(): void
    {
        [$p, $a, $b] = $this->product('S', 40);
        $order = $this->deliveredOrder([[$p, $a, 1]]);
        $line  = $this->line($order, $a);
        $c = $this->complaint($this->ask($order, 'doesnt_fit', [['order_item_id' => $line->id, 'quantity' => 1, 'replacement_variant_id' => $b->id]], 'exchange'));
        $this->approve($c)->assertOk();
        $this->assertSame(9, (int) $b->fresh()->stock);

        // Refused at the door
        app(IntigoStatusSync::class)->apply($c->fresh()->intigo_exchange_nid, 6000, 'Préparation du retour');
        $c->refresh();
        $this->assertSame(Complaint::STATUS_EXCHANGE_FAILED, $c->status);
        Notification::assertSentTo($this->admin, ReturnAdminNotification::class);
        $this->assertEquals(4.28, (float) ComplaintCost::where('complaint_id', $c->id)->where('kind', 'exchange_failed')->value('intigo_cost'));

        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/exchange/decide", ['action' => 'return'])->assertOk();
        $c->refresh();
        $this->assertSame(Complaint::RESOLUTION_RETURN_REFUND, $c->resolution_type);
        $this->assertSame(Complaint::STATUS_ADMIN_APPROVED, $c->status);
        $this->assertSame(10, (int) $b->fresh()->stock, 'replacement back in stock');
        $this->assertEquals(round(40 - 8.56, 3), (float) $c->refund_amount, 'now a return: the return fee applies');
    }

    public function test_lost_intigo_answer_needs_a_check_is_never_retried_and_the_pasted_nid_ships_it(): void
    {
        config(['intigo.fake_exchange_failure' => 'timeout']);
        [$p, $a, $b] = $this->product('S');
        $order = $this->deliveredOrder([[$p, $a, 1]]);
        $c = $this->complaint($this->ask($order, 'doesnt_fit', [['order_item_id' => $this->line($order, $a)->id, 'quantity' => 1, 'replacement_variant_id' => $b->id]], 'exchange'));

        $this->approve($c)->assertOk()->assertJsonFragment(['message' => 'Exchange approved, replacement stock held. Intigo did not answer clearly: check in Intigo and paste the tracking number (never re-sent automatically).']);
        $c->refresh();
        $this->assertSame(Complaint::STATUS_ADMIN_APPROVED, $c->status);
        $this->assertSame(Complaint::EXCHANGE_NEEDS_CHECK, $c->exchange_sync_status);
        Notification::assertSentTo($this->admin, ReturnAdminNotification::class, fn ($n) => $n->toDatabase($this->admin)['type'] === 'return_exchange_needs_check');

        // A new booking attempt is refused while it needs a check
        config(['intigo.fake_exchange_failure' => null]);
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/exchange/book")->assertStatus(422);
        $this->assertSame(1, DB::table('intigo_logs')->where('endpoint', '/parcels/exchange')->where('request', 'like', '%' . $c->reference . '%')->count());

        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/exchange/nid", ['nid' => 'INT-12345'])->assertOk();
        $c->refresh();
        $this->assertSame(Complaint::STATUS_EXCHANGE_IN_TRANSIT, $c->status);
        $this->assertSame('INT-12345', $c->intigo_exchange_nid);
        $this->assertSame(Complaint::EXCHANGE_MANUAL, $c->exchange_sync_status);
    }

    public function test_order_not_shipped_through_intigo_gets_the_manual_booking_step(): void
    {
        [$p, $a, $b] = $this->product('S');
        $order = $this->deliveredOrder([[$p, $a, 1]]);
        DB::table('seller_orders')->where('order_id', $order->id)->update(['intigo_nid' => null, 'intigo_status_code' => null]);
        $c = $this->complaint($this->ask($order, 'doesnt_fit', [['order_item_id' => $this->line($order, $a)->id, 'quantity' => 1, 'replacement_variant_id' => $b->id]], 'exchange'));

        $this->approve($c)->assertOk();
        $c->refresh();
        $this->assertSame(Complaint::STATUS_ADMIN_APPROVED, $c->status);
        $this->assertSame(Complaint::EXCHANGE_MANUAL, $c->exchange_sync_status);
        $this->assertStringContainsString('not shipped through Intigo', $c->exchange_sync_error);

        $show = $this->as($this->admin)->getJson("/api/admin/complaints/{$c->id}")->assertOk();
        $this->assertFalse($show->json('data.exchange.can_book'));
        $this->assertEquals(8.56, $show->json('data.exchange.cod'));

        // Exchange slip PDF
        $this->as($this->admin)->get("/api/admin/complaints/{$c->id}/return-slip")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_old_item_not_confirmed_after_seven_days_is_flagged(): void
    {
        [$p, $a, $b] = $this->product('S');
        $order = $this->deliveredOrder([[$p, $a, 1]]);
        $c = $this->complaint($this->ask($order, 'doesnt_fit', [['order_item_id' => $this->line($order, $a)->id, 'quantity' => 1, 'replacement_variant_id' => $b->id]], 'exchange'));
        $this->approve($c)->assertOk();
        app(IntigoStatusSync::class)->apply($c->fresh()->intigo_exchange_nid, 5000, 'Livré');

        $this->assertFalse($c->fresh()->old_item_overdue);
        Complaint::whereKey($c->id)->update(['exchanged_at' => now()->subDays(8)]);
        $this->assertTrue($c->fresh()->old_item_overdue);
        $this->assertGreaterThanOrEqual(1, $this->as($this->admin)->getJson('/api/admin/complaints/stats')->json('data.old_item_not_received'));
        $this->assertSame(1, $this->as($this->admin)->getJson('/api/admin/complaints?old_item_overdue=1&search=' . $c->reference)->json('data.total'));
    }

    public function test_legacy_exchanges_stay_closed_and_can_only_be_closed(): void
    {
        [$p, $a] = $this->product('S');
        $order = $this->deliveredOrder([[$p, $a, 1]]);
        $c = $this->complaint($this->ask($order, 'damaged_product', [['order_item_id' => $this->line($order, $a)->id, 'quantity' => 1]]));
        // An old-policy exchange: no fee size
        Complaint::whereKey($c->id)->update(['resolution_type' => 'exchange', 'fee_size' => null, 'status' => Complaint::STATUS_ADMIN_APPROVED]);
        $c->refresh();

        $this->assertTrue($c->isLegacyExchange());
        $this->assertSame([Complaint::STATUS_CANCELLED, Complaint::STATUS_CLOSED], array_values(array_intersect([Complaint::STATUS_CANCELLED, Complaint::STATUS_CLOSED], $c->allowedTransitions())));
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/exchange/book")->assertStatus(422);
        $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/close")->assertOk();
        $this->assertSame(Complaint::STATUS_CLOSED, $c->fresh()->status);
    }
}
