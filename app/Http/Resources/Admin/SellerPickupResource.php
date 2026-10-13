<?php

namespace App\Http\Resources\Admin;

use App\Support\SellerPickup;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A seller sub-order's pickup point (array from SellerPickup::for()).
 */
class SellerPickupResource extends JsonResource
{
    public function toArray($request): array
    {
        $p = $this->resource;

        return [
            'shop_name'   => $p['shop_name'],
            'contact'     => $p['contact'],
            'phone'       => $p['phone'],
            'address'     => $p['address'],
            'city'        => $p['city'],
            'postal_code' => $p['postal_code'],
            'wilaya'      => $p['wilaya'],
            'zone'        => $p['zone'] ?? null,
            'zone_type'   => $p['zone_type'] ?? null,
            'latitude'    => $p['latitude'] ?? null,
            'longitude'   => $p['longitude'] ?? null,
            'status'      => $p['status'] ?? null,        // seller_pickup_addresses status (null: platform)
            'intigo_ready'=> $p['intigo_ready'] ?? false,
            'notes'       => $p['notes'],
            'formatted'   => SellerPickup::formatAddress($p),
            'is_platform' => $p['is_platform'],
            'complete'    => $p['complete'],
            'missing'     => $p['missing'],
        ];
    }
}
