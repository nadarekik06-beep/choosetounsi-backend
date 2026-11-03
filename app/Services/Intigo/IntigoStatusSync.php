<?php

namespace App\Services\Intigo;

use App\Models\SellerOrder;
use App\Services\Orders\IllegalTransition;
use App\Services\Orders\ParcelStatus;
use App\Services\Orders\SellerOrderNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies Intigo statuses (webhook, polling, simulation) to our parcels.
 *
 *   record()   stores the event once: intigo_webhook_events is unique on
 *              (nid, new_status, timestamp), so Intigo's retries are no-ops
 *   process()  updates the carrier status shown (only forward: a late event never
 *              overwrites a newer one) and walks our workflow forward to the
 *              mapped status (IntigoStatusMap) through ParcelStatus — the forward
 *              flow or the return branch, never backwards. Each step runs the
 *              existing logic (delivered: cash collected, commission, payout;
 *              refused: return fee; returned: stock back) and is kept in
 *              seller_order_status_history with the raw Intigo code.
 */
class IntigoStatusSync
{
    /** Forward flow, then the return branch. */
    private const FLOW = ['confirmed', 'handed_to_courier', 'out_for_delivery', 'delivered'];

    public function __construct(
        private ParcelStatus  $parcels,
        private IntigoAlerts  $alerts,
    ) {}

    /**
     * Store an event (deduplicated). Returns its id, or null when it was a duplicate.
     *
     * @param 'webhook'|'poll'|'simulate' $source
     */
    public function record(string $nid, int $newStatus, ?int $oldStatus, string $timestamp, ?string $label, string $source, ?string $eventType = null, ?array $payload = null): ?int
    {
        $soId = SellerOrder::where('intigo_nid', $nid)->value('id');
        $inserted = DB::table('intigo_webhook_events')->insertOrIgnore([
            'nid'             => mb_substr($nid, 0, 32),
            'new_status'      => $newStatus,
            'old_status'      => $oldStatus,
            'event_timestamp' => mb_substr($timestamp, 0, 40),
            'event_type'      => $eventType !== null ? mb_substr($eventType, 0, 40) : null,
            'status_label'    => $label !== null ? mb_substr($label, 0, 120) : null,
            'source'          => $source,
            'seller_order_id' => $soId,
            'payload'         => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'created_at'      => now(),
        ]);
        if ($inserted !== 1) {
            return null;
        }
        return (int) DB::table('intigo_webhook_events')->where('nid', $nid)->where('new_status', $newStatus)
            ->where('event_timestamp', mb_substr($timestamp, 0, 40))->value('id');
    }

