<?php

namespace App\Services\Intigo;

use App\Models\Complaint;
use App\Models\SellerOrder;
use App\Models\User;
use App\Notifications\Returns\ReturnAdminNotification;
use App\Services\Delivery\DeliverySettings;
use App\Services\Returns\ReturnService;
use App\Support\ParcelSize;
use App\Support\SellerPickup;
use App\Support\TestAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Books the Intigo exchange parcel of an approved exchange complaint
 * (POST /parcels/exchange): the courier picks the replacement up at the
 * seller's pickup address, delivers it to the client (details copied from the
 * original parcel), collects exchange_price in cash and takes the old item back.
 *
 *   exchange_price  the client's fee (client's choice) or 0 (seller at fault)
 *   old_parcel_nid  the original parcel: must exist at Intigo, delivered (5000)
 *
 * Outcomes (complaints.exchange_sync_status):
 *   sent         nid saved, complaint → exchange_in_transit
 *   manual       can't go through the API (no Intigo parcel for the original
 *                order, no pickup index, TEST account…): book it by hand in the
 *                Intigo dashboard and paste the nid (reason in exchange_sync_error)
 *   needs_check  no answer / ambiguous: the parcel may exist and Intigo has no
 *                cid to find it again — the admin checks Intigo and pastes the
 *                nid. NEVER retried automatically (a retry could book it twice).
 *   failed       Intigo refused it (reason saved): fix and book again, or by hand
 *
 * Only one booking at a time per complaint: the row is claimed ('sending')
 * with a conditional update before calling Intigo.
 */
class ExchangeSender
{
    public function __construct(private IntigoClient $client, private DeliverySettings $settings) {}

    /** The original parcel (sub-order) of the complaint's lines. */
    public function originalParcel(Complaint $c): ?SellerOrder
    {
        $id = app(ReturnService::class)->sellerOrderId($c);
        return $id ? SellerOrder::with(['order', 'seller'])->find($id) : null;
    }

    /**
     * The body as it would be sent and every reason it can't be (admin view).
     *
     * @return array{payload: ?array, problems: string[], original_nid: ?string}
     */
    public function preview(Complaint $c): array
    {
        $problems = [];
        $so = $this->originalParcel($c);
        $live = config('intigo.mode') === 'live';

        if (!$so || !$so->intigo_nid) {
            return ['payload' => null, 'original_nid' => null,
                'problems' => ['the original order was not shipped through Intigo: book the exchange by hand in the Intigo dashboard']];
        }
        $code = $so->intigo_status_code !== null ? (int) $so->intigo_status_code : null;
        if ($code !== null && $code !== 5000) {
            $problems[] = "the original parcel {$so->intigo_nid} is not delivered at Intigo (status {$code}): Intigo only exchanges delivered parcels";
        }
        if ($live) {
            if (TestAccounts::isTestUser((int) $c->user_id)) $problems[] = 'TEST buyer account: never sent to Intigo in live mode';
            if (!$so->isPlatformParcel() && TestAccounts::isTestUser((int) $so->seller_id)) $problems[] = 'TEST seller account: never sent to Intigo in live mode';
        }
        $pickup = ParcelPayload::pickupIndex($so);
        if ($pickup === null) {
            $problems[] = 'the seller has no Intigo pickup address';
        } elseif (!$so->isPlatformParcel() && !SellerPickup::intigoReady($so->seller_id)) {
            $problems[] = "the seller's pickup address is not verified in Intigo";
        }

        $payload = [
            'old_parcel_nid' => (string) $so->intigo_nid,
            'exchange_price' => round($c->clientFee(), 3),
            'package_size'   => ParcelSize::packageSize($c->fee_size),
            'description'    => mb_substr($this->description($c), 0, 500),
            'note'           => mb_substr("Échange {$c->reference} — reprendre l'ancien article", 0, 500),
            'can_open'       => $this->settings->canOpen(),
            'pickup_index'   => $pickup,
        ];

        return ['payload' => $payload, 'problems' => $problems, 'original_nid' => (string) $so->intigo_nid];
    }

