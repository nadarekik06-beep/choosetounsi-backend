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
            'notes'       => $p['notes'],
            'formatted'   => SellerPickup::formatAddress($p),
            'is_platform' => $p['is_platform'],
            'complete'    => $p['complete'],
            'missing'     => $p['missing'],
        ];
    }
}
