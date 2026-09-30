<?php

namespace App\Services\Ads;

use App\Models\AdWalletTransaction as Tx;
use Illuminate\Support\Facades\DB;

/**
 * Platform ad revenue from the wallet ledger: click charges net of refunds, split
 * into real money (paid balance) and plan credit (not revenue, reported apart).
 */
class AdRevenue
{
    /**
     * @param string|null $from Africa/Tunis date (Y-m-d), inclusive; null = all time
     * @param string|null $to   inclusive
     * @return array{paid: float, credit: float, total: float}
     */
    public function between(?string $from, ?string $to): array
    {
        $charges = $this->sum(Tx::TYPE_CLICK_CHARGE, 'rollup_date', $from, $to);
        $refunds = $this->sum(Tx::TYPE_REFUND, DB::raw('DATE(created_at)'), $from, $to, onlyCampaigns: true);

        // Charges are negative, refunds positive: revenue = −(charges + refunds).
        $paid   = round(-(($charges->total - $charges->credit) + ($refunds->total - $refunds->credit)), 3);
        $credit = round(-($charges->credit + $refunds->credit), 3);

        return ['paid' => max(0.0, $paid), 'credit' => max(0.0, $credit), 'total' => max(0.0, round($paid + $credit, 3))];
    }

    /** Paid / credit click revenue per Africa/Tunis day. */
    public function daily(string $from, string $to): array
    {
        return DB::table('ad_wallet_transactions')
            ->where('type', Tx::TYPE_CLICK_CHARGE)->whereBetween('rollup_date', [$from, $to])
            ->groupBy('rollup_date')->orderBy('rollup_date')
            ->get([DB::raw('rollup_date AS date'), DB::raw('-SUM(amount - credit_amount) AS paid'), DB::raw('-SUM(credit_amount) AS credit')])
            ->map(fn ($r) => ['date' => (string) $r->date, 'paid' => round((float) $r->paid, 3), 'credit' => round((float) $r->credit, 3)])
            ->all();
    }

    private function sum(string $type, $dateColumn, ?string $from, ?string $to, bool $onlyCampaigns = false): object
    {
        $q = DB::table('ad_wallet_transactions')->where('type', $type)
            ->when($onlyCampaigns, fn ($q) => $q->whereNotNull('sponsorship_id'))
            ->when($from, fn ($q) => $q->where($dateColumn, '>=', $from))
            ->when($to, fn ($q) => $q->where($dateColumn, '<=', $to));

        $row = $q->selectRaw('COALESCE(SUM(amount), 0) AS total, COALESCE(SUM(credit_amount), 0) AS credit')->first();
        return (object) ['total' => (float) $row->total, 'credit' => (float) $row->credit];
    }
}
