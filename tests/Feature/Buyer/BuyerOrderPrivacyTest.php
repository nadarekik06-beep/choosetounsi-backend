<?php

namespace Tests\Feature\Buyer;

use App\Models\Coupon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Intigo\IntigoTestHelpers;
use Tests\TestCase;

/**
 * Buyer-facing order data is an allow-list (App\Http\Resources\Buyer): no
 * commission, seller payout, courier cost, platform margin, settlement, seller
 * contact / pickup or Intigo internals ever reaches the storefront, at any
 * depth of the JSON. And a buyer only ever sees their own orders and returns.
 */
class BuyerOrderPrivacyTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    /** Keys that must never appear in a buyer response, at any depth. */
    private const FORBIDDEN_KEYS = [
        // commission / seller money
        'commission_percentage', 'commission_amount', 'commission_rate', 'commission_source', 'seller_amount',
        'seller_net_amount', 'net_after_shipping', 'plan_used', 'payout_status', 'seller_shipping_charge',
        'seller_free_delivery_contribution', 'contribution',
        // platform / courier money
        'platform_profit', 'platform_delivery_margin', 'shipping_cost', 'agency_delivery_cost', 'agency_cost',
        'amount_to_remit', 'refused_agency_fee', 'refused_fee_paid_by', 'return_fee', 'cash_collected_at',
        'money_received_at', 'client_delivery_fee',
        // settlements / finance
        'settlement_batch_id', 'settlement_id', 'finance_applied_at', 'refund_task_id',
        // seller contact / pickup
        // (seller_id stays: it is the public shop id the storefront links to; the
        // buyer's own e-mail is theirs; sellers' e-mails are checked as values)
        'pickup', 'pickup_index', 'intigo_pickup_index', 'intigo_pickup_label', 'pickup_address', 'phone_number', 'whatsapp_number',
        // Intigo internals
        'intigo_nid', 'intigo_error', 'intigo_attention', 'intigo_sync_status', 'intigo_attempts', 'intigo_locked_at',
        'intigo_previous_nids', 'intigo_status_code', 'carrier_status_raw', 'parcel_size_computed',
        // staff notes / handlers / provider ids
        'admin_note', 'reception_note', 'pickup_note', 'received_by', 'refunded_by', 'admin_decided_by',
        'stripe_payment_intent_id', 'woocommerce_order_id', 'promotion_id', 'flash_reserved', 'stock', 'sku',
    ];

    /** Values that must never appear either (seller phones, pickup street). */
    private const FORBIDDEN_VALUES = ['55111222', '98000111', 'Route de Tunis km 5'];

    /** Sellers' e-mail addresses: never shown to the buyer. */
    private array $sellerEmails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
        Storage::fake('public');
    }

    /** A delivered two-shop order (coupon, free shipping, Intigo parcel) with a return request. */
    private function richOrder(): array
    {
        $a = $this->sellerWithPickup(3);
        $b = $this->sellerWithPickup(4, 'red');
        $this->sellerEmails = [$a->email, $b->email];
        $pa = $this->product($a, 100);
        $coupon = Coupon::create(['seller_id' => $a->id, 'code' => 'PRV' . Str::upper(Str::random(5)), 'discount_type' => 'fixed', 'discount_value' => 10, 'is_active' => true]);
        $coupon->products()->attach($pa->id);
        $this->addToCart($pa);
        $this->addToCart($this->product($b, 60, true));
        [, $orderId] = $this->placeAndConfirm(['coupon_codes' => [$coupon->code]]);

        $soA = $this->parcel($orderId, $a);
        $soB = $this->parcel($orderId, $b);
        $this->prepare($soA, $a);
        $this->prepare($soB, $b);
        $this->webhook($this->so($soA)->intigo_nid, 5000)->assertOk();   // delivered: return window opens

        $line = DB::table('order_items')->where('seller_order_id', $soA->id)->first();
        $complaint = $this->as($this->customer)->post('/api/client/complaints', [
            'order_id' => $orderId, 'complaint_type' => 'damaged_product',
            'description' => 'The item arrived broken, the box was crushed.',
            'items' => [['order_item_id' => $line->id, 'quantity' => 1]],
            'images' => [UploadedFile::fake()->image('proof.jpg', 400, 300)],
        ], ['Accept' => 'application/json'])->assertCreated();
        DB::table('complaints')->where('id', $complaint->json('data.id'))->update(['admin_note' => 'internal: check seller history', 'reception_note' => 'staff only']);

        return [$orderId, (int) $complaint->json('data.id'), $complaint];
    }

    private function assertClean(\Illuminate\Testing\TestResponse $res, string $where): void
    {
        $res->assertSuccessful();
        $found = [];
        $walk = function ($node, string $path) use (&$walk, &$found) {
            if (!is_array($node)) return;
            foreach ($node as $k => $v) {
                if (is_string($k) && in_array($k, self::FORBIDDEN_KEYS, true)) {
                    $found[] = "{$path}.{$k}";
                }
                $walk($v, "{$path}.{$k}");
            }
        };
        $walk($res->json(), '$');
        $this->assertSame([], $found, "{$where} leaks internal fields");

        $raw = $res->getContent();
        foreach ([...self::FORBIDDEN_VALUES, ...$this->sellerEmails] as $value) {
            $this->assertStringNotContainsString($value, $raw, "{$where} leaks seller contact data");
        }
        $this->assertStringNotContainsString('internal: check seller history', $raw, "{$where} leaks admin notes");
    }

    public function test_buyer_endpoints_never_expose_internal_fields(): void
    {
        [$orderId, $complaintId, $created] = $this->richOrder();
        $this->assertClean($created, 'POST /client/complaints');

        $list = $this->as($this->customer)->getJson('/api/client/orders');
        $this->assertClean($list, 'GET /client/orders');
        $show = $this->as($this->customer)->getJson("/api/client/orders/{$orderId}");
        $this->assertClean($show, 'GET /client/orders/{id}');

        foreach ([
            'GET /client/complaints'                 => $this->as($this->customer)->getJson('/api/client/complaints'),
            'GET /client/complaints/{id}'            => $this->as($this->customer)->getJson("/api/client/complaints/{$complaintId}"),
            'GET /client/complaints/eligible-orders' => $this->as($this->customer)->getJson('/api/client/complaints/eligible-orders'),
            'GET /client/statistics'                 => $this->as($this->customer)->getJson('/api/client/statistics'),
            'GET /profile/overview'                  => $this->as($this->customer)->getJson('/api/profile/overview'),
            'GET /client/reviews/eligible'           => $this->as($this->customer)->getJson('/api/client/reviews/eligible'),
            'GET /client/reviews/prompts'            => $this->as($this->customer)->getJson('/api/client/reviews/prompts'),
            'GET /notifications'                     => $this->as($this->customer)->getJson('/api/notifications'),
        ] as $where => $res) {
            $this->assertClean($res, $where);
        }
        $this->assertClean($this->as($this->customer)->patchJson("/api/client/complaints/{$complaintId}/cancel"), 'PATCH /client/complaints/{id}/cancel');

        // What the storefront needs is still there
        $order = $show->json('data');
        $this->assertSame(98.0 + 0.0, (float) $order['seller_groups'][0]['subtotal'] + (float) $order['seller_groups'][0]['delivery_fee'] - (float) $order['seller_groups'][0]['discount_amount']);
        $this->assertCount(2, $order['seller_groups']);
        $this->assertNotNull($order['seller_groups'][0]['shop']['name']);
        $this->assertSame('delivered', $order['seller_groups'][0]['delivery']['stage']);
        $this->assertStringStartsWith('FAKE-', $order['seller_groups'][0]['delivery']['tracking_ref']);
        $this->assertTrue($order['seller_groups'][1]['is_free_delivery']);
        $this->assertEquals(0, $order['seller_groups'][1]['delivery_fee']);
        $this->assertEquals(158, $order['total_amount']);   // 100 − 10 + 8 + 60 + 0
        foreach (['product_name', 'variant_label', 'resolved_image_url', 'quantity', 'unit_price', 'total', 'discount_amount', 'seller_order_status', 'return_state'] as $key) {
            $this->assertArrayHasKey($key, $order['items'][0]);
        }
        $this->assertCount(1, $order['returns']);
    }

    public function test_checkout_and_quote_responses_are_clean(): void
    {
        $seller = $this->sellerWithPickup();
        $this->sellerEmails = [$seller->email];
        $this->addToCart($this->product($seller, 40));
        $quote = $this->as($this->customer)->postJson('/api/checkout/quote', []);
        $this->assertClean($quote, 'POST /checkout/quote');
        $res = $this->as($this->customer)->postJson('/api/checkout', $this->address() + ['payment_method' => 'cod', 'expected_total' => $quote->json('data.total')]);
        $this->assertClean($res, 'POST /checkout');
    }

    public function test_a_buyer_cannot_see_another_buyers_order_or_return(): void
    {
        [$orderId, $complaintId] = $this->richOrder();
        $intruder = $this->makeUser('client');

        $this->as($intruder)->getJson("/api/client/orders/{$orderId}")->assertNotFound();
        $this->assertSame([], $this->as($intruder)->getJson('/api/client/orders')->json('data.data'));
        $this->as($intruder)->getJson("/api/client/complaints/{$complaintId}")->assertNotFound();
        $this->as($intruder)->patchJson("/api/client/complaints/{$complaintId}/escalate")->assertNotFound();
        $this->as($intruder)->patchJson("/api/client/complaints/{$complaintId}/cancel")->assertNotFound();
        $this->assertSame([], $this->as($intruder)->getJson('/api/client/complaints')->json('data.data'));
        $this->as($intruder)->post('/api/client/complaints', [
            'order_id' => $orderId, 'complaint_type' => 'damaged_product',
            'description' => 'Trying to return somebody else\'s order.',
            'return_all' => true, 'images' => [UploadedFile::fake()->image('x.jpg')],
        ], ['Accept' => 'application/json'])->assertNotFound();

        // Not logged in: nothing
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson("/api/client/orders/{$orderId}")->assertStatus(401);
    }
}
