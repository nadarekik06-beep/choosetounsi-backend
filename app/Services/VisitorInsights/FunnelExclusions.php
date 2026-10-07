<?php

namespace App\Services\VisitorInsights;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Who must not count in a seller's funnel: the seller looking at their own
 * products (logged in, or on a browser they once logged in with), platform staff
 * and bots. Live events are flagged when recorded; history is flagged by
 * markHistory() during the backfill (same rules, plus a volume rule for bots,
 * since old rows have no User-Agent).
 */
class FunnelExclusions
{
    public const STAFF_ROLES = ['admin', 'delivery_admin', 'delivery_guy'];

    /** Remember which account a browser belongs to (a guest view later is still the same person). */
    public static function rememberSessionOwner(?int $userId, ?string $sessionId): void
    {
        if ($userId && $sessionId) {
            Cache::put("funnel:sess-owner:$sessionId", $userId, now()->addDays(90));
        }
    }

    public static function isExcluded(?int $userId, ?string $sessionId, ?int $sellerId): bool
    {
        $actor = $userId ?: ($sessionId ? Cache::get("funnel:sess-owner:$sessionId") : null);
        if (!$actor) return false;
        if ($sellerId && (int) $actor === (int) $sellerId) return true;
        return self::isStaff((int) $actor);
    }

    public static function isStaff(int $userId): bool
    {
        return (bool) Cache::remember("funnel:staff:$userId", 3600, fn () =>
            DB::table('users')->where('id', $userId)->whereIn('role', self::STAFF_ROLES)->exists() ? 1 : 0);
    }

    /**
     * Flag historical rows (idempotent). Returns the number of rows newly flagged.
     * Optional $since limits the scan (the nightly run only re-checks recent days).
     */
    public function markHistory(?string $since = null): int
    {
        $flagged = 0;
        $scope = fn ($q) => $since ? $q->where('ui.created_at', '>=', $since) : $q;

        // 1. The seller's own account, and staff accounts
        $flagged += DB::table('user_interactions as ui')
            ->where('ui.funnel_excluded', false)->whereNotNull('ui.user_id')
            ->where(fn ($q) => $q->whereColumn('ui.user_id', 'ui.seller_id')
                ->orWhereIn('ui.user_id', DB::table('users')->select('id')->whereIn('role', self::STAFF_ROLES)))
            ->tap($scope)
            ->update(['ui.funnel_excluded' => true]);

        // 2. Guest rows from a browser the seller (or staff) logged in with
        //    (MariaDB can't UPDATE a table it reads in a subquery: select the ids first)
        $owners = DB::table('user_interactions as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->whereNotNull('o.user_id')->whereNotNull('o.session_id')
            ->selectRaw('o.session_id, o.user_id, MAX(u.role IN (\'' . implode("','", self::STAFF_ROLES) . '\')) as staff')
            ->groupBy('o.session_id', 'o.user_id')->get();
        foreach ($owners->groupBy('session_id') as $sid => $accounts) {
            $q = DB::table('user_interactions as ui')->where('ui.session_id', $sid)->where('ui.funnel_excluded', false)
                ->tap($scope);
            if (!$accounts->contains(fn ($a) => (int) $a->staff === 1)) {
                $q->whereIn('ui.seller_id', $accounts->pluck('user_id')->all());
            }
            $flagged += $q->update(['ui.funnel_excluded' => true]);
        }

        // 3. Bot-like volume: one actor viewing more products in a day than a person would
        $limit = (int) config('funnel.bot_views_per_day');
        $tz = self::offset();
        $bots = DB::table('user_interactions')
            ->where('event_type', 'view')
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->selectRaw("COALESCE(CONCAT('u', user_id), session_id) as actor, DATE(DATE_ADD(created_at, INTERVAL $tz MINUTE)) as d")
            ->groupBy('actor', 'd')
            ->havingRaw('COUNT(*) > ?', [$limit])
            ->get();
        foreach ($bots as $b) {
            $q = DB::table('user_interactions')->where('funnel_excluded', false)
                ->whereRaw("DATE(DATE_ADD(created_at, INTERVAL $tz MINUTE)) = ?", [$b->d]);
            str_starts_with($b->actor, 'u') && ctype_digit(substr($b->actor, 1))
                ? $q->where('user_id', (int) substr($b->actor, 1))
                : $q->where('session_id', $b->actor);
            $flagged += $q->update(['funnel_excluded' => true]);
        }
        return $flagged;
    }

    /** Minutes between UTC and the funnel timezone (DST-safe enough for daily buckets). */
    public static function offset(): int
    {
        return now(config('funnel.timezone'))->utcOffset();
    }
}
