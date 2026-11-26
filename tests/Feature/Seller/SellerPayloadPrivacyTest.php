<?php

namespace Tests\Feature\Seller;

use App\Models\Complaint;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Delivery\DeliveryTestHelpers;
use Tests\TestCase;

/**
 * No seller-facing response carries the platform's money: its profit or margin,
 * the delivery fee collected, or what the delivery company costs.
 *
 * Every GET route under /api/seller is called (a new one is covered at once),
 * plus the actions that answer with an order or a return.
 */
class SellerPayloadPrivacyTest extends TestCase
{
    use DatabaseTransactions, DeliveryTestHelpers;

    /** Keys that must never reach a seller, wherever they sit in a payload. */
    private const FORBIDDEN = [
        'platform_profit', 'total_platform_profit', 'platform_delivery_margin', 'delivery_margin',
        'delivery_fee', 'total_delivery_fees', 'delivery_fee_charged', 'delivery_cost', 'delivery_mode', 'delivery_refund',
        'client_delivery_fee', 'agency_delivery_cost', 'agency_cost', 'shipping_cost', 'shipping_fee',
        'amount_to_remit', 'cod_amount', 'client_total', 'parcel_fee', 'fee_rates', 'customer_delivery_fee', 'delivery_included',
        'included_fee', 'included_fee_total', 'included_fees_total', 'multi_item_discount',
        'seller_free_delivery_contribution',
    ];

