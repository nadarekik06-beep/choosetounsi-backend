<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Product API Resource
 * Transform product data for API responses
 */
class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
{
    // ── All your existing fields — UNCHANGED ────────────────────────────
    $data = [
        'id'          => $this->id,
        'name'        => $this->name,
        'description' => $this->description,
        'price'       => (float) $this->price,   // ← original price, ALWAYS present
        'stock'       => $this->stock,
        'sku'         => $this->sku,
        'is_approved' => $this->is_approved,
        'is_active'   => $this->is_active,
        'seller'      => [
            'id'   => $this->seller->id,
            'name' => $this->seller->name,
        ],
        'created_at'  => $this->created_at->toISOString(),
        'updated_at'  => $this->updated_at->toISOString(),
    ];

    // ── ADDITIVE: promotion overlay — injected only when service is available ─
    // The promotion data is pre-computed in the controller and passed via
    // $this->additional['promotion_data'] to avoid N+1 queries.
    if (isset($this->additional['promotion_data'])) {
        $pd = $this->additional['promotion_data'];
        $data['effective_price'] = $pd['effective_price'];  // computed, not stored
        $data['discount_amount'] = $pd['discount_amount'];
        $data['promotion']       = $pd['promotion'];        // null if no active promo
    } else {
        $data['effective_price'] = (float) $this->price;
        $data['discount_amount'] = 0.0;
        $data['promotion']       = null;
    }

    return $data;
}
}