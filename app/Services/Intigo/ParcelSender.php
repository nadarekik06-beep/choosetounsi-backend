<?php

namespace App\Services\Intigo;

use App\Jobs\Intigo\CancelIntigoParcel;
use App\Models\SellerOrder;
use App\Services\Sellers\PickupAddressService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Creates the Intigo parcel of a seller_order, and never two: a duplicate is a
 * real driver trip and real fees.
 *
 *   1. claim     row locked: skip when a nid exists, when another send holds the
 *                claim (intigo_locked_at, stale after send.lock_seconds), or when
 *                the parcel isn't confirmed any more; the payload is checked
 *                (pickup index, COD amount > 0, phone, address) before any call
 *   2. look up   any earlier attempt may have reached Intigo: GET /parcels/?cid=
 *                first, and adopt the parcel found
 *   3. create    POST /parcels/by-name
 *   4. outcome   2xx → sent. Timeout / 5xx / no answer (ambiguous) → look the cid up
 *                again; not found → 'retry' (the job backs off, max attempts).
 *                400 / 401 / 402 / 404 / 409 → failed with Intigo's message, never retried.
 *
 * Every failure needing a human is flagged to the admins (IntigoAlerts).
 */
class ParcelSender
{
    public const PENDING   = 'pending';
    public const SENT      = 'sent';
    public const FAILED    = 'failed';
    public const CANCELLED = 'cancelled';

    public function __construct(
        private IntigoClient  $client,
        private ParcelPayload $payloads,
        private IntigoAlerts  $alerts,
        private PickupVerifier $pickups,
    ) {}