    /**
     * Book it (approval, admin retry). Returns what happened, for the admin.
     *
     * @return array{result: string, message: string}
     */
    public function book(Complaint $c, ?User $admin): array
    {
        $claimable = fn ($q) => $q->where(fn ($w) => $w
            ->where(fn ($a) => $a->where('status', Complaint::STATUS_ADMIN_APPROVED)
                ->where(fn ($s) => $s->whereNull('exchange_sync_status')->orWhereIn('exchange_sync_status', [Complaint::EXCHANGE_MANUAL, Complaint::EXCHANGE_SEND_FAILED])))
            ->orWhere(fn ($b) => $b->where('status', Complaint::STATUS_EXCHANGE_FAILED)
                ->where(fn ($s) => $s->whereNull('exchange_sync_status')->orWhereNotIn('exchange_sync_status', [Complaint::EXCHANGE_SENDING, Complaint::EXCHANGE_NEEDS_CHECK]))));

        $claimed = DB::table('complaints')->where('id', $c->id)->where('resolution_type', Complaint::RESOLUTION_EXCHANGE)
            ->where($claimable)
            ->update(['exchange_sync_status' => Complaint::EXCHANGE_SENDING, 'updated_at' => now()]);
        if (!$claimed) {
            $fresh = $c->fresh();
            return ['result' => (string) $fresh->exchange_sync_status, 'message' => 'Nothing to book: the exchange is ' . $fresh->status . ' (' . ($fresh->exchange_sync_status ?? 'not booked') . ').'];
        }

        $c = $c->fresh();
        $preview = $this->preview($c);
        $returns = app(ReturnService::class);

        if ($preview['problems'] || !$preview['payload']) {
            $reason = implode('; ', $preview['problems']);
            $this->state($c, Complaint::EXCHANGE_MANUAL, $reason);
            $returns->event($c, 'exchange_booking', $admin, 'admin', null, ['result' => 'manual', 'reason' => $reason]);
            return ['result' => Complaint::EXCHANGE_MANUAL, 'message' => 'Book the exchange by hand in the Intigo dashboard, then paste its tracking number: ' . $reason . '.'];
        }

        $soId = $returns->sellerOrderId($c);
        try {
            $answer = $this->client->createExchange($preview['payload'], $soId);
        } catch (IntigoException $e) {
            if ($e->retryable) {
                return $this->needsCheck($c, $admin, $e->getMessage());
            }
            $this->state($c, Complaint::EXCHANGE_SEND_FAILED, $e->getMessage());
            $returns->event($c, 'exchange_booking', $admin, 'admin', null, ['result' => 'failed', 'error' => $e->getMessage()]);
            $this->alert($c, 'exchange_send_failed');
            return ['result' => Complaint::EXCHANGE_SEND_FAILED, 'message' => 'Intigo refused the exchange: ' . $e->getMessage() . '. Fix it and book again, or book it by hand and paste the tracking number.'];
        } catch (\Throwable $e) {
            Log::error("[Intigo] exchange {$c->reference}: " . $e->getMessage());
            return $this->needsCheck($c, $admin, $e->getMessage());
        }

        try {
            $returns->exchangeShipped($c->fresh(), $admin, $admin ? 'admin' : 'system', $answer['nid'], 'api');
        } catch (\Throwable $e) {
            // Created at Intigo but not saved here: keep the nid where the admin sees it
            $this->state($c, Complaint::EXCHANGE_NEEDS_CHECK, "Created at Intigo as {$answer['nid']} but not saved: " . $e->getMessage());
            $this->alert($c, 'exchange_needs_check');
            return ['result' => Complaint::EXCHANGE_NEEDS_CHECK, 'message' => "Intigo created parcel {$answer['nid']} but it could not be saved here: paste it in the complaint."];
        }
        return ['result' => Complaint::EXCHANGE_SENT, 'message' => "Exchange parcel {$answer['nid']} booked at Intigo."];
    }

    private function needsCheck(Complaint $c, ?User $admin, string $error): array
    {
        $this->state($c, Complaint::EXCHANGE_NEEDS_CHECK, $error);
        app(ReturnService::class)->event($c, 'exchange_booking', $admin, 'admin', null, ['result' => 'needs_check', 'error' => $error]);
        $this->alert($c, 'exchange_needs_check');
        return ['result' => Complaint::EXCHANGE_NEEDS_CHECK, 'message' => 'No clear answer from Intigo (' . $error . '). The parcel may exist: check in Intigo and paste its tracking number. It will not be re-sent automatically.'];
    }

    private function state(Complaint $c, string $status, ?string $error): void
    {
        DB::table('complaints')->where('id', $c->id)->update([
            'exchange_sync_status' => $status,
            'exchange_sync_error'  => $error !== null ? mb_substr($error, 0, 500) : null,
            'updated_at'           => now(),
        ]);
    }

    private function alert(Complaint $c, string $event): void
    {
        try {
            Notification::send(User::where('role', 'admin')->where('is_active', true)->get(), new ReturnAdminNotification($c->fresh(), $event));
        } catch (\Throwable $e) {
            Log::error("[Intigo] exchange alert {$event} failed: " . $e->getMessage());
        }
    }

    /** "Échange RET-000123 : 1 × Robe (M) → Robe — L". */
    private function description(Complaint $c): string
    {
        $parts = [];
        $rows = DB::table('complaint_items as ci')->join('order_items as oi', 'oi.id', '=', 'ci.order_item_id')
            ->where('ci.complaint_id', $c->id)->get(['ci.quantity', 'ci.replacement_label', 'oi.product_name', 'oi.variant_label']);
        foreach ($rows as $r) {
            $parts[] = "{$r->quantity} × {$r->product_name}"
                . ($r->variant_label ? " ({$r->variant_label})" : '')
                . ($r->replacement_label ? " → {$r->replacement_label}" : '');
        }
        return "Échange {$c->reference} : " . ($parts ? implode(', ', $parts) : 'article');
    }
}
