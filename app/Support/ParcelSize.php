<?php

namespace App\Support;

/**
 * Parcel sizes: products carry one, a parcel (seller_order) takes the largest
 * of its items. Sent to Intigo as package_size: S = 1 (small), L = 4 (large),
 * XL = 5 (extra large). Delivery prices are set per size by the admin
 * (App\Services\Delivery\DeliverySettings).
 */
class ParcelSize
{
    public const ALL = ['S', 'L', 'XL'];

    public const DEFAULT = 'S';

    /** Intigo package_size codes. */
    public const PACKAGE_SIZE = ['S' => 1, 'L' => 4, 'XL' => 5];

    private const RANK = ['S' => 1, 'L' => 2, 'XL' => 3];

    public static function normalize(?string $size): string
    {
        $size = strtoupper(trim((string) $size));
        return in_array($size, self::ALL, true) ? $size : self::DEFAULT;
    }

    public static function isValid(?string $size): bool
    {
        return in_array($size, self::ALL, true);
    }

    /** Largest of the sizes given (S when none). */
    public static function largest(iterable $sizes): string
    {
        $best = self::DEFAULT;
        foreach ($sizes as $size) {
            $size = self::normalize($size);
            if (self::RANK[$size] > self::RANK[$best]) {
                $best = $size;
            }
        }
        return $best;
    }

    public static function packageSize(?string $size): int
    {
        return self::PACKAGE_SIZE[self::normalize($size)];
    }
}