    /**
     * @param bool $manual an admin's "Retry send": also re-sends a failed parcel
     *                     (automatic sends leave failed ones to the admin)
     * @return array{result:string, message:string, nid:?string}
     *   result: sent | already_sent | busy | skipped | failed | retry
     */
    public function send(int $sellerOrderId, bool $manual = false): array
    {
        $claim = $this->claim($sellerOrderId, $manual);
        if ($claim['result'] !== 'claimed') {
            return $claim;
        }
        ['payload' => $payload, 'attempt' => $attempt, 'previous' => $previous] = $claim;

        try {
            // An earlier attempt may have created it although we never got the answer
            if ($attempt > 1 && ($found = $this->lookup($sellerOrderId, $previous))) {
                return $this->markSent($sellerOrderId, $found['nid'], null, null, 'found by cid before re-sending');
            }

            // Live: the index must exist in Intigo's own list, in the seller's governorate
            if (config('intigo.mode') === 'live') {
                try {
                    $problem = $this->pickups->problem(SellerOrder::findOrFail($sellerOrderId), (int) $payload['pickup_index']);
                } catch (IntigoException $e) {
                    if ($e->retryable) throw $e;
                    $problem = 'could not read Intigo\'s pickup addresses to check the index: ' . $e->getMessage();
                }
                if ($problem !== null) {
                    return $this->fail($sellerOrderId, "Not sent: {$problem}.", false);
                }
            }

            $created = $this->client->createParcel($payload, $sellerOrderId);
            return $this->markSent($sellerOrderId, $created['nid'], $created['district_name'], $created['district_fallback'], 'created');
        } catch (IntigoException $e) {
            if (!$e->retryable) {
                return $this->fail($sellerOrderId, $e->getMessage());
            }
            return $this->ambiguous($sellerOrderId, $previous, $attempt, $e->getMessage());
        } catch (\Throwable $e) {
            // Our own error after the call: treat as ambiguous (the parcel may exist)
            Log::error("[Intigo] send seller_order {$sellerOrderId}: " . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->ambiguous($sellerOrderId, $previous, $attempt, 'Internal error: ' . mb_substr($e->getMessage(), 0, 200));
        }
    }

    /**
     * Admin "Retry send": same no-duplicate rules. A parcel cancelled at Intigo
     * is archived (intigo_previous_nids) so a new one can be created.
     */
    public function retry(int $sellerOrderId): array
    {
        DB::transaction(function () use ($sellerOrderId) {
            $so = SellerOrder::whereKey($sellerOrderId)->lockForUpdate()->firstOrFail();
            if ($so->intigo_nid && ($so->intigo_sync_status === self::CANCELLED || IntigoStatusMap::isCancelled($so->intigo_status_code))) {
                $previous = array_values(array_unique([...($this->previousNids($so)), $so->intigo_nid]));
                $so->forceFill([
                    'intigo_previous_nids' => $previous, 'intigo_nid' => null, 'intigo_sync_status' => null,
                    'intigo_status_code' => null, 'intigo_status_label' => null, 'intigo_status_at' => null,
                    'intigo_sent_at' => null, 'intigo_district_fallback' => null, 'intigo_district_name' => null,
                    'intigo_attention' => null,
                ])->save();
            }
        });

        return $this->send($sellerOrderId, true);
    }

    /** Give up after the job's last attempt. */
    public function giveUp(int $sellerOrderId, string $reason): void
    {
        $so = SellerOrder::find($sellerOrderId);
        if ($so && !$so->intigo_nid && $so->intigo_sync_status === self::PENDING) {
            $this->fail($sellerOrderId, $reason);
        }
    }

    // ── Steps ──────────────────────────────────────────────────────────────

    private function claim(int $id, bool $manual): array
    {
        $outcome = DB::transaction(function () use ($id, $manual) {
            $so = SellerOrder::with(['order', 'seller', 'items'])->whereKey($id)->lockForUpdate()->first();
            if (!$so) {
                return ['result' => 'skipped', 'message' => 'Parcel not found.', 'nid' => null];
            }
            if ($so->intigo_nid) {
                return ['result' => 'already_sent', 'message' => "Already sent to Intigo ({$so->intigo_nid}).", 'nid' => $so->intigo_nid];
            }
            $lock = (int) config('intigo.send.lock_seconds', 120);
            if ($so->intigo_locked_at && now()->diffInSeconds($so->intigo_locked_at) < $lock) {
                return ['result' => 'busy', 'message' => 'A send is already in progress for this parcel.', 'nid' => null];
            }
            if ($so->status !== 'confirmed') {
                // Cancelled (or moved on by hand) before it was sent: nothing to send any more
                if ($so->intigo_sync_status === self::PENDING) {
                    $so->forceFill(['intigo_sync_status' => null, 'intigo_locked_at' => null, 'intigo_error' => "Not sent: the parcel is {$so->status}."])->save();
                }
                return ['result' => 'skipped', 'message' => "Not sent: the parcel is {$so->status}.", 'nid' => null];
            }

            // Automatic sends never override a failure (the admin retries it) nor exceed the attempts
            if (!$manual && $so->intigo_sync_status === self::FAILED) {
                return ['result' => 'skipped', 'message' => 'Failed earlier: waiting for an admin retry.', 'nid' => null];
            }
            if (!$manual && (int) $so->intigo_attempts >= (int) config('intigo.send.max_attempts', 4)) {
                return ['result' => 'invalid', 'message' => "Gave up after {$so->intigo_attempts} attempts.", 'nid' => null];
            }

            $built = $this->payloads->build($so);
            if ($built['problems']) {
                return ['result' => 'invalid', 'message' => 'Not sent: ' . implode('; ', $built['problems']) . '.', 'nid' => null];
            }

            $so->forceFill([
                'intigo_locked_at'   => now(),
                'intigo_attempts'    => (int) $so->intigo_attempts + 1,
                'intigo_sync_status' => self::PENDING,
            ])->save();

            return ['result' => 'claimed', 'payload' => $built['payload'], 'attempt' => (int) $so->intigo_attempts, 'previous' => $this->previousNids($so)];
        });

        if ($outcome['result'] === 'invalid') {
            // Never reached Intigo: no call made, admin told why
            return $this->fail($id, $outcome['message'], false);
        }
        return $outcome;
    }

    /** A parcel Intigo holds under our cid, other than the cancelled ones we archived. */
    private function lookup(int $id, array $previous): ?array
    {
        $parcels = $this->client->findByCid(ParcelPayload::cid($id), $id);
        foreach ($parcels as $p) {
            if (!in_array($p['nid'], $previous, true) && !IntigoStatusMap::isCancelled($p['status'])) {
                return $p;
            }
        }
        return null;
    }

    private function ambiguous(int $id, array $previous, int $attempt, string $message): array
    {
        // Do NOT blindly retry: the parcel may have been created. Look it up first.
        try {
            if ($found = $this->lookup($id, $previous)) {
                return $this->markSent($id, $found['nid'], null, null, 'found by cid after: ' . $message);
            }
        } catch (\Throwable $e) {
            $message .= ' (cid lookup failed too: ' . mb_substr($e->getMessage(), 0, 120) . ')';
        }

        DB::table('seller_orders')->where('id', $id)->whereNull('intigo_nid')->update([
            'intigo_sync_status' => self::PENDING,
            'intigo_error'       => mb_substr("Attempt {$attempt}: {$message}. Retrying automatically.", 0, 2000),
            'intigo_locked_at'   => null,
            'updated_at'         => now(),
        ]);
        return ['result' => 'retry', 'message' => $message, 'nid' => null];
    }

    private function markSent(int $id, string $nid, ?string $districtName, ?bool $fallback, string $how): array
    {
        $so = DB::transaction(function () use ($id, $nid, $districtName, $fallback, $how) {
            $so = SellerOrder::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($so->intigo_nid && $so->intigo_nid !== $nid) {
                // Should never happen (claim + cid lookup); keep the first, shout
                Log::critical("[Intigo] seller_order {$id} already has parcel {$so->intigo_nid}, Intigo returned another one: {$nid}");
                $this->alerts->flag($so, 'Possible duplicate Intigo parcel', "Intigo returned a second parcel {$nid} while {$so->intigo_nid} exists. Cancel the extra one in the Intigo dashboard.", 'duplicate');
                return $so;
            }
            $so->forceFill([
                'intigo_nid'               => $nid,
                'intigo_sync_status'       => self::SENT,
                'intigo_sent_at'           => $so->intigo_sent_at ?? now(),
                'intigo_error'             => null,
                'intigo_locked_at'         => null,
                'intigo_attention'         => null,
                'intigo_district_fallback' => $fallback,
                'intigo_district_name'     => $districtName !== null ? mb_substr($districtName, 0, 120) : null,
                'carrier_tracking_number'  => $nid,
            ])->save();
            Log::info("[Intigo] seller_order {$id} → parcel {$nid} ({$how})");
            return $so;
        });

        if ($fallback) {
            $this->alerts->flag($so, 'Intigo guessed the delegation', "The client's delegation was not recognised; Intigo used \"{$districtName}\". Check the address with the client if needed.", 'district_fallback', false);
        }

        // Cancelled while the parcel was being created: cancel it at Intigo too
        if ($so->fresh()->status === 'cancelled') {
            CancelIntigoParcel::dispatch($id, 'Order cancelled while the parcel was being created');
        }

        return ['result' => self::SENT, 'message' => "Sent to Intigo: {$nid}.", 'nid' => $nid];
    }

    private function fail(int $id, string $message, bool $reachedIntigo = true): array
    {
        DB::table('seller_orders')->where('id', $id)->whereNull('intigo_nid')->update([
            'intigo_sync_status' => self::FAILED,
            'intigo_error'       => mb_substr($message, 0, 2000),
            'intigo_locked_at'   => null,
            'updated_at'         => now(),
        ]);
        $reason = $message;
        if (!$reachedIntigo) {
            $seller = DB::table('seller_orders as so')->leftJoin('users as u', 'u.id', '=', 'so.seller_id')
                ->where('so.id', $id)->first(['so.seller_id', 'u.name']);
            $reason = ($seller?->seller_id ? "Seller #{$seller->seller_id} ({$seller->name}). " : '') . $message
                . ' Nothing was created at Intigo and the order is kept as it is: fix this, then click "Retry send" on the order'
                . (str_contains($message, PickupAddressService::HOLD) ? ' (or it is re-sent automatically once the pickup is created in Intigo).' : '.');
        }
        $this->alerts->flag($id, $reachedIntigo ? 'Intigo rejected a parcel' : 'Parcel not sent to Intigo', $reason, 'send_failed', false);
        return ['result' => self::FAILED, 'message' => $message, 'nid' => null];
    }

    private function previousNids(SellerOrder $so): array
    {
        $raw = $so->getAttribute('intigo_previous_nids');
        $list = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
        return array_values(array_filter($list, 'is_string'));
    }
}