    /** Process a stored event once. Returns what it did (also kept on the event). */
    public function process(int $eventId): string
    {
        $claimed = DB::table('intigo_webhook_events')->where('id', $eventId)->whereNull('processed_at')
            ->update(['processed_at' => now()]);
        if ($claimed !== 1) {
            return 'already processed';
        }
        $event = DB::table('intigo_webhook_events')->where('id', $eventId)->first();

        try {
            $result = $this->apply($event->nid, (int) $event->new_status, $event->status_label);
        } catch (\Throwable $e) {
            // Released for intigo:sync-statuses to try again
            DB::table('intigo_webhook_events')->where('id', $eventId)->update(['processed_at' => null, 'result' => mb_substr('error: ' . $e->getMessage(), 0, 255)]);
            Log::error("[Intigo] event {$eventId} ({$event->nid} → {$event->new_status}) failed: " . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            throw $e;
        }
        DB::table('intigo_webhook_events')->where('id', $eventId)->update(['result' => mb_substr($result, 0, 255)]);
        return $result;
    }

    /** Apply one carrier status to the parcel holding $nid. */
    public function apply(string $nid, int $code, ?string $label = null): string
    {
        $so = SellerOrder::where('intigo_nid', $nid)->first();
        if (!$so) {
            // An exchange parcel (after-sale complaint)?
            $complaint = \App\Models\Complaint::where('intigo_exchange_nid', $nid)->first();
            if ($complaint) {
                return app(ExchangeStatusSync::class)->apply($complaint, $code, $label);
            }
            Log::warning("[Intigo] status {$code} for unknown parcel {$nid}: ignored");
            return 'ignored: unknown nid';
        }
        DB::table('intigo_webhook_events')->where('nid', $nid)->whereNull('seller_order_id')->update(['seller_order_id' => $so->id]);

        $label = IntigoStatusMap::label($code, $label);

        // A code we have no status for: never dropped silently (event row keeps Intigo's label)
        if (IntigoStatusMap::isUnmapped($code)) {
            Log::warning("[Intigo] unmapped status {$code} \"{$label}\" for parcel {$nid} (seller_order {$so->id}): stored, no status change");
        }

        // Carrier status shown: forward only; delivered / returned are never overwritten
        $current = $so->intigo_status_code;
        $rank    = IntigoStatusMap::rank($code);
        $forward = $rank > 0
            && !in_array($current, [5000, 6900], true)
            && ($current === null || $rank >= IntigoStatusMap::rank($current));
        if ($forward) {
            DB::table('seller_orders')->where('id', $so->id)->update([
                'intigo_status_code'  => $code,
                'intigo_status_label' => $label,
                'intigo_status_at'    => now(),
                'carrier_status_raw'  => mb_substr("{$code} {$label}", 0, 191),
                'carrier_status_at'   => now(),
                'updated_at'          => now(),
            ]);
        }
        if (!$forward) {
            if ($rank === 0) {
                return IntigoStatusMap::isUnmapped($code) ? "unmapped status {$code} ({$label}): logged only" : "recorded only: {$label} (informational)";
            }
            return "recorded only: older than the current status {$current}";
        }

        $target = IntigoStatusMap::target($code);
        if ($target === IntigoStatusMap::CARRIER_CANCELLED) {
            return $this->carrierCancelled($so->fresh(), $code, $label);
        }
        if ($target === null || $target === 'confirmed') {
            return IntigoStatusMap::isUnmapped($code) ? "unmapped status {$code} ({$label}): shown, no move" : "carrier status {$code} ({$label})";
        }

        return $this->walkTo($so->fresh(), $target, $code, $label);
    }

    /**
     * Read one parcel's current status from Intigo (GET /parcels/{nid}, read-only)
     * and apply it like a webhook. Idempotent: the event is stored once per
     * Intigo update, and apply() only ever moves forward (notifications go out
     * only for steps actually taken, so webhook + sync never double-notify).
     * Used by the admin "Sync with Intigo" button and intigo:sync-order.
     *
     * @return array{code:int,label:?string,result:string}
     */
    public function syncParcel(SellerOrder $so, IntigoClient $client): array
    {
        if (!$so->intigo_nid) {
            throw new \RuntimeException('This parcel has not been sent to Intigo.');
        }
        $body   = $client->getParcel($so->intigo_nid, $so->id);
        $parcel = isset($body['parcel']) && is_array($body['parcel']) ? $body['parcel'] : $body;
        if (!isset($parcel['status']) || !is_numeric($parcel['status'])) {
            throw new \RuntimeException('Intigo returned no status for parcel ' . $so->intigo_nid . '.');
        }
        $code  = (int) $parcel['status'];
        $label = isset($parcel['status_label']) ? (string) $parcel['status_label'] : null;
        $stamp = isset($parcel['updated_at']) ? (string) $parcel['updated_at'] : 'poll';

        $eventId = $this->record($so->intigo_nid, $code, $so->intigo_status_code !== null ? (int) $so->intigo_status_code : null, $stamp, $label, 'poll');
        // Already stored (webhook or an earlier sync): apply again, it is a no-op once up to date
        $result = $eventId ? $this->process($eventId) : $this->apply($so->intigo_nid, $code, $label);

        return ['code' => $code, 'label' => $label, 'result' => $result];
    }

    /**
     * The parcel's carrier timeline for sellers and buyers: Intigo's label and
     * when, oldest first. No internal results or payloads.
     *
     * @return array<int, array{code:int,label:string,at:?string}>
     */
    public static function timeline(SellerOrder $so): array
    {
        if (!$so->intigo_nid) {
            return [];
        }
        return DB::table('intigo_webhook_events')
            ->where('seller_order_id', $so->id)->where('nid', $so->intigo_nid)
            ->whereIn('source', ['webhook', 'poll'])
            ->orderBy('created_at')->orderBy('id')
            ->get(['new_status', 'status_label', 'created_at'])
            ->unique('new_status')
            ->map(fn ($e) => [
                'code'  => (int) $e->new_status,
                'label' => IntigoStatusMap::label((int) $e->new_status, $e->status_label),
                'at'    => $e->created_at ? \Illuminate\Support\Carbon::parse($e->created_at)->toIso8601String() : null,
            ])->values()->all();
    }

    /** Steps from our current status to $target, forward flow or return branch only. */
    public static function path(string $from, string $to): array
    {
        $fi = array_search($from, self::FLOW, true);
        if ($fi === false) {
            // Return branch
            return $from === 'refused' && $to === 'returned_to_seller' ? ['returned_to_seller'] : [];
        }
        if (in_array($to, self::FLOW, true)) {
            $ti = array_search($to, self::FLOW, true);
            return $ti > $fi ? array_slice(self::FLOW, $fi + 1, $ti - $fi) : [];
        }
        if ($from === 'delivered') {
            return [];   // after-sale returns stay manual (complaints)
        }
        $toCourier = $from === 'confirmed' ? ['handed_to_courier'] : [];
        return match ($to) {
            'refused'            => [...$toCourier, 'refused'],
            'returned_to_seller' => [...$toCourier, 'refused', 'returned_to_seller'],
            default              => [],
        };
    }

    private function walkTo(SellerOrder $so, string $target, int $code, string $label): string
    {
        $steps = self::path($so->status, $target);
        if (!$steps) {
            if ($so->status === $target) {
                return "already {$target}";
            }
            if (in_array($so->status, ['cancelled', 'pending'], true) || ($so->status === 'delivered' && $target !== 'delivered')) {
                $this->alerts->flag($so, 'Intigo status needs a check', "Intigo says \"{$label}\" ({$code}) but the order is {$so->status} here. Check it in the Intigo dashboard.", 'status_mismatch');
            }
            return "no move: {$so->status} → {$target} is not forward";
        }

        $moved = [];
        foreach ($steps as $step) {
            try {
                $so = $this->parcels->transition($so, $step, [
                    'source'             => 'api',
                    'carrier_status_raw' => "{$code} {$label}",
                    'note'               => "Intigo: {$label}",
                ]);
                $moved[] = $step;
            } catch (IllegalTransition $e) {
                $this->alerts->flag($so, 'Intigo status could not be applied', "Intigo says \"{$label}\" ({$code}): " . $e->getMessage(), 'transition_blocked');
                break;
            }
        }

        $this->notifySeller($so, $moved);
        $this->notifyAdmins($so, $moved, $code, $label);
        return $moved ? 'moved to ' . implode(' → ', $moved) : 'no move';
    }

    private function carrierCancelled(SellerOrder $so, int $code, string $label): string
    {
        DB::table('seller_orders')->where('id', $so->id)->update(['intigo_sync_status' => ParcelSender::CANCELLED, 'updated_at' => now()]);

        // We asked for it (order cancelled here): nothing to do
        if ($so->status === 'cancelled') {
            return 'cancelled at Intigo (order already cancelled)';
        }
        $this->alerts->flag($so, 'Parcel cancelled by Intigo',
            "Intigo cancelled parcel {$so->intigo_nid} ({$label}, {$code}) while the order is {$so->status}. Re-send it (Retry send) or cancel the order.",
            'carrier_cancelled');
        return 'cancelled by Intigo: admin notified';
    }

    /** Seller hears about picked up, out for delivery, delivered and returned (dashboard bell). */
    private function notifySeller(SellerOrder $so, array $moved): void
    {
        foreach (array_intersect($moved, ['handed_to_courier', 'out_for_delivery', 'delivered', 'returned_to_seller']) as $step) {
            app(SellerOrderNotifier::class)->parcelUpdate($so, $step);
        }
    }

    /** Admin bell: picked up, delivered, refused, returned (once per parcel and step). */
    private function notifyAdmins(SellerOrder $so, array $moved, int $code, string $label): void
    {
        $titles = [
            'handed_to_courier'  => 'Parcel picked up by Intigo',
            'delivered'          => 'Parcel delivered',
            'refused'            => 'Parcel refused by the client',
            'returned_to_seller' => 'Parcel returned to the seller',
        ];
        foreach (array_intersect($moved, array_keys($titles)) as $step) {
            $this->alerts->inform($so, 'carrier_' . $step, $titles[$step], "Intigo {$so->intigo_nid}: {$label} ({$code}).");
        }
    }
}
