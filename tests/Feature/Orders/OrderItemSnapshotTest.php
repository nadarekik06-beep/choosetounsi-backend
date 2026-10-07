<?php

namespace Tests\Feature\Orders;

use App\Helpers\PlatformUser;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Cart;
use App\Models\Complaint;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerApplication;
use App\Models\User;
use App\Services\Orders\OrderItemSnapshot;
use App\Services\ProductImages;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An order line shows what the buyer bought — its own variant's image, name,
 * attributes and price — on the complaint form and every complaint view, even
 * after the seller edits or deletes the variant or its photos.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Orders/OrderItemSnapshotTest.php
 */
class OrderItemSnapshotTest extends TestCase
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
    private array $opt = [];   // 'Rouge' / 'Bleu' / 'M' => option id
    private string $r;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.shipping_cost' => 8.0]);
        Notification::fake();
        Storage::fake('public');
        ProductImages::flush();
        OrderItemSnapshot::flush();

        if (!DB::table('users')->where('id', 1)->exists()) {
            DB::table('users')->insert([
                'id' => 1, 'name' => "CHOOSE'Tounsi", 'email' => 'platform-' . Str::random(6) . '@test.local',
                'password' => bcrypt('x'), 'role' => 'seller', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        PlatformUser::reset();

        $this->r      = Str::random(5);
        $this->seller = $this->makeUser('seller');
        SellerApplication::create([
            'user_id' => $this->seller->id, 'full_name' => 'Mohamed Trabelsi', 'phone_number' => '55111222',
            'business_name' => 'Atelier ' . $this->r, 'business_category' => 'crafts', 'business_description' => 'Test shop',
            'wilaya' => 'Sfax', 'city' => 'Sakiet Ezzit', 'pickup_address' => 'Route de Tunis km 5',
            'pickup_postal_code' => '3021', 'status' => 'approved',
        ]);

        $color = Attribute::firstOrCreate(['slug' => 'color'], ['name' => 'Color', 'name_ar' => 'Color', 'name_fr' => 'Couleur', 'type' => 'color']);
        $size  = Attribute::create(['name' => 'Taille', 'name_ar' => 'Taille', 'name_fr' => 'Taille', 'slug' => "size-{$this->r}", 'type' => 'select']);
        $this->opt['Rouge'] = AttributeOption::create(['attribute_id' => $color->id, 'value' => "Rouge{$this->r}", 'color_hex' => '#ff0000'])->id;
        $this->opt['Bleu']  = AttributeOption::create(['attribute_id' => $color->id, 'value' => "Bleu{$this->r}", 'color_hex' => '#0000ff'])->id;
        $this->opt['M']     = AttributeOption::create(['attribute_id' => $size->id, 'value' => 'M'])->id;
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

    /** T-shirt in Rouge / M and Bleu / M; each color has its own photo, the blue one is the cover. */
    private function tshirt(): array
    {
        $name = 'T-shirt ' . Str::random(6);
        $p = Product::create([
            'seller_id' => $this->seller->id, 'name' => $name, 'slug' => Str::slug($name),
            'price' => 30, 'stock' => 20, 'is_approved' => true, 'is_active' => true,
        ]);
        $red  = ProductVariant::create(['product_id' => $p->id, 'sku' => 'R-' . Str::random(6), 'stock' => 10, 'is_active' => true]);
        $red->attributeOptions()->sync([$this->opt['Rouge'], $this->opt['M']]);
        $blue = ProductVariant::create(['product_id' => $p->id, 'sku' => 'B-' . Str::random(6), 'stock' => 10, 'is_active' => true]);
        $blue->attributeOptions()->sync([$this->opt['Bleu'], $this->opt['M']]);

        $redImg  = $this->image($p, 'red-photo',  $this->opt['Rouge'], false);
        $blueImg = $this->image($p, 'blue-photo', $this->opt['Bleu'], true);

        return [$p, $red, $blue, $redImg, $blueImg];
    }

    private function image(Product $p, string $content, ?int $colorId, bool $primary, int $order = 0): ProductImage
    {
        $path = 'products/' . Str::random(12) . '.png';
        Storage::disk('public')->put($path, $content);
        return ProductImage::create([
            'product_id' => $p->id, 'image_path' => $path, 'color_option_id' => $colorId,
            'order' => $order, 'is_primary' => $primary,
        ]);
    }

    /** The url path checkout must freeze for these image bytes. */
    private function snapshotUrl(string $content): string
    {
        $hash = sha1($content);
        return '/storage/' . OrderItemSnapshot::DIR . '/' . substr($hash, 0, 2) . "/{$hash}.png";
    }

    /** @param array<array{0: Product, 1: ?ProductVariant, 2: int}> $lines */
    private function deliveredOrder(array $lines, ?User $buyer = null): Order
    {
        $buyer ??= $this->makeUser('client');
        foreach ($lines as [$product, $variant, $qty]) {
            Cart::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant?->id, 'quantity' => $qty]);
        }
        $res = $this->as($buyer)->postJson('/api/checkout', self::ADDRESS + ['payment_method' => 'cod'])->assertCreated();

        $order = Order::findOrFail($res->json('order_id'));
        DB::table('seller_orders')->where('order_id', $order->id)->update(['status' => 'delivered']);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'delivered', 'updated_at' => now()]);
        return $order->fresh();
    }

    private function eligibleItems(Order $order): array
    {
        $this->app['auth']->forgetGuards();
        $res = $this->as($order->user)->getJson('/api/client/complaints/eligible-orders')->assertOk();
        return collect($res->json('data'))->firstWhere('id', $order->id)['items'] ?? [];
    }

    private function line(Order $order, ProductVariant $variant): OrderItem
    {
        return OrderItem::where('order_id', $order->id)->where('variant_id', $variant->id)->firstOrFail();
    }

    private function seenAfterCatalogChange(): void
    {
        ProductImages::flush();
        OrderItemSnapshot::flush();
    }

    // ── Complaint selector ────────────────────────────────────────────────────

    public function test_two_variants_of_one_product_are_two_choices_each_with_its_own_image(): void
    {
        [$p, $red, $blue] = $this->tshirt();
        $order = $this->deliveredOrder([[$p, $red, 1], [$p, $blue, 2]]);

        $items = collect($this->eligibleItems($order))->keyBy('variant_id');
        $this->assertCount(2, $items, 'one choice per order line');

        $this->assertSame($this->line($order, $red)->id, $items[$red->id]['id'], 'chosen by order_item_id');
        $this->assertStringEndsWith($this->snapshotUrl('red-photo'), $items[$red->id]['image_url']);
        $this->assertStringEndsWith($this->snapshotUrl('blue-photo'), $items[$blue->id]['image_url']);
        $this->assertSame("Rouge{$this->r} / M", $items[$red->id]['variant_label']);
        $this->assertSame("Bleu{$this->r} / M", $items[$blue->id]['variant_label']);
        $this->assertSame(2, $items[$blue->id]['quantity']);
        $this->assertEquals(30, $items[$red->id]['unit_price']);
        $this->assertSame('#ff0000', collect($items[$red->id]['variant_attributes'])->firstWhere('slug', 'color')['color_hex']);

        $line = $this->line($order, $red);
        $this->assertSame(OrderItemSnapshot::SRC_VARIANT, $line->image_source);
        Storage::disk('public')->assertExists(ltrim(str_replace('/storage/', '', $line->image_url), '/'));
    }

    public function test_seller_replacing_or_deleting_the_photo_after_purchase_does_not_change_the_complaint(): void
    {
        [$p, $red, , $redImg] = $this->tshirt();
        $order = $this->deliveredOrder([[$p, $red, 1]]);

        // Seller swaps the red photo for a new one and the old file is deleted
        Storage::disk('public')->delete($redImg->image_path);
        $redImg->delete();
        $this->image($p, 'new-red-photo', $this->opt['Rouge'], false);
        $p->update(['name' => 'Renamed by the seller']);
        $this->seenAfterCatalogChange();

        $items = $this->eligibleItems($order);
        $this->assertStringEndsWith($this->snapshotUrl('red-photo'), $items[0]['image_url']);
        $this->assertNotSame('Renamed by the seller', $items[0]['product_name']);
        Storage::disk('public')->assertExists(ltrim(str_replace('/storage/', '', $this->snapshotUrl('red-photo')), '/'));

        // …and deleting the variant itself changes nothing either
        $red->delete();
        $this->seenAfterCatalogChange();
        $items = $this->eligibleItems($order);
        $this->assertStringEndsWith($this->snapshotUrl('red-photo'), $items[0]['image_url']);
        $this->assertSame("Rouge{$this->r} / M", $items[0]['variant_label']);
    }

    // ── Fallback chain (lines without a snapshot) ─────────────────────────────

    public function test_fallback_chain_never_shows_another_variants_image(): void
    {
        [$p, $red] = $this->tshirt();
        $order = $this->deliveredOrder([[$p, $red, 1]]);
        $line  = $this->line($order, $red);

        // A line from before the snapshot existed
        DB::table('order_items')->where('id', $line->id)->update(['image_url' => null, 'image_source' => null]);

        // 2. live variant image
        $this->assertStringEndsWith(Storage::url(ProductImage::where('product_id', $p->id)->where('color_option_id', $this->opt['Rouge'])->value('image_path')),
            OrderItem::find($line->id)->displayImageUrl());

        // Variant deleted, attributes snapshot gone too: the bought color is unknown.
        // The cover is the BLUE photo → it must not be shown: placeholder (null).
        $red->delete();
        DB::table('order_items')->where('id', $line->id)->update(['variant_attributes' => null]);
        $this->seenAfterCatalogChange();
        $this->assertNull(OrderItem::find($line->id)->displayImageUrl());

        // 3. a color-less product photo is the main image fallback
        $gallery = $this->image($p, 'gallery-photo', null, false, 9);
        $this->seenAfterCatalogChange();
        $this->assertStringEndsWith(Storage::url($gallery->image_path), OrderItem::find($line->id)->displayImageUrl());

        // 4. product gone entirely: placeholder
        DB::table('order_items')->where('id', $line->id)->update(['product_id' => null]);
        $this->assertNull(OrderItem::find($line->id)->displayImageUrl());
    }

    public function test_attributes_snapshot_keeps_the_color_after_the_variant_is_deleted(): void
    {
        [$p, $red, , $redImg] = $this->tshirt();
        $order = $this->deliveredOrder([[$p, $red, 1]]);
        $line  = $this->line($order, $red);
        DB::table('order_items')->where('id', $line->id)->update(['image_url' => null, 'image_source' => null]);

        $red->delete();
        $this->seenAfterCatalogChange();

        $this->assertStringEndsWith(Storage::url($redImg->image_path), OrderItem::find($line->id)->displayImageUrl(),
            'the red group is found from variant_attributes.option_id');
    }

    // ── Complaint record and views ────────────────────────────────────────────

    public function test_complaint_stores_the_order_item_and_every_view_shows_that_line_as_bought(): void
    {
        [$p, $red, $blue] = $this->tshirt();
        $order = $this->deliveredOrder([[$p, $red, 1], [$p, $blue, 1]]);
        $redLine = $this->line($order, $red);

        $res = $this->as($order->user)->postJson('/api/client/complaints', [
            'order_id' => $order->id, 'complaint_type' => 'wrong_color', 'resolution_type' => 'exchange',
            'description' => 'I received a different shade than the one shown.',
            'item_ids' => [$redLine->id],
        ])->assertCreated();

        $complaint = Complaint::findOrFail($res->json('data.id'));
        $this->assertSame([$redLine->id], $complaint->order_item_ids);

        $check = function (array $items) use ($redLine) {
            $this->assertCount(1, $items, 'only the complained line');
            $this->assertSame($redLine->id, $items[0]['id']);
            $this->assertStringEndsWith($this->snapshotUrl('red-photo'), $items[0]['image_url']);
            $this->assertSame("Rouge{$this->r} / M", $items[0]['variant_label']);
        };

        $check($res->json('data.complained_items'));
        $check($this->as($order->user)->getJson("/api/client/complaints/{$complaint->id}")->assertOk()->json('data.complained_items'));
        $check($this->as($order->user)->getJson('/api/client/complaints')->assertOk()->json('data.data.0.complained_items'));
        $check($this->as($this->seller)->getJson("/api/seller/complaints/{$complaint->id}")->assertOk()->json('data.complained_items'));
        $check($this->as($this->seller)->getJson('/api/seller/complaints')->assertOk()->json('data.data.0.complained_items'));
        $admin = $this->makeUser('admin');
        $check($this->as($admin)->getJson("/api/admin/complaints/{$complaint->id}")->assertOk()->json('data.complained_items'));

        // E-mails name the same line
        $this->assertSame(["{$redLine->product_name} — Rouge{$this->r} / M × 1"], $complaint->itemSummaries());
        $this->assertArrayNotHasKey('complained_items', $complaint->getAttributes(), 'never saved as a column');
    }

    public function test_item_from_another_order_is_refused(): void
    {
        [$p, $red, $blue] = $this->tshirt();
        $order = $this->deliveredOrder([[$p, $red, 1]]);
        $other = $this->deliveredOrder([[$p, $blue, 1]]);

        $this->as($order->user)->postJson('/api/client/complaints', [
            'order_id' => $order->id, 'complaint_type' => 'wrong_color', 'resolution_type' => 'exchange',
            'description' => 'I received a different shade than the one shown.',
            'item_ids' => [$this->line($other, $blue)->id],
        ])->assertStatus(422);
    }

    // ── Related screens ───────────────────────────────────────────────────────

    public function test_order_detail_and_review_prompt_show_the_bought_variant(): void
    {
        [$p, $red, $blue] = $this->tshirt();
        $order = $this->deliveredOrder([[$p, $red, 1], [$p, $blue, 1]]);

        $items = collect($this->as($order->user)->getJson("/api/client/orders/{$order->id}")->assertOk()->json('data.items'))->keyBy('variant_id');
        $this->assertStringEndsWith($this->snapshotUrl('red-photo'), $items[$red->id]['resolved_image_url']);
        $this->assertStringEndsWith($this->snapshotUrl('blue-photo'), $items[$blue->id]['resolved_image_url']);

        $review = collect($this->as($order->user)->getJson('/api/client/reviews/eligible')->assertOk()->json('data'))->keyBy('order_item_id');
        $this->assertStringEndsWith($this->snapshotUrl('red-photo'), $review[$this->line($order, $red)->id]['product_image']);
    }

    // ── Backfill ──────────────────────────────────────────────────────────────

    public function test_backfill_fills_old_lines_once_and_logs_the_inexact_ones(): void
    {
        [$p, $red, $blue] = $this->tshirt();
        $order = $this->deliveredOrder([[$p, $red, 1], [$p, $blue, 1]]);
        $redLine  = $this->line($order, $red);
        $blueLine = $this->line($order, $blue);

        // Pretend both lines predate the snapshot; the blue variant was later deleted
        DB::table('order_items')->whereIn('id', [$redLine->id, $blueLine->id])
            ->update(['image_url' => null, 'image_source' => null, 'variant_attributes' => null]);
        $blue->delete();
        $this->seenAfterCatalogChange();

        $this->artisan('orders:snapshot-items')->assertSuccessful();

        $red = OrderItem::find($redLine->id);
        $this->assertSame(OrderItemSnapshot::SRC_VARIANT, $red->image_source);
        $this->assertSame($this->snapshotUrl('red-photo'), $red->image_url);
        $this->assertSame("Rouge{$this->r}", collect($red->variant_attributes)->firstWhere('slug', 'color')['value']);

        $blueNow = OrderItem::find($blueLine->id);
        $this->assertNull($blueNow->variant_id);
        $this->assertSame(OrderItemSnapshot::SRC_LABEL, $blueNow->image_source, 'color recovered from "Bleu… / M"');
        $this->assertSame($this->snapshotUrl('blue-photo'), $blueNow->image_url);

        $before = DB::table('order_items')->whereIn('id', [$redLine->id, $blueLine->id])->orderBy('id')->get()->toArray();

        $this->seenAfterCatalogChange();
        $this->artisan('orders:snapshot-items')
            ->expectsOutput('Order lines to snapshot: 0')
            ->assertSuccessful();

        $this->assertEquals($before, DB::table('order_items')->whereIn('id', [$redLine->id, $blueLine->id])->orderBy('id')->get()->toArray());
    }

    public function test_backfill_dry_run_writes_nothing(): void
    {
        [$p, $red] = $this->tshirt();
        $order = $this->deliveredOrder([[$p, $red, 1]]);
        $line  = $this->line($order, $red);
        DB::table('order_items')->where('id', $line->id)->update(['image_url' => null, 'image_source' => null]);

        $this->artisan('orders:snapshot-items', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull(OrderItem::find($line->id)->image_source);
    }
}
