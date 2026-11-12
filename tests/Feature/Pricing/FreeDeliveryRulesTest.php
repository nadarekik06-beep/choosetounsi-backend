<?php

namespace Tests\Feature\Pricing;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Notifications\Sellers\FreeDeliveryEverywhereNotification;
use App\Services\Delivery\DeliverySettings;
use App\Support\Millimes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Delivery\DeliveryTestHelpers;
use Tests\TestCase;

/**
 * "Free delivery everywhere", around the worked examples: nothing leaks a base
 * price to the storefront, sellers keep their own prices and payouts, the
 * invoice in the parcel shows client amounts, sizes get confirmed, the seller
 * free-delivery option is gone, the cart and the return form read server figures.
 *
 * Settings: client fee S 9.000 / L 11.000 / XL 17.000 (Intigo cost the same).
 */
class FreeDeliveryRulesTest extends TestCase
{
    use DatabaseTransactions, DeliveryTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpDelivery();
        $sizes = [];
        foreach (['S' => 9, 'L' => 11, 'XL' => 17] as $size => $fee) {
            $sizes[$size] = ['client_fee' => Millimes::of($fee), 'agency_cost' => Millimes::of($fee), 'return_fee' => Millimes::of('4.280'),
                'after_sale_use_agency_cost' => false, 'after_sale_return_fee' => Millimes::of('4.280')];
        }
        app(DeliverySettings::class)->update(['sizes' => $sizes], $this->admin->id);
    }

    private function item($seller, $price, string $size = 'S', array $extra = []): Product
    {
        $name = 'Del Product ' . Str::random(8);
        return Product::create($extra + [
            'seller_id' => $seller->id, 'name' => $name, 'slug' => Str::slug($name), 'price' => $price,
            'parcel_size' => $size, 'stock' => 50, 'is_approved' => true, 'is_active' => true,
        ]);
    }

    private function anonymous(): self
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        return $this;
    }

    // ── No base price, no fee, no delivery amount on the storefront ───────────

    public function test_storefront_endpoints_never_return_a_base_price_or_a_per_product_fee(): void
    {
        $seller  = $this->seller('free');
        // 33.333 and 44.444 are found nowhere else: a leak of the base price shows up in the JSON
        $product = $this->item($seller, '33.33', 'L');
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'LK-' . Str::random(5), 'price_override' => '44.44', 'stock' => 5, 'is_active' => true]);
        $this->addToCart($product);
        DB::table('carts')->where('product_id', $product->id)->update(['variant_id' => $variant->id]);

        $payloads = [
            'product page' => $this->anonymous()->getJson("/api/products/{$product->slug}")->assertOk()->getContent(),
            'listing'      => $this->anonymous()->getJson("/api/products?seller_id={$seller->id}")->assertOk()->getContent(),
            'by ids'       => $this->anonymous()->postJson('/api/products/by-ids', ['ids' => [$product->id]])->getContent(),
            'cart'         => $this->as($this->customer)->getJson('/api/cart')->assertOk()->getContent(),
            'quote'        => $this->as($this->customer)->postJson('/api/checkout/quote')->assertOk()->getContent(),
        ];
        foreach ($payloads as $name => $json) {
            $this->assertStringNotContainsString('33.33', $json, "{$name}: base price leaked");
            $this->assertStringNotContainsString('44.44', $json, "{$name}: base variant price leaked");
            $this->assertStringNotContainsString('effective_delivery_fee', $json, "{$name}: per-product fee exposed");
            $this->assertStringNotContainsString('"delivery_fee":11', $json, "{$name}: a delivery amount is shown");
        }
        $this->assertStringContainsString('44.33', $payloads['product page'], 'display price = 33.33 + 11');
        $this->assertStringContainsString('55.44', $payloads['product page'], 'variant display price = 44.44 + 11');

        // The order confirmation and "Mes commandes" neither
        $res = $this->checkout();
        $order = $this->as($this->customer)->getJson('/api/client/orders/' . $res['order_id'])->assertOk()->getContent();
        foreach ([json_encode($res), $order] as $json) {
            $this->assertStringNotContainsString('44.44', $json);
            $this->assertStringNotContainsString('"shipping_fee":11', $json);
            $this->assertStringNotContainsString('commission', $json);
        }
    }

    // ── Sellers keep their own prices and payouts ─────────────────────────────

    public function test_seller_screens_show_base_prices_and_the_payout_ignores_the_multi_item_discount(): void
    {
        $seller = $this->seller('free');
        $a = $this->item($seller, 30, 'S');
        $b = $this->item($seller, 80, 'L');

        // One order with both items (multi-item discount 9.000), one with the shoes alone
        $this->addToCart($a);
        $this->addToCart($b);
        $both = $this->checkout();
        $this->travel(\App\Services\Orders\CheckoutGuard::RECENT_SECONDS + 1)->seconds();
        $this->addToCart($b);
        $alone = $this->checkout();

        $soBoth  = $this->parcel($both['order_id'], $seller);
        $soAlone = $this->parcel($alone['order_id'], $seller);
        // Shoes: 80 − 15 % = 68 in both orders; the discount never touches the seller
        $this->assertSame(self::m('93.5'), self::m($soBoth->seller_net_amount));
        $this->assertSame(self::m('68'), self::m($soAlone->seller_net_amount));
        $this->assertSame(self::m('0'), self::m($soBoth->seller_shipping_charge));

        $detail = $this->as($seller)->getJson("/api/seller/orders/{$soBoth->id}")->assertOk()->json('data');
        $this->assertEquals(110.0, $detail['seller_subtotal'], 'the seller sees their own prices');
        $this->assertEquals(0.0, $detail['commission']['shipping_paid_by_seller']);
        $this->assertEquals(93.5, $detail['commission']['net_after_shipping']);
        $this->assertStringNotContainsString('multi_item_discount', json_encode($detail['commission']));

        $earnings = $this->as($seller)->getJson("/api/seller/earnings/orders/{$soBoth->id}/details")->assertOk()->json('data.earnings');
        $this->assertEquals([110.0, 93.5, 0.0], [$earnings['gross'], $earnings['net'], $earnings['shipping_charge']]);

        // The old "what free delivery costs you" endpoint charges nothing any more
        $this->assertEquals(0.0, $this->as($seller)->getJson('/api/seller/shipping-cost')->assertOk()->json('data.free_delivery_contribution'));
    }

    // ── Q6: the invoice a seller may put in the parcel ────────────────────────

    public function test_seller_invoice_shows_client_amounts_only(): void
    {
        $seller = $this->seller('free');
        $this->addToCart($this->item($seller, 30, 'S'));
        $this->addToCart($this->item($seller, 80, 'L'));
        $res = $this->checkout();
        $so  = $this->parcel($res['order_id'], $seller);

        $invoice = $this->as($seller)->getJson("/api/seller/orders/{$so->id}/invoice")->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([39.0, 91.0], array_column($invoice['items'], 'unit_price'));
        $this->assertEquals(130.0, $invoice['subtotal']);
        $this->assertEquals(9.0, $invoice['multi_item_discount']);
        $this->assertEquals(0.0, $invoice['shipping_fee']);
        $this->assertTrue($invoice['free_delivery']);
        $this->assertEquals(121.0, $invoice['grand_total']);
        $json = json_encode($invoice);
        $this->assertStringNotContainsString('"unit_price":30', $json);
        $this->assertStringNotContainsString('"unit_price":80', $json);
    }

    // ── Admin: fee change through the admin page, single margin ───────────────

    public function test_admin_delivery_settings_have_one_margin_and_no_seller_contribution(): void
    {
        $data = $this->as($this->admin)->getJson('/api/admin/delivery-settings')->assertOk()->json('data');
        $this->assertArrayNotHasKey('seller_contribution', $data['sizes']['S']);
        $this->assertArrayNotHasKey('margin_free', $data['sizes']['S']);
        $this->assertArrayNotHasKey('free_delivery', $data['margins']);
        $this->assertEquals(0.0, $data['sizes']['L']['margin_normal']);

        // An older admin build still sending the contribution is accepted, the value ignored
        $sizes = [];
        foreach (['S', 'L', 'XL'] as $size) {
            $sizes[$size] = ['client_fee' => '9.000', 'agency_cost' => '7.000', 'return_fee' => '4.280', 'seller_contribution' => '6.000'];
        }
        $saved = $this->as($this->admin)->putJson('/api/admin/delivery-settings', ['sizes' => $sizes, 'refused_parcel_fee_paid_by' => 'seller'])
            ->assertOk()->json('data');
        $this->assertEquals(2.0, $saved['sizes']['S']['margin_normal']);   // client fee − Intigo cost
        $this->assertFalse(DB::table('delivery_setting_changes')->where('field', 'like', '%seller_contribution')->where('created_at', '>=', now()->subMinute())->exists());
    }

    // ── R3: the seller option is gone ─────────────────────────────────────────

    public function test_a_legacy_free_delivery_flag_no_longer_changes_anything(): void
    {
        $seller  = $this->seller('free');
        $product = $this->item($seller, 50, 'S', ['delivery_fee' => 0]);   // what "free delivery" used to store
        $this->addToCart($product);

        $res = $this->checkout();
        $this->assertEquals(59.0, $res['total'], 'the fee is included like for every product');
        $so = $this->parcel($res['order_id'], $seller);
        $this->assertSame(self::m('0'), self::m($so->seller_shipping_charge), 'nothing is deducted from the seller for delivery');
        $this->assertSame(self::m('42.5'), self::m($so->seller_net_amount));
        $this->assertFalse((bool) $so->is_free_delivery);
        $this->assertSame('customer', Order::find($res['order_id'])->getAttribute('shipping_paid_by'));

        // The old ?free_delivery filter filters nothing
        $ids = collect($this->anonymous()->getJson("/api/products?seller_id={$seller->id}&free_delivery=1")->assertOk()->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($product->id));
    }

    // ── Q8: migration + rollback + seller notice ──────────────────────────────

    public function test_q8_migration_clears_the_flag_keeps_a_backup_and_rolls_back(): void
    {
        $seller  = $this->seller('free');
        $free    = $this->item($seller, 50, 'S', ['delivery_fee' => 0]);
        $normal  = $this->item($seller, 60, 'S');
        $before  = app(\App\Services\Pricing\PricingService::class)->priceMany([$free])[$free->id]['final_price'];

        // The data part of 2026_11_11_000002 (its table already exists: no DDL inside this test's transaction)
        $up = function () {
            DB::statement('INSERT IGNORE INTO legacy_free_delivery_products (product_id, seller_id, noted_at)
                SELECT id, seller_id, NOW() FROM products WHERE delivery_fee IS NOT NULL AND delivery_fee = 0');
            DB::table('products')->whereNotNull('delivery_fee')->where('delivery_fee', 0)->update(['delivery_fee' => null]);
        };
        $down = fn () => DB::table('products')
            ->whereIn('id', DB::table('legacy_free_delivery_products')->select('product_id'))->update(['delivery_fee' => 0]);
        $migration = file_get_contents(database_path('migrations/2026_11_11_000002_remove_seller_free_delivery.php'));
        $this->assertStringContainsString('INSERT IGNORE INTO legacy_free_delivery_products', $migration);
        $this->assertStringContainsString("->update(['delivery_fee' => 0])", $migration, 'down() restores the flags');

        $up();
        $this->assertNull(DB::table('products')->where('id', $free->id)->value('delivery_fee'));
        $this->assertTrue(DB::table('legacy_free_delivery_products')->where('product_id', $free->id)->where('seller_id', $seller->id)->exists());
        $this->assertFalse(DB::table('legacy_free_delivery_products')->where('product_id', $normal->id)->exists());
        // Its displayed price includes the fee, like before the flag was cleared (the flag is no longer read)
        $this->assertEquals(59.0, $before);
        $this->assertEquals(59.0, $this->anonymous()->getJson("/api/products/{$free->slug}")->assertOk()->json('data.final_price'));

        // Sellers concerned are told once (bell + e-mail), not by the migration itself
        $this->artisan('pricing:notify-free-delivery-sellers')->assertExitCode(0);
        Notification::assertNothingSent();
        $this->artisan('pricing:notify-free-delivery-sellers', ['--send' => true])->assertExitCode(0);
        Notification::assertSentTo($seller, FreeDeliveryEverywhereNotification::class, fn ($n, $channels) => in_array('mail', $channels) && in_array('database', $channels));
        $this->artisan('pricing:notify-free-delivery-sellers', ['--send' => true])->assertExitCode(0);
        Notification::assertSentToTimes($seller, FreeDeliveryEverywhereNotification::class, 1);

        // Rollback: the flags come back for the backed-up ids only
        $down();
        $this->assertSame(0.0, (float) DB::table('products')->where('id', $free->id)->value('delivery_fee'));
        $this->assertNull(DB::table('products')->where('id', $normal->id)->value('delivery_fee'));
    }

    public function test_the_seller_notice_says_the_owner_s_sentence_in_three_languages(): void
    {
        foreach (['fr' => 'La livraison est désormais gratuite sur toute la plateforme et incluse dans le prix affiché au client. Vous continuez à recevoir votre prix moins la commission.',
                  'en' => 'included in the price shown to the customer', 'ar' => 'ناقص العمولة'] as $locale => $text) {
            app()->setLocale($locale);
            $body = (new FreeDeliveryEverywhereNotification(2))->toDatabase($this->admin)['body'];
            $this->assertStringContainsString($text, $body);
        }
        app()->setLocale('fr');
    }

    // ── Q7: parcel size confirmation ──────────────────────────────────────────

    public function test_parcel_size_is_flagged_until_a_seller_or_admin_confirms_it(): void
    {
        $seller  = $this->seller('free');
        $product = $this->item($seller, 30, 'S');
        $this->assertNull($product->fresh()->parcel_size_confirmed_at, 'older products: never confirmed');

        $list = collect($this->as($this->admin)->getJson('/api/admin/products?status=all&size_unconfirmed=1&seller_id=' . $seller->id)->assertOk()->json('data.data'));
        $row  = $list->firstWhere('id', $product->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['parcel_size_confirmed']);
        $this->assertEquals(39.0, $row['display_price'], 'admin sees the seller price and the displayed one');
        $this->assertEquals(30.0, (float) $row['price']);

        // The admin picks the real size: confirmed, and the displayed price follows
        $this->as($this->admin)->putJson("/api/admin/products/{$product->id}", ['parcel_size' => 'L'])->assertOk();
        $this->assertNotNull($product->fresh()->parcel_size_confirmed_at);
        $this->assertEquals(41.0, $this->anonymous()->getJson("/api/products/{$product->slug}")->assertOk()->json('data.final_price'));
        $after = collect($this->as($this->admin)->getJson('/api/admin/products?status=all&size_unconfirmed=1&seller_id=' . $seller->id)->json('data.data'));
        $this->assertNull($after->firstWhere('id', $product->id));
    }

    // ── Q4: "the client will see −X %" in the promotion forms ─────────────────

    public function test_promotion_preview_tells_what_the_client_will_see(): void
    {
        $seller  = $this->seller('free');
        $product = $this->item($seller, 30, 'S');

        $row = $this->as($seller)->postJson('/api/seller/promotions/preview', [
            'product_ids' => [$product->id], 'discount_type' => 'percentage', 'discount_value' => 50,
        ])->assertOk()->json('data.0');
        $this->assertEquals(15.0, $row['final_price'], 'the seller is paid on 15.000');
        $this->assertEquals([39.0, 24.0, 38], [$row['client_original_price'], $row['client_final_price'], $row['client_percent']]);
    }

    // ── Cart: per-shop summary, selection, refreshed prices ───────────────────

    public function test_cart_summary_per_shop_and_for_a_selection(): void
    {
        $a = $this->seller('free');
        $b = $this->seller('free');
        $r1 = $this->addToCart($this->item($a, 30, 'S'));
        $r2 = $this->addToCart($this->item($a, 80, 'L'));
        $r3 = $this->addToCart($this->item($b, 50, 'S'));

        $cart  = $this->as($this->customer)->getJson('/api/cart')->assertOk()->json('data');
        $shops = collect($cart['summary']['shops'])->keyBy('seller_id');
        $this->assertEquals(189.0, $cart['subtotal']);
        $this->assertEquals([189.0, 9.0, 180.0], [$cart['summary']['subtotal'], $cart['summary']['multi_item_discount'], $cart['summary']['total']]);
        $this->assertEquals(9.0, $shops[$a->id]['multi_item_discount']);
        $this->assertNull($shops[$a->id]['upsell_min_saving']);
        $this->assertEquals(0.0, $shops[$b->id]['multi_item_discount']);
        $this->assertEquals(9.0, $shops[$b->id]['upsell_min_saving']);
        $this->assertEqualsCanonicalizing([$r1->id, $r2->id], $shops[$a->id]['item_ids']);

        // Only the shoes and seller B's item ticked: no discount left, two upsell hints
        $picked = $this->as($this->customer)->postJson('/api/cart/summary', ['item_ids' => [$r2->id, $r3->id]])->assertOk()->json('data');
        $this->assertEquals([150.0, 0.0, 150.0], [$picked['subtotal'], $picked['multi_item_discount'], $picked['total']]);
        $this->assertEquals([9.0, 9.0], array_column($picked['shops'], 'upsell_min_saving'));
        // Same numbers as the checkout quote for that selection
        $quote = $this->as($this->customer)->postJson('/api/checkout/quote', ['item_ids' => [$r2->id, $r3->id]])->assertOk()->json('data');
        $this->assertEquals($picked['total'], $quote['total']);
    }

    // ── Return form: the refund comes from the server ─────────────────────────

    public function test_return_preview_matches_what_the_return_then_refunds(): void
    {
        $seller = $this->seller('free');
        $tshirt = $this->item($seller, 30, 'S');
        $shoes  = $this->item($seller, 80, 'L');
        $this->addToCart($tshirt);
        $this->addToCart($shoes);
        $res = $this->checkout();
        DB::table('seller_orders')->where('order_id', $res['order_id'])->update(['status' => 'delivered']);
        DB::table('orders')->where('id', $res['order_id'])->update(['status' => 'delivered', 'updated_at' => now()]);
        $lines = DB::table('order_items')->where('order_id', $res['order_id'])->get()->keyBy('product_id');

        $preview = fn (array $body) => $this->as($this->customer)->postJson('/api/client/complaints/preview', ['order_id' => $res['order_id']] + $body)->assertOk()->json('data');

        // T-shirt alone, seller's fault: 30.000; changed mind: 30 − 4.280
        $one = ['items' => [['order_item_id' => $lines[$tshirt->id]->id, 'quantity' => 1]]];
        $this->assertEquals(30.0, $preview($one + ['complaint_type' => 'damaged_product'])['refund_amount']);
        $this->assertEquals(25.72, $preview($one + ['complaint_type' => 'changed_mind'])['refund_amount']);
        // Everything: 121.000, or 116.720 for a change of mind
        $this->assertEquals(121.0, $preview(['return_all' => 1, 'complaint_type' => 'damaged_product'])['refund_amount']);
        $all = $preview(['return_all' => 1, 'complaint_type' => 'changed_mind']);
        $this->assertEquals([121.0, 4.28, 116.72], [$all['items_amount'], $all['client_fee'], $all['refund_amount']]);

        // The form lists what the buyer paid per unit as displayed (never 30 / 80)
        $eligible = collect($this->as($this->customer)->getJson('/api/client/complaints/eligible-orders')->assertOk()->json('data'))->firstWhere('id', $res['order_id']);
        $this->assertEqualsCanonicalizing([39.0, 91.0], array_column($eligible['items'], 'unit_price'));
    }

    // ── Chatbot: display prices ───────────────────────────────────────────────

    public function test_chatbot_retriever_prices_and_filters_on_display_prices(): void
    {
        $seller  = $this->seller('free');
        $word    = 'zorglub' . strtolower(Str::random(5));
        $product = Product::create([
            'seller_id' => $seller->id, 'name' => "Sac {$word}", 'slug' => "sac-{$word}", 'price' => 30,
            'parcel_size' => 'L', 'stock' => 5, 'is_approved' => true, 'is_active' => true,
        ]);

        $retriever = app(\App\Services\Chat\ProductRetriever::class);
        $found = collect($retriever->search(['keywords' => [$word], 'min_price' => 41, 'max_price' => 41])['products']);
        $hit   = $found->firstWhere('id', $product->id);
        $this->assertNotNull($hit, 'price filter on the display price (30 + 11)');
        $this->assertEquals(41.0, $hit['price']);
        $this->assertNull(collect($retriever->search(['keywords' => [$word], 'max_price' => 40])['products'])->firstWhere('id', $product->id));
    }
}
