<?php

namespace App\Support;

use App\Helpers\PlatformUser;
use App\Models\SellerApplication;
use App\Models\User;

/**
 * Where the courier collects a seller sub-order.
 *
 * Sellers: their (latest) seller application — business_name, full_name
 * (contact), phone_number, pickup_address, city, pickup_postal_code, wilaya.
 * The platform's own sub-orders: config('delivery.platform_pickup').
 *
 * Pass a seller with `sellerApplication` eager-loaded to avoid a query per
 * sub-order.
 */
class SellerPickup
{
    /** Fields a courier needs to collect a parcel; label used in warnings. */
    const REQUIRED = [
        'shop_name'   => 'shop name',
        'contact'     => 'contact person',
        'phone'       => 'phone',
        'address'     => 'street address',
        'city'        => 'city / delegation',
        'postal_code' => 'postal code',
        'wilaya'      => 'governorate',
    ];

    /**
     * @return array{shop_name:?string, contact:?string, phone:?string, address:?string,
     *               city:?string, postal_code:?string, wilaya:?string, notes:?string,
     *               is_platform:bool, missing:string[], complete:bool}
     */
    public static function for(?User $seller, ?int $sellerId = null): array
    {
        $sellerId ??= $seller?->id;
        $isPlatform = $sellerId === null || $sellerId === PlatformUser::id();

        if ($isPlatform) {
            $data = config('delivery.platform_pickup', []);
        } else {
            $app = $seller?->relationLoaded('sellerApplication')
                ? $seller->sellerApplication
                : SellerApplication::where('user_id', $sellerId)->latest()->first();
            $data = self::fromApplication($app, $seller);
        }

        return self::finish($data, $isPlatform);
    }

    public static function fromApplication(?SellerApplication $app, ?User $seller = null): array
    {
        return [
            'shop_name'   => $app?->business_name ?: $seller?->name,
            'contact'     => $app?->full_name ?: $seller?->name,
            'phone'       => $app?->phone_number,
            'address'     => $app?->pickup_address,
            'city'        => $app?->city,
            'postal_code' => $app?->pickup_postal_code,
            'wilaya'      => Wilayas::normalize($app?->wilaya) ?? $app?->wilaya,
            'notes'       => $app?->pickup_notes,
        ];
    }

    private static function finish(array $data, bool $isPlatform): array
    {
        $data = array_map(fn($v) => is_string($v) && trim($v) !== '' ? trim($v) : null, $data + array_fill_keys(array_keys(self::REQUIRED), null) + ['notes' => null]);

        $missing = [];
        foreach (self::REQUIRED as $key => $label) {
            if ($data[$key] === null) $missing[] = $label;
        }
        if ($data['phone'] !== null && !TunisianPhone::isValid($data['phone'])) {
            $missing[] = 'valid phone';
        }
        if ($data['postal_code'] !== null && !preg_match('/^\d{4}$/', $data['postal_code'])) {
            $missing[] = 'valid postal code';
        }

        return $data + [
            'is_platform' => $isPlatform,
            'missing'     => $missing,
            'complete'    => $missing === [],
        ];
    }

    // ── Editing (seller settings, admin order drawer) ─────────────────────────

    /** Normalize phone and wilaya in place, before validation runs. */
    public static function prepare(\Illuminate\Http\Request $request): void
    {
        $merge = [];
        if ($request->has('phone_number')) {
            $merge['phone_number'] = TunisianPhone::normalize($request->input('phone_number'));
        }
        if ($request->filled('wilaya')) {
            $merge['wilaya'] = Wilayas::normalize($request->input('wilaya')) ?? $request->input('wilaya');
        }
        if (is_string($request->input('pickup_postal_code'))) {
            $merge['pickup_postal_code'] = trim($request->input('pickup_postal_code'));
        }
        $request->merge($merge);
    }

    /** seller_applications columns that make up the pickup point. */
    public static function rules(): array
    {
        return [
            'full_name'          => ['required', 'string', 'min:3', 'max:255'],
            'phone_number'       => ['required', 'string', 'regex:' . TunisianPhone::PATTERN],
            'pickup_address'     => ['required', 'string', 'min:5', 'max:500'],
            'city'               => ['required', 'string', 'min:2', 'max:100'],
            'pickup_postal_code' => ['required', 'digits:4'],
            'wilaya'             => ['required', 'string', \Illuminate\Validation\Rule::in(Wilayas::ALL)],
            'pickup_notes'       => ['nullable', 'string', 'max:500'],
        ];
    }

    /** One line: "Zone industrielle, Ben Arous, 2013 Ben Arous". */
    public static function formatAddress(array $pickup): string
    {
        $city = trim(implode(' ', array_filter([$pickup['postal_code'] ?? null, $pickup['wilaya'] ?? null])));
        return implode(', ', array_filter([$pickup['address'] ?? null, $pickup['city'] ?? null, $city]));
    }
}
