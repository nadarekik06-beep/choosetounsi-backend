<?php

namespace App\Services\GrowthRadar;

use Illuminate\Support\Facades\DB;

/**
 * What worked for this seller: measured results of their past actions, by kind
 * (discount, flash sale, coupon, boost…). Used to scale impact estimates and to
 * pick the promotion type their buyers respond to. Nothing changes until a kind
 * has config('growth.learning.min_actions') clear (not "unclear") results.
 */
class Learning
{
    /** @var array<string, array{n: int, wins: int, lift: float, multiplier: float}> */
    public array $byKind = [];

    public static function forSeller(int $sellerId): self
    {
        $l = new self();
        $rows = DB::table('growth_actions')
            ->where('seller_id', $sellerId)->where('status', 'measured')
            ->whereIn('verdict', ['win', 'loss', 'neutral'])
            ->get(['kind', 'verdict', 'result']);

        foreach ($rows->groupBy('kind') as $kind => $group) {
            $lifts = [];
            foreach ($group as $r) {
                $res = json_decode($r->result, true) ?: [];
                if (isset($res['lift_pct'])) $lifts[] = $res['lift_pct'] / 100;
            }
            $lift = $lifts ? array_sum($lifts) / count($lifts) : 0.0;
            [$lo, $hi] = config('growth.learning.multiplier');
            $enough = $group->count() >= (int) config('growth.learning.min_actions');
            $l->byKind[$kind] = [
                'n'          => $group->count(),
                'wins'       => $group->where('verdict', 'win')->count(),
                'lift'       => round($lift, 3),
                'multiplier' => $enough ? max($lo, min($hi, 1 + $lift / 2)) : 1.0,
            ];
        }
        return $l;
    }

    public function multiplier(string $kind): float
    {
        return $this->byKind[$kind]['multiplier'] ?? 1.0;
    }

    /** discount or flash_sale — whichever this seller's buyers answered better (default: discount). */
    public function preferredPromo(): string
    {
        $d = $this->byKind['discount'] ?? null;
        $f = $this->byKind['flash_sale'] ?? null;
        $min = (int) config('growth.learning.min_actions');
        if ($f && $f['n'] >= $min && (!$d || $d['n'] < $min || $f['lift'] > $d['lift'])) return 'flash_sale';
        return 'discount';
    }

    /** Short facts for the "Past actions" tab: best kind, by lift, with enough results. */
    public function summary(): array
    {
        $min = (int) config('growth.learning.min_actions');
        $ranked = array_filter($this->byKind, fn ($k) => $k['n'] >= $min);
        uasort($ranked, fn ($a, $b) => $b['lift'] <=> $a['lift']);
        return [
            'by_kind' => $this->byKind,
            'best'    => array_key_first($ranked),
            'min_actions' => $min,
        ];
    }
}
