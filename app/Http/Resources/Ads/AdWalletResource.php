<?php

namespace App\Http\Resources\Ads;

use App\Models\AdWallet;
use Illuminate\Http\Resources\Json\JsonResource;

/** Ad wallet: paid balance + spendable free credit. */
class AdWalletResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var AdWallet $w */
        $w = $this->resource;

        return [
            'balance'           => round((float) $w->balance, 3),
            'credit_balance'    => round($w->usableCredit(), 3),
            'credit_expires_at' => $w->usableCredit() > 0 ? $w->credit_expires_at?->toIso8601String() : null,
            'available'         => $w->available(),
        ];
    }
}
