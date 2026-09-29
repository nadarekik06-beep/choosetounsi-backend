<?php

namespace App\Http\Resources\Ads;

use App\Models\AdWalletTransaction;
use Illuminate\Http\Resources\Json\JsonResource;

class AdWalletTransactionResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var AdWalletTransaction $t */
        $t = $this->resource;

        return [
            'id'             => $t->id,
            'type'           => $t->type,
            'amount'         => round((float) $t->amount, 3),
            'credit_amount'  => round((float) $t->credit_amount, 3),
            'paid_amount'    => $t->paidAmount(),
            'balance_after'  => round((float) $t->balance_after, 3),
            'credit_after'   => round((float) $t->credit_after, 3),
            'sponsorship_id' => $t->sponsorship_id,
            'date'           => $t->rollup_date?->toDateString(),
            'clicks'         => $t->type === AdWalletTransaction::TYPE_CLICK_CHARGE ? ($t->meta['clicks'] ?? null) : null,
            'note'           => $t->meta['note'] ?? null,
            'created_at'     => $t->created_at?->toIso8601String(),
            'updated_at'     => $t->updated_at?->toIso8601String(),
        ];
    }
}
