<?php

namespace App\Services\GrowthRadar;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Records that a seller acted on a Growth Radar card, through the existing
 * flows: the promotion / coupon / boost endpoints accept an optional
 * `growth_card_id` and call record() once the thing is created. Link actions
 * (edit the listing, list a product, build a bundle) are recorded from the
 * dashboard (POST /seller/growth-radar/cards/{id}/applied).
 *
 * growth:measure later compares the window with the same number of days just
 * before it (see ResultMeasurer).
 */
class GrowthActions
{
    public const KINDS = ['discount', 'flash_sale', 'coupon', 'boost', 'edit', 'listing', 'bundle'];

    /** The seller's card with this id, if it can still be acted on. */
    public function card(int $sellerId, ?int $cardId): ?object
    {
        if (!$cardId) return null;
        return DB::table('growth_cards')->where('id', $cardId)->where('seller_id', $sellerId)
            ->whereIn('status', ['new', 'snoozed', 'applied'])->first();
    }

    public function cardFromRequest(Request $request): ?object
    {
        $id = $request->input('growth_card_id');
        return is_numeric($id) ? $this->card((int) $request->user()->id, (int) $id) : null;
    }

    /** @return int|null the growth_actions id, or null when the card is not the seller's */
    public function record(int $sellerId, ?object $card, string $kind, ?int $refId, ?int $productId,
                           CarbonInterface $startsAt, CarbonInterface $endsAt): ?int
    {
        if (!$card || !in_array($kind, self::KINDS, true)) return null;

        $id = DB::table('growth_actions')->insertGetId([
            'seller_id'  => $sellerId,
            'card_id'    => $card->id,
            'card_type'  => $card->type,
            'product_id' => $productId ?? $card->product_id,
            'kind'       => $kind,
            'ref_id'     => $refId,
            'starts_at'  => $startsAt->copy()->utc(),
            'ends_at'    => $endsAt->copy()->utc(),
            'status'     => 'running',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('growth_cards')->where('id', $card->id)
            ->update(['status' => 'applied', 'applied_at' => now(), 'updated_at' => now()]);
        return $id;
    }
}
