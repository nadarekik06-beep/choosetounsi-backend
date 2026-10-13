<?php

namespace App\Support;

use App\Helpers\PlatformUser;
use App\Models\SellerApplication;
use App\Models\SellerPickupAddress;
use App\Models\User;

/**
 * Where the courier collects a seller sub-order.
 *
 * Sellers: seller_pickup_addresses (1:1 with Intigo's "Nouvelle adresse":
 * adresse complète, zone, map pin) + contact, phone, postal code, indications;
 * the shop name comes from the seller application. Edited only through
 * App\Services\Sellers\PickupAddressService.
 * The platform's own sub-orders: config('delivery.platform_pickup').
 *
 * Pass a seller with `pickupAddress.zone` (and `sellerApplication`) eager-loaded
 * to avoid queries per sub-order.
 */
class SellerPickup
{
    /** Fields a courier needs to collect a parcel; label used in warnings. */
    const REQUIRED = [
        'shop_name'   => 'shop name',
        'contact'     => 'contact person',
        'phone'       => 'phone',
        'address'     => 'street address',
        'zone'        => 'Intigo zone',
        'postal_code' => 'postal code',
    ];

    /**
     * @return array{shop_name:?string, contact:?string, phone:?string, address:?string,
     *               city:?string, postal_code:?string, wilaya:?string, notes:?string,
     *               zone:?string, zone_type:?string, latitude:?float, longitude:?float,
     *               status:?string, intigo_ready:bool,
     *               is_platform:bool, missing:string[], complete:bool}
     */
    public static function for(?User $seller, ?int $sellerId = null): array
    {
        $sellerId ??= $seller?->id;
        $isPlatform = $sellerId === null || $sellerId === PlatformUser::id();

        if ($isPlatform) {
            $data = config('delivery.platform_pickup', []) + ['gps_optional' => true];
        } else {
            $record = $seller?->relationLoaded('pickupAddress')
                ? $seller->pickupAddress
                : SellerPickupAddress::with('zone')->where('user_id', $sellerId)->first();
            $app = $seller?->relationLoaded('sellerApplication')
                ? $seller->sellerApplication
                : SellerApplication::where('user_id', $sellerId)->latest()->first(['id', 'user_id', 'business_name']);
            $data = self::fromRecord($record, $app, $seller);
        }

        return self::finish($data, $isPlatform);
    }

    public static function fromRecord(?SellerPickupAddress $r, ?SellerApplication $app, ?User $seller = null): array
    {
        $zone = $r?->zone;
        return [
            'shop_name'    => $app?->business_name ?: $seller?->name,
            'contact'      => $r?->contact_name,
            'phone'        => $r?->phone,
            'address'      => $r?->adresse_complete,
            'zone'         => $zone?->display_label,
            'zone_type'    => $zone?->type,
            'city'         => $zone?->delegation,
            'postal_code'  => $r?->code_postal,
            'wilaya'       => $zone ? ($zone->wilaya ?: $zone->gouvernorat) : null,
            'notes'        => $r?->indications,
            'latitude'     => $r?->latitude,
            'longitude'    => $r?->longitude,
            'status'       => $r?->status(),
            'intigo_ready' => (bool) $r?->isIntigoReady(),
        ];
    }

    /** Verified by the admin and created at Intigo: parcels may be sent. */
    public static function intigoReady(int $sellerId): bool
    {
        $r = SellerPickupAddress::where('user_id', $sellerId)->first();
        return $r !== null && $r->isIntigoReady();
    }

    private static function finish(array $data, bool $isPlatform): array
    {
        $coords = ['latitude' => $data['latitude'] ?? null, 'longitude' => $data['longitude'] ?? null];
        $flags  = ['status' => $data['status'] ?? null, 'intigo_ready' => (bool) ($data['intigo_ready'] ?? $isPlatform)];
        $keys   = ['shop_name', 'contact', 'phone', 'address', 'zone', 'zone_type', 'city', 'postal_code', 'wilaya', 'notes'];
        $text   = [];
        foreach ($keys as $key) {
            $v = $data[$key] ?? null;
            $text[$key] = is_string($v) && trim($v) !== '' ? trim($v) : null;
        }
        if ($isPlatform) {
            // The platform's .env address has no Intigo zone: its city / governorate stand in
            $text['zone'] ??= trim(implode(', ', array_filter([$text['city'], $text['wilaya']]))) ?: null;
        }

        $missing = [];
        foreach (self::REQUIRED as $key => $label) {
            if ($text[$key] === null) $missing[] = $label;
        }
        if (!$isPlatform && ($coords['latitude'] === null || $coords['longitude'] === null)) {
            $missing[] = 'map pin';
        }
        if ($text['phone'] !== null && !TunisianPhone::isValid($text['phone'])) {
            $missing[] = 'valid phone';
        }
        if ($text['postal_code'] !== null && !preg_match('/^\d{4}$/', $text['postal_code'])) {
            $missing[] = 'valid postal code';
        }

        return $text + [
            'latitude'    => $coords['latitude'] !== null ? (float) $coords['latitude'] : null,
            'longitude'   => $coords['longitude'] !== null ? (float) $coords['longitude'] : null,
        ] + $flags + [
            'is_platform' => $isPlatform,
            'missing'     => $missing,
            'complete'    => $missing === [],
        ];
    }

    /** One line: "12 Rue des Fleurs, Bab Bhar (Tunis), 1000". */
    public static function formatAddress(array $pickup): string
    {
        if (!empty($pickup['zone'])) {
            return implode(', ', array_filter([$pickup['address'] ?? null, $pickup['zone'], $pickup['postal_code'] ?? null]));
        }
        $city = trim(implode(' ', array_filter([$pickup['postal_code'] ?? null, $pickup['wilaya'] ?? null])));
        return implode(', ', array_filter([$pickup['address'] ?? null, $pickup['city'] ?? null, $city]));
    }
}
