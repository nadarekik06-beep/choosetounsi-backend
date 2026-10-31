<?php

namespace App\Support;

use App\Models\SubscriptionPlan;
use App\Services\Sellers\StoreProfileService;
use Illuminate\Support\Facades\DB;

/**
 * Public "store" block for buyer-facing product payloads: product cards show the
 * store (name + logo from the seller's approved application), never the account's
 * personal name, e-mail or phone.
 *
 *   seller: {
 *     id, store_id        seller account id (/sellers/{id})
 *     store_name          business_name of the approved application (null if none)
 *     store_logo          absolute logo URL (profile_picture), null if none
 *     name, business_name store_name, else the account name so a card is never empty
 *     avatar              = store_logo (no fallback to the personal photo: the card shows the store's initials)
 *     plan                pepper tier key, when the seller has an approved application
 *   }
 *
 * Used by the StoreBrandedCards middleware on the storefront card routes. Admin and
 * seller-dashboard routes are not touched and keep the seller's real name.
 */
class StoreCards
{
    /** Personal fields never sent in a public seller block. */
    private const PERSONAL = ['email', 'phone', 'phone_number', 'full_name', 'first_name', 'last_name', 'google_id', 'email_verified_at'];

    /**
     * seller_id => store block. Two queries whatever the number of sellers.
     *
     * @param  int[]  $sellerIds
     * @return array<int, array<string, mixed>>
     */
    public static function forSellers(array $sellerIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $sellerIds))));
        if (!$ids) return [];

        // Latest approved application per seller (ordered by id, keyBy keeps the last one)
        $apps = DB::table('seller_applications')
            ->whereIn('user_id', $ids)
            ->where('status', 'approved')
            ->orderBy('id')
            ->get(['user_id', 'business_name', 'plan', 'profile_picture'])
            ->keyBy('user_id');
        $names = DB::table('users')->whereIn('id', $ids)->pluck('name', 'id');

        $out = [];
        foreach ($ids as $id) {
            $app       = $apps->get($id);
            $storeName = trim((string) ($app->business_name ?? '')) ?: null;
            $logo      = StoreProfileService::logoUrl($app->profile_picture ?? null);
            $display   = $storeName ?? (trim((string) ($names[$id] ?? '')) ?: null);

            $out[$id] = array_filter([
                'id'            => $id,
                'store_id'      => $id,
                'store_name'    => $storeName,
                'store_logo'    => $logo,
                'name'          => $display,
                'business_name' => $display,
                'avatar'        => $logo,
                'plan'          => $app ? SubscriptionPlan::forSlug($app->plan)->tierKey() : null,
            ], fn ($v, $k) => $v !== null || in_array($k, ['store_name', 'store_logo', 'avatar'], true), ARRAY_FILTER_USE_BOTH);
        }

        return $out;
    }

    /**
     * Rewrites every `seller` object (with an id) found anywhere in a decoded JSON
     * payload (objects from json_decode without assoc, so {} stays {}).
     * Other keys of the seller object are kept (wilaya, total_products…), personal ones removed.
     */
    public static function brandPayload(mixed $payload): mixed
    {
        $ids = [];
        self::walk($payload, function (object $seller) use (&$ids) { $ids[] = (int) $seller->id; });
        if (!$ids) return $payload;

        $stores = self::forSellers($ids);
        self::walk($payload, function (object $seller) use ($stores) {
            foreach (self::PERSONAL as $key) unset($seller->{$key});
            foreach ($stores[(int) $seller->id] ?? [] as $key => $value) $seller->{$key} = $value;
        });

        return $payload;
    }

    /** Calls $fn on each `seller` object that has a numeric id (objects are changed in place). */
    private static function walk(mixed $node, callable $fn, int $depth = 0): void
    {
        if ($depth > 12) return;
        if (is_array($node)) {
            foreach ($node as $child) self::walk($child, $fn, $depth + 1);
            return;
        }
        if (!is_object($node)) return;

        foreach (get_object_vars($node) as $key => $child) {
            if ($key === 'seller' && is_object($child) && isset($child->id) && is_numeric($child->id)) {
                $fn($child);
            }
            self::walk($child, $fn, $depth + 1);
        }
    }
}
