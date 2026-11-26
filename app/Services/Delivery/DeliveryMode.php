<?php

namespace App\Services\Delivery;

use App\Helpers\PlatformUser;
use App\Support\PricingCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How a seller's delivery fee reaches the buyer (admin-controlled, per seller):
 *
 *   included  the size's client fee is inside every displayed price; delivery is shown as free
 *   separate  displayed prices are the seller's prices; one fee per parcel (the largest size)
 *             is charged on its own line
 *
 * The money of a parcel is the same in both modes (items − coupon + one client
 * fee): only the presentation differs. CHOOSE'Tounsi's own products are always
 * included. Orders freeze the mode on each seller_order, so a switch never
 * touches a past order.
 */
class DeliveryMode
{
    public const INCLUDED = 'included';

    public const SEPARATE = 'separate';

    public const MODES = [self::INCLUDED, self::SEPARATE];

    public const CACHE_KEY = 'delivery_mode.separate_sellers';

    /** @return int[] ids of the sellers in separate mode (cached until a switch) */
    public function separateSellerIds(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('users')
            ->where('delivery_fee_mode', self::SEPARATE)
            ->where('id', '!=', (int) PlatformUser::id())
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /** False for the platform's own products (no seller). */
    public function isSeparate($sellerId): bool
    {
        return $sellerId !== null && in_array((int) $sellerId, $this->separateSellerIds(), true);
    }

    public function of($sellerId): string
    {
        return $this->isSeparate($sellerId) ? self::SEPARATE : self::INCLUDED;
    }

    /**
     * Switches sellers to $mode and logs each real change. Rows that are not
     * sellers, the platform account and sellers already in $mode are left alone.
     *
     * @param  int[] $sellerIds
     * @return int[] the sellers actually switched
     */
    public function set(array $sellerIds, string $mode, ?int $adminId): array
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException("Unknown delivery fee mode \"{$mode}\".");
        }

        $changed = DB::transaction(function () use ($sellerIds, $mode, $adminId) {
            $rows = DB::table('users')
                ->whereIn('id', array_map('intval', $sellerIds))
                ->where('role', 'seller')
                ->where('id', '!=', (int) PlatformUser::id())
                ->where('delivery_fee_mode', '!=', $mode)
                ->lockForUpdate()
                ->get(['id', 'delivery_fee_mode']);
            if ($rows->isEmpty()) {
                return [];
            }
            $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
            DB::table('users')->whereIn('id', $ids)->update(['delivery_fee_mode' => $mode, 'updated_at' => now()]);
            $now = now();
            DB::table('seller_delivery_mode_changes')->insert($rows->map(fn ($r) => [
                'seller_id'  => $r->id,
                'old_mode'   => $r->delivery_fee_mode,
                'new_mode'   => $mode,
                'changed_by' => $adminId,
                'created_at' => $now,
            ])->all());
            return $ids;
        });

        if ($changed) {
            $this->flush();
        }
        return $changed;
    }

    /** @return array<int, object> the seller's switches, newest first */
    public function history(int $sellerId, int $limit = 20): array
    {
        return DB::table('seller_delivery_mode_changes as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.changed_by')
            ->where('c.seller_id', $sellerId)
            ->orderByDesc('c.id')
            ->limit($limit)
            ->get(['c.id', 'c.old_mode', 'c.new_mode', 'c.created_at', 'c.changed_by', 'u.name as changed_by_name'])
            ->all();
    }

    /** Every cache that holds a displayed price. */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget('shop:overview:stats');
        PricingCache::bump();
    }
}
