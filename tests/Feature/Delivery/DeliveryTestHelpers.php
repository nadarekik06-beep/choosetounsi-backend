<?php

namespace Tests\Feature\Delivery;

use App\Helpers\PlatformUser;
use App\Models\Cart;
use App\Models\Pack;
use App\Models\PackItem;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\SellerApplication;
use App\Models\User;
use App\Services\Delivery\DeliverySettings;
use App\Services\FinancialSnapshotService;
use App\Support\Millimes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared fixtures for the delivery-fee / COD finance tests.
 * Admin values used everywhere: client 8.000, agency 7.000, seller contribution 6.000.
 * Commission: the real plan rates of the test DB (tiers by unit price, free −0, red −3, black −6).
 */
trait DeliveryTestHelpers
{
    protected User $admin;
    protected User $customer;

    protected function setUpDelivery(): void
    {
        PlatformSetting::flushCache();
        PlatformUser::reset();
        app(DeliverySettings::class)->flush();
        $this->admin    = $this->makeUser('admin');
        $this->customer = $this->makeUser('client');
        $this->setFees(8, 7, 6);
    }

    protected function setFees($client, $agency, $contribution, array $extra = []): void
    {
        $s = app(DeliverySettings::class);
        $s->update([
            'client_delivery_fee'               => Millimes::of($client),
            'agency_delivery_cost'              => Millimes::of($agency),
            'seller_free_delivery_contribution' => Millimes::of($contribution),
        ] + $extra, $this->admin->id);
    }

    protected function makeUser(string $role): User
    {
        return $this->withCompleteProfile(User::create([
            'name'      => ucfirst($role) . ' ' . Str::random(5),
            'email'     => $role . '_' . Str::random(10) . '@test.local',
            'password'  => bcrypt('secret-password'),
            'role'      => $role,
            'is_active' => true,
        ]));
    }

    protected function seller(string $plan = 'free'): User
    {
        $seller = $this->makeUser('seller');
        SellerApplication::create([
            'user_id' => $seller->id, 'full_name' => 'Seller ' . $plan, 'phone_number' => '55111222',
            'business_name' => 'Shop ' . Str::random(5), 'business_category' => 'crafts', 'business_description' => 'Test shop',
            'wilaya' => 'Sfax', 'city' => 'Sakiet Ezzit', 'pickup_address' => 'Route de Tunis km 5',
            'pickup_postal_code' => '3021', 'status' => 'approved', 'plan' => $plan,
        ]);
        return $seller;
    }

    protected function product(User $seller, $price, bool $free = false, int $stock = 50): Product
    {
        $name = 'Del Product ' . Str::random(8);
        return Product::create([
            'seller_id' => $seller->id, 'name' => $name, 'slug' => Str::slug($name), 'price' => $price,
            'delivery_fee' => $free ? 0 : null, 'stock' => $stock, 'is_approved' => true, 'is_active' => true,
        ]);
    }

    protected function promotion(Product $product, string $type, $percent): Promotion
    {
        $promo = Promotion::create([
            'seller_id' => $product->seller_id, 'name' => ucfirst($type) . ' ' . Str::random(4), 'type' => $type,
            'discount_type' => 'percentage', 'discount_value' => $percent, 'status' => 'active',
            'priority' => $type === 'flash_sale' ? 10 : 5,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(2),
            'flash_stock' => $type === 'flash_sale' ? 20 : null, 'flash_stock_used' => 0,
        ]);
        $promo->products()->attach($product->id);
        return $promo;
    }

    /** @param array<array{0: Product, 1: int}> $items product + quantity per pack */
    protected function pack(User $owner, $price, array $items): Pack
    {
        $pack = Pack::create([
            'seller_id' => $owner->id, 'name' => 'Pack ' . Str::random(5), 'pack_price' => $price,
            'original_price' => $price, 'is_active' => true, 'is_approved' => true,
        ]);
        foreach ($items as $i => [$product, $qty]) {
            PackItem::create(['pack_id' => $pack->id, 'product_id' => $product->id, 'quantity' => $qty, 'order' => $i]);
        }
        return $pack;
    }

    protected function addToCart(Product $product, int $qty = 1, ?User $customer = null): Cart
    {
        return Cart::create(['user_id' => ($customer ?? $this->customer)->id, 'product_id' => $product->id, 'quantity' => $qty]);
    }

    protected function addPack(Pack $pack, int $qty = 1, $snapshot = null): Cart
    {
        return Cart::create([
            'user_id' => $this->customer->id, 'pack_id' => $pack->id, 'quantity' => $qty,
            'pack_price_snapshot' => $snapshot ?? $pack->pack_price, 'pack_name' => $pack->name, 'pack_selections' => [],
        ]);
    }

    protected function as(User $user)
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken]);
    }

    protected function address(): array
    {
        return [
            'recipient_name' => 'Test Buyer', 'wilaya' => 'Tunis', 'delegation' => 'Bab Bhar',
            'address' => '1 rue Test', 'postal_code' => '1000', 'phone' => '22123456',
        ];
    }

    protected function quote(array $body = []): array
    {
        return $this->as($this->customer)->postJson('/api/checkout/quote', $body)->assertOk()->json('data');
    }

    /** Places the cart order as the storefront does: quote first, then checkout with the quoted total. */
    protected function checkout(array $body = []): array
    {
        $quote = $this->quote(array_intersect_key($body, array_flip(['coupon_codes'])));
        $res = $this->as($this->customer)->postJson('/api/checkout', $this->address() + $body + [
            'payment_method' => 'cod', 'expected_total' => $quote['total'],
        ])->assertCreated()->json();
        $this->assertEquals($quote['total'], $res['total'], 'charged total = quoted total');
        $this->assertEquals($quote['parcels'], $res['parcels'], 'charged parcels = quoted parcels');
        return $res;
    }

    protected function parcel(int $orderId, ?User $seller): object
    {
        return DB::table('seller_orders')->where('order_id', $orderId)
            ->where('seller_id', $seller?->id)->first();
    }

    protected static function m($v): int
    {
        return Millimes::of($v ?? 0);
    }

    /**
     * Every figure of one parcel, in TND strings, plus the money check:
     * cod = payout + commission + agency fee + platform delivery margin.
     */
    protected function assertParcel(object $so, array $expected): void
    {
        $actual = [
            'items'        => $so->subtotal,
            'coupon'       => $so->discount_amount,
            'delivery_fee' => $so->delivery_fee,
            'cod'          => $so->cod_amount,
            'commission'   => $so->commission_amount,
            'contribution' => $so->seller_shipping_charge,
            'payout'       => $so->seller_net_amount,
            'agency'       => $so->shipping_cost,
            'margin'       => $so->platform_delivery_margin,
            'to_remit'     => $so->amount_to_remit,
            'free'         => (bool) $so->is_free_delivery,
        ];
        foreach ($expected as $key => $value) {
            if ($key === 'free') {
                $this->assertSame($value, $actual['free'], "parcel {$so->id}: free");
                continue;
            }
            $this->assertSame(self::m($value), self::m($actual[$key]), "parcel {$so->id}: {$key} = {$actual[$key]}, expected {$value}");
        }
        $this->assertBalanced($so);
    }

    protected function assertBalanced(object $so): void
    {
        $so = DB::table('seller_orders')->where('id', $so->id)->first();
        $this->assertSame(
            self::m($so->cod_amount),
            self::m($so->seller_net_amount) + self::m($so->commission_amount) + self::m($so->shipping_cost) + self::m($so->platform_delivery_margin),
            "money check fails on parcel {$so->id}"
        );
        $this->assertTrue(FinancialSnapshotService::checkParcel($so->id));
        // Snapshot of the settings in force
        $this->assertNotNull($so->client_delivery_fee);
        $this->assertNotNull($so->agency_delivery_cost);
        $this->assertNotNull($so->seller_free_delivery_contribution);
        $this->assertNotNull($so->commission_rate);
    }

    /** Walks a parcel through the workflow to $status (admin per-parcel moves). */
    protected function moveTo(object $so, string ...$steps): void
    {
        foreach ($steps as $step) {
            $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/status", ['status' => $step])->assertOk();
        }
    }

    /** Confirmed, then shipped (out for delivery): the only state a parcel is delivered or refused from. */
    protected function ship(object $so): void
    {
        $status = DB::table('seller_orders')->where('id', $so->id)->value('status');
        $path   = ['pending' => ['confirmed', 'out_for_delivery'], 'confirmed' => ['out_for_delivery'], 'handed_to_courier' => ['out_for_delivery']];
        $this->moveTo($so, ...($path[$status] ?? []));
    }

    protected function deliver(object $so): void
    {
        $this->ship($so);
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/delivered")->assertOk();
    }

    protected function refuse(object $so): \Illuminate\Testing\TestResponse
    {
        $this->ship($so);
        return $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/refused");
    }

    protected function confirmRemittance(object $so): \Illuminate\Testing\TestResponse
    {
        return $this->as($this->admin)->postJson("/api/admin/finance/confirm-money/{$so->id}");
    }

    protected function fresh(object $so): object
    {
        return DB::table('seller_orders')->where('id', $so->id)->first();
    }
}
