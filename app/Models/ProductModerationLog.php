<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductModerationLog extends Model
{
    protected $fillable = [
        'product_id', 'admin_id', 'action', 'from_status', 'to_status', 'reasons', 'note',
    ];

    protected $casts = [
        'reasons' => 'array',
    ];

    /**
     * Predefined moderation reasons (code => label).
     * Shared by reject and request-changes; served to the admin panel so the
     * list is defined in exactly one place.
     */
    public const REASONS = [
        'poor_image_quality'   => 'Poor image quality',
        'insufficient_images'  => 'Not enough images',
        'wrong_category'       => 'Wrong category',
        'prohibited_item'      => 'Prohibited item',
        'misleading_description' => 'Misleading description',
        'incorrect_price'      => 'Incorrect price',
        'missing_variant_info' => 'Missing variant info',
        'counterfeit'          => 'Suspected counterfeit / brand infringement',
        'duplicate_listing'    => 'Duplicate listing',
        'other'                => 'Other',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /** Human-readable labels for the stored reason codes. */
    public function getReasonLabelsAttribute(): array
    {
        return self::labelsFor($this->reasons ?? []);
    }

    public static function labelsFor(array $codes): array
    {
        return array_values(array_map(fn($c) => self::REASONS[$c] ?? $c, $codes));
    }

    /**
     * Records a moderation event. Never throws — history must not block moderation.
     */
    public static function record(Product $product, string $action, array $attrs = []): ?self
    {
        try {
            return self::create(array_merge([
                'product_id' => $product->id,
                'action'     => $action,
            ], $attrs));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[ProductModerationLog] ' . $e->getMessage());
            return null;
        }
    }
}
