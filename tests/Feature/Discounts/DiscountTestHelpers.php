<?php

namespace Tests\Feature\Discounts;

use App\Models\Coupon;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Intigo\ParcelPayload;
use App\Services\Orders\CheckoutGuard;
use App\Services\PriceHistory;
use App\Models\SellerOrder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Intigo\IntigoTestHelpers;

/**
 * Fixtures for the AUDIT_DISCOUNTS fixes. Fake Intigo everywhere (no network).
 * Admin delivery values: client 8.000, agency 7.000, seller contribution 6.000.
 * Commission (free plan): ≤ 100 DT → 15 %, 100.01-200 DT → 12 % (pinned in setUp).
 */
trait DiscountTestHelpers
{
    use IntigoTestHelpers;

    protected function setUpDiscounts(): void
    {
        $this->assertSame('choosetounsi_test', DB::connection()->getDatabaseName());
        $this->setUpIntigo();
        Notification::fake();
        Storage::fake('public');
        Cache::flush();
        PriceHistory::flush();
        PlatformSetting::setValue('commission.default', [
            'tiers' => [
                ['min' => 0, 'max' => 100, 'rate' => 15],
                ['min' => 100.01, 'max' => 200, 'rate' => 12],
                ['min' => 200.01, 'max' => null, 'rate' => 10],
            ],
            'floor' => 3,
        ]);
        PlatformSetting::flushCache();
        config(['guest_checkout.enabled' => true]);
    }

    /** A live promotion on $products, created straight in the DB (seller by default). */
    protected function promo(array $products, string $type = 'discount', $percent = 20, array $extra = []): Promotion
    {
        $promo = Promotion::create($extra + [
            'seller_id' => $products[0]->seller_id, 'name' => 'Promo ' . Str::random(4), 'type' => $type,
            'discount_type' => 'percentage', 'discount_value' => $percent, 'status' => 'active',
            'priority' => $type === 'flash_sale' ? 10 : 5,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(2),
            'flash_stock' => null, 'flash_stock_used' => 0,
            'per_customer_limit' => $type === 'flash_sale' ? Promotion::DEFAULT_PER_CUSTOMER_LIMIT : null,
            'created_by' => Promotion::SOURCE_SELLER, 'funded_by' => Promotion::FUNDED_SELLER,
        ]);
        $promo->products()->attach(collect($products)->pluck('id')->all());
        return $promo;
    }

    protected function coupon(User $seller, array $products, string $type = 'percentage', $value = 10, array $extra = []): Coupon
    {
        $coupon = Coupon::create($extra + [
            'seller_id' => $seller->id, 'code' => 'DC' . Str::upper(Str::random(7)),
            'discount_type' => $type, 'discount_value' => $value, 'is_active' => true, 'usage_count' => 0,
        ]);
        $coupon->products()->attach(collect($products)->pluck('id')->all());
        return $coupon;
    }

    /** Places the cart order (quote then checkout, as the storefront does), past the identical-order window. */
    protected function place(array $body = []): array
    {
        $this->travel(CheckoutGuard::RECENT_SECONDS + 1)->seconds();
        return $this->checkout($body);
    }

    /** The checkout request without asserting success (quote total sent as expected_total when it prices). */
    protected function tryPlace(array $body = []): \Illuminate\Testing\TestResponse
    {
        $this->travel(CheckoutGuard::RECENT_SECONDS + 1)->seconds();
        $quote = $this->as($this->customer)->postJson('/api/checkout/quote', array_intersect_key($body, array_flip(['coupon_codes'])));
        $expected = $quote->status() === 200 ? ['expected_total' => $quote->json('data.total')] : [];
        return $this->as($this->customer)->postJson('/api/checkout', $this->address() + $body + ['payment_method' => 'cod'] + $expected);
    }

    protected function line(int $orderId, Product $product): object
    {
        return DB::table('order_items')->where('order_id', $orderId)->where('product_id', $product->id)->first();
    }

    /** The amount Intigo would collect for this parcel (fake mode: built locally, never sent). */
    protected function intigoPrice(object $so): float
    {
        return (float) app(ParcelPayload::class)->preview(SellerOrder::findOrFail($so->id))['payload']['price'];
    }

    protected function assertMoney($expected, $actual, string $message = ''): void
    {
        $this->assertSame(self::m($expected), self::m($actual), $message . " (got {$actual}, expected {$expected})");
    }
}
