<?php

namespace App\Http\Resources\Ads;

use App\Models\AdTopUp;
use Illuminate\Http\Resources\Json\JsonResource;

class AdTopUpResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var AdTopUp $t */
        $t = $this->resource;

        return [
            'id'         => $t->id,
            'amount'     => round((float) $t->amount, 3),
            'gateway'    => $t->gateway,
            'status'     => $t->status,
            'reference'  => $t->reference,
            'paid_at'    => $t->paid_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
            'seller'     => $this->whenLoaded('seller', fn () => ['id' => $t->seller->id, 'name' => $t->seller->name, 'email' => $t->seller->email]),
        ];
    }
}
