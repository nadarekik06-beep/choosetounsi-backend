<?php

namespace App\Services\Intigo;

use App\Models\Complaint;
use App\Models\User;
use App\Notifications\Returns\ReturnAdminNotification;
use App\Services\Returns\ReturnService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Intigo statuses of an exchange parcel → the complaint (webhook or polling,
 * routed here by IntigoStatusSync::apply when the nid is a complaint's).
 *
 *   1000–4000                    pickup / transit      carrier status shown, no move
 *   5000                         delivered             → exchanged (cash collected, old item taken back)
 *   3201, 6000, 6001, 6900       return before 5000    → exchange_failed (refused at the door)
 *   1100–1102, 9000–9004         cancelled by Intigo   admin alerted (book again or by hand)
 *   6500 "Retour échange", 99    informational         after delivery: the old item on its way back
 *
 * The carrier status only moves forward (IntigoStatusMap::rank); the old item's
 * reception is confirmed by the seller, not by Intigo.
 */
class ExchangeStatusSync
{
    private const FAILED = [3201, 6000, 6001, 6900];

    public function apply(Complaint $complaint, int $code, ?string $label = null): string
    {
        $label   = IntigoStatusMap::label($code, $label);
        $current = $complaint->intigo_exchange_status_code;
        $rank    = IntigoStatusMap::rank($code);

        if ($rank === 0) {
            return "exchange {$complaint->reference}: recorded only: {$label} (informational)";
        }
        if ($current !== null && (in_array($current, [5000, 6900], true) || $rank < IntigoStatusMap::rank($current))) {
            return "exchange {$complaint->reference}: recorded only: older than {$current}";
        }

        DB::table('complaints')->where('id', $complaint->id)->update([
            'intigo_exchange_status_code'  => $code,
            'intigo_exchange_status_label' => $label,
            'intigo_exchange_status_at'    => now(),
            'updated_at'                   => now(),
        ]);
        $complaint = $complaint->fresh();

        if ($complaint->status !== Complaint::STATUS_EXCHANGE_IN_TRANSIT) {
            return "exchange {$complaint->reference}: carrier status {$code} ({$label}), complaint is {$complaint->status}";
        }

        $returns = app(ReturnService::class);
        if ($code === 5000) {
            $returns->markExchanged($complaint, null, 'system', "Intigo: {$label}");
            return "exchange {$complaint->reference}: exchanged";
        }
        if (in_array($code, self::FAILED, true)) {
            $returns->markExchangeFailed($complaint, null, 'system', "Intigo: {$label}");
            return "exchange {$complaint->reference}: failed";
        }
        if (IntigoStatusMap::isCancelled($code)) {
            DB::table('complaints')->where('id', $complaint->id)->update([
                'exchange_sync_status' => Complaint::EXCHANGE_CANCELLED,
                'exchange_sync_error'  => "Cancelled by Intigo: {$label} ({$code})",
            ]);
            $returns->event($complaint, 'exchange_carrier_cancelled', null, 'system', "Intigo: {$label}", ['code' => $code]);
            try {
                Notification::send(User::where('role', 'admin')->where('is_active', true)->get(),
                    new ReturnAdminNotification($complaint->fresh(), 'exchange_carrier_cancelled'));
            } catch (\Throwable $e) {
                Log::error('[Intigo] exchange cancel alert failed: ' . $e->getMessage());
            }
            return "exchange {$complaint->reference}: cancelled by Intigo, admin alerted";
        }
        return "exchange {$complaint->reference}: carrier status {$code} ({$label})";
    }
}