    /**
     * Allowed on purpose: earnings.shipping_paid_by is a label ("you" / "customer" /
     * "platform"), not an amount, and the order drawer shows it.
     *
     * Not covered: GET orders/{id}/invoice is the CUSTOMER's invoice (it shows what
     * the customer pays for delivery) and is switched off (platform.seller_invoice_enabled).
     */

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('public');
        \Illuminate\Support\Facades\Http::fake();   // the AI seller pages call Groq
        config(['intigo.mode' => 'fake']);
        $this->setUpDelivery();
    }

    private function item(User $seller, $price, string $size = 'S'): Product
    {
        $name = 'Priv Product ' . Str::random(8);
        return Product::create([
            'seller_id' => $seller->id, 'name' => $name, 'slug' => Str::slug($name), 'price' => $price,
            'parcel_size' => $size, 'stock' => 50, 'is_approved' => true, 'is_active' => true,
        ]);
    }

    /** @return string[] "path.to.key" of every forbidden key found */
    private function leaks($value, string $path = ''): array
    {
        $found = [];
        if (!is_array($value)) {
            return $found;
        }
        foreach ($value as $key => $child) {
            $at = $path === '' ? (string) $key : "{$path}.{$key}";
            if (in_array((string) $key, self::FORBIDDEN, true) && $child !== null) {
                $found[] = $at;
            }
            array_push($found, ...$this->leaks($child, $at));
        }
        return $found;
    }

    public function test_no_seller_response_carries_the_platform_s_money(): void
    {
        $leaks = [];
        $answered = 0;
        $check = function (string $label, \Illuminate\Testing\TestResponse $res, bool $mustAnswer = false) use (&$leaks, &$answered): void {
            if ($mustAnswer) {
                $res->assertOk();
            }
            $json = json_decode((string) $res->baseResponse->getContent(), true);
            if ($res->getStatusCode() >= 300 || !is_array($json)) {
                return;
            }
            $answered++;
            foreach ($this->leaks($json) as $path) {
                $leaks[] = $label . ' → ' . preg_replace('/\.\d+(?=\.|$)/', '.*', $path);
            }
        };

        // One separate-mode seller (the most delivery data there is) and one included one
        foreach (['separate', 'included'] as $mode) {
            $this->travel(2)->minutes();
            $seller = $this->seller('black');
            $this->as($this->admin)->postJson('/api/admin/sellers/delivery-mode', ['seller_ids' => [$seller->id], 'mode' => $mode])->assertOk();
            foreach ([[$this->item($seller, 30, 'S'), 1], [$this->item($seller, 80, 'L'), 2]] as [$p, $qty]) {
                $this->addToCart($p, $qty);
            }
            $orderId = $this->checkout()['order_id'];
            $so = $this->parcel($orderId, $seller);
            // The actions that answer with the parcel itself
            $check('PATCH orders/{id}/status', $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'cancelled']), true);
            $check('PATCH orders/{id}/status (again)', $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'cancelled']), true);
            $this->travel(2)->minutes();
            $this->addToCart($this->item($seller, 30, 'S'));
            $this->addToCart($this->item($seller, 80, 'L'), 2);
            $orderId = $this->checkout()['order_id'];
            $so = $this->parcel($orderId, $seller);
            $this->moveTo($so, 'confirmed');
            $check('POST orders/{id}/prepared', $this->as($seller)->postJson("/api/seller/orders/{$so->id}/prepared"), true);
            $this->deliver($so);
            $this->confirmRemittance($so)->assertOk();
            DB::table('seller_orders')->where('id', $so->id)->update(['payment_status' => 'unpaid']);
            $check('PATCH orders/{id}/payment', $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/payment", ['payment_status' => 'refunded']), true);
            DB::table('seller_orders')->where('id', $so->id)->update(['payment_status' => 'paid']);
            $batch = $this->as($this->admin)->postJson('/api/admin/settlements/create', ['seller_id' => $seller->id, 'batch_date' => now()->toDateString()])->assertOk()->json('data');

            // A full return the seller is at fault for, walked to the sale reversal
            DB::table('orders')->where('id', $orderId)->update(['status' => 'delivered', 'updated_at' => now()]);
            $c = Complaint::findOrFail($this->as($this->customer)->post('/api/client/complaints', [
                'order_id' => $orderId, 'complaint_type' => 'damaged_product', 'return_all' => 1,
                'description' => 'Detailed reason for this return request, long enough.',
                'images' => [UploadedFile::fake()->image('proof.jpg', 400, 300)],
            ], ['Accept' => 'application/json'])->assertCreated()->json('data.id'));
            $check('PATCH complaints/{id}/approve', $this->as($seller)->patchJson("/api/seller/complaints/{$c->id}/approve", ['seller_note' => 'ok']));
            $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/approve")->assertOk();
            $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/schedule-pickup", ['carrier' => 'Intigo'])->assertOk();
            $this->as($this->admin)->patchJson("/api/admin/complaints/{$c->id}/picked-up")->assertOk();
            $check('PATCH complaints/{id}/received', $this->as($seller)->patchJson("/api/seller/complaints/{$c->id}/receive", [
                'conditions' => $c->items()->pluck('order_item_id')->mapWithKeys(fn ($id) => [$id => 'resaleable'])->all(),
            ]));

            $ids = ['complaints' => $c->id, 'orders' => $so->id, 'earnings/orders' => $so->id, 'earnings/settlement' => $batch['id']];
            foreach (Route::getRoutes() as $route) {
                $uri = $route->uri();
                if (!str_starts_with($uri, 'api/seller/') || !in_array('GET', $route->methods(), true)) {
                    continue;
                }
                if (str_contains($uri, '{')) {
                    $prefix = trim(Str::before(Str::after($uri, 'api/seller/'), '/{'), '/');
                    if (!isset($ids[$prefix])) {
                        continue;
                    }
                    $uri = preg_replace('/\{[^}]+\}/', (string) $ids[$prefix], $uri);
                }
                foreach (['', '?period=all'] as $query) {
                    $check("GET {$uri}", $this->as($seller)->getJson("/{$uri}{$query}"));
                }
            }
            $check('GET notifications', $this->as($seller)->getJson('/api/notifications'));
        }

        $this->assertGreaterThan(60, $answered, 'the routes answered');
        $this->assertSame([], array_values(array_unique($leaks)));
    }
}
