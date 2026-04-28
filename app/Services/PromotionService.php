<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Promotion;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class PromotionService
{
    /**
     * Returns the effective price data for a product.
     * NEVER modifies the product record.
     *
     * @return array{
     *   original_price: float,
     *   effective_price: float,
     *   discount_amount: float,
     *   promotion: array|null
     * }
     */
    public function getEffectivePrice(Product $product, ?float $basePrice = null): array
    {
        $originalPrice = $basePrice ?? (float) $product->price;
        $promotion     = $this->getActivePromotionForProduct($product->id);

        if (!$promotion) {
            return [
                'original_price'  => $originalPrice,
                'effective_price' => $originalPrice,
                'discount_amount' => 0.0,
                'promotion'       => null,
            ];
        }

        $discounted = $this->applyDiscount($originalPrice, $promotion);
        $discounted = max(0, $discounted);

        return [
            'original_price'  => $originalPrice,
            'effective_price' => round($discounted, 3),
            'discount_amount' => round($originalPrice - $discounted, 3),
            'promotion'       => $this->formatPromotion($promotion),
        ];
    }

    /**
     * Get the highest-priority active promotion for a product.
     * Flash sales always outrank discounts (type priority).
     * Within same type, higher `priority` column wins.
     * Tie-break: most recently created.
     */
    public function getActivePromotionForProduct(int $productId): ?Promotion
    {
        return Cache::remember("promo_product_{$productId}", 60, function () use ($productId) {
            return Promotion::active()
                ->whereHas('products', fn($q) => $q->where('products.id', $productId))
                ->orderByRaw("CASE WHEN type = 'flash_sale' THEN 0 ELSE 1 END")
                ->orderByDesc('priority')
                ->orderByDesc('created_at')
                ->first();
        });
    }

    /**
     * Compute discounted price.
     */
    private function applyDiscount(float $price, Promotion $promo): float
    {
        if ($promo->discount_type === 'percentage') {
            return $price * (1 - ((float) $promo->discount_value / 100));
        }
        return max(0, $price - (float) $promo->discount_value);
    }

    /**
     * Format promotion for API response — frontend-ready.
     */
    public function formatPromotion(Promotion $promo): array
    {
        $endsAt = $promo->ends_at instanceof Carbon
            ? $promo->ends_at
            : Carbon::parse($promo->ends_at);

        return [
            'id'                    => $promo->id,
            'type'                  => $promo->type,
            'name'                  => $promo->name,
            'discount_type'         => $promo->discount_type,
            'discount_value'        => (float) $promo->discount_value,
            'discount_label'        => $promo->discount_type === 'percentage'
                                          ? (int) $promo->discount_value . '% OFF'
                                          : number_format($promo->discount_value, 3) . ' DT OFF',
            'ends_at'               => $endsAt->toISOString(),
            'flash_stock_remaining' => $promo->flashStockRemaining(),
            'is_flash_sale'         => $promo->type === 'flash_sale',
        ];
    }

    /**
     * Bust cache after promotion create/update/delete.
     */
    public function bustCacheForProducts(array $productIds): void
    {
        foreach ($productIds as $id) {
            Cache::forget("promo_product_{$id}");
        }
    }

    /**
     * Validate a promotion payload against business rules.
     * Returns array of error messages (empty = valid).
     */
    public function validate(array $data, string $type): array
    {
        $errors = [];
        $starts = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : null;
        $ends   = isset($data['ends_at'])   ? Carbon::parse($data['ends_at'])   : null;

        if ($starts && $ends) {
            $durationHours = $starts->diffInHours($ends);

            if ($type === 'flash_sale') {
                if ($durationHours < 1)  $errors[] = 'Flash sale must last at least 1 hour.';
                if ($durationHours > 72) $errors[] = 'Flash sale cannot exceed 72 hours.';
            } else {
                $durationDays = $starts->diffInDays($ends);
                if ($durationDays < 1)  $errors[] = 'Discount must last at least 1 day.';
                if ($durationDays > 90) $errors[] = 'Discount cannot exceed 90 days.';
            }
        }

        if (isset($data['discount_type'], $data['discount_value'])) {
            $maxDiscount = $type === 'flash_sale' ? 90 : 70;
            if ($data['discount_type'] === 'percentage' && $data['discount_value'] > $maxDiscount) {
                $errors[] = "Max discount for {$type} is {$maxDiscount}%.";
            }
            if ($data['discount_value'] <= 0) {
                $errors[] = 'Discount value must be greater than 0.';
            }
        }

        return $errors;
    }
}