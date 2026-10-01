<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Recommendation\MakesCatalog;
use Tests\TestCase;

/** php vendor/bin/phpunit tests/Feature/DemoPurgeTest.php */
class DemoPurgeTest extends TestCase
{
    use DatabaseTransactions, MakesCatalog;

    private User $demoSeller;
    private User $demoClient;
    private Product $demoProduct;
    private Product $realProduct;
    private User $realClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTranslator();
        Storage::fake('public');
        Storage::disk('public')->put('products/demo/a.png', 'png');

        $s = Str::lower(Str::random(6));
        $cat = $this->makeCategory();
        $this->demoSeller = $this->makeUser('seller', ['email' => "demo.seller.$s@choosetounsi.test"]);
        $this->demoClient = $this->makeUser('client', ['email' => "demo.client$s@choosetounsi.test"]);
        $this->realClient = $this->makeUser('client');
        $this->demoProduct = $this->makeProduct($this->demoSeller, $cat);
        $this->realProduct = $this->makeProduct($this->makeUser('seller'), $cat);

        DB::table('product_images')->insert(['product_id' => $this->demoProduct->id, 'image_path' => 'products/demo/a.png', 'is_primary' => 1, 'order' => 0]);
        DB::table('favorites')->insert(['user_id' => $this->realClient->id, 'product_id' => $this->demoProduct->id]);
        $orderId = $this->order($this->demoClient, $this->demoProduct);
        $itemId = DB::table('order_items')->where('order_id', $orderId)->value('id');
        DB::table('reviews')->insert(['user_id' => $this->demoClient->id, 'product_id' => $this->demoProduct->id, 'order_item_id' => $itemId,
            'seller_id' => $this->demoSeller->id, 'rating' => 5, 'body' => 'Demo review.', 'status' => 'approved']);
    }

    private function order(User $client, Product $product): int
    {
        $orderId = DB::table('orders')->insertGetId(['user_id' => $client->id, 'order_number' => 'T-' . Str::random(10),
            'subtotal' => 50, 'total_amount' => 50, 'status' => 'delivered']);
        $so = DB::table('seller_orders')->insertGetId(['order_id' => $orderId, 'seller_id' => $product->seller_id, 'status' => 'delivered',
            'subtotal' => 50, 'seller_net_amount' => 50]);
        DB::table('order_items')->insert(['order_id' => $orderId, 'seller_order_id' => $so, 'product_id' => $product->id, 'quantity' => 1,
            'unit_price' => 50, 'price' => 50, 'total' => 50, 'net_total' => 50, 'seller_amount' => 50]);
        return $orderId;
    }

    public function test_dry_run_lists_and_deletes_nothing(): void
    {
        $this->assertSame(0, Artisan::call('demo:purge', ['--dry-run' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString($this->demoSeller->email, $output);
        $this->assertStringContainsString('Dry run', $output);

        $this->assertDatabaseHas('products', ['id' => $this->demoProduct->id]);
        $this->assertDatabaseHas('users', ['id' => $this->demoClient->id]);
        Storage::disk('public')->assertExists('products/demo/a.png');
    }

    public function test_purge_removes_demo_data_and_everything_hanging_off_it(): void
    {
        $this->artisan('demo:purge', ['--force' => true])->assertExitCode(0);

        foreach ([$this->demoSeller, $this->demoClient] as $u) {
            $this->assertDatabaseMissing('users', ['id' => $u->id]);
        }
        $this->assertDatabaseMissing('products', ['id' => $this->demoProduct->id]);
        $this->assertDatabaseMissing('product_images', ['product_id' => $this->demoProduct->id]);
        $this->assertDatabaseMissing('orders', ['user_id' => $this->demoClient->id]);
        $this->assertDatabaseMissing('reviews', ['product_id' => $this->demoProduct->id]);
        $this->assertDatabaseMissing('favorites', ['product_id' => $this->demoProduct->id]);
        Storage::disk('public')->assertMissing('products/demo/a.png');

        $this->assertDatabaseHas('products', ['id' => $this->realProduct->id]);
        $this->assertDatabaseHas('users', ['id' => $this->realClient->id]);
    }

    public function test_refuses_when_a_real_order_contains_a_demo_product(): void
    {
        $this->order($this->realClient, $this->demoProduct);

        $this->assertSame(1, Artisan::call('demo:purge', ['--force' => true]));
        $this->assertStringContainsString('Real orders contain demo products', Artisan::output());

        $this->assertDatabaseHas('products', ['id' => $this->demoProduct->id]);
    }
}
