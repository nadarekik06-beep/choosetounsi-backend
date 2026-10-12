<?php

namespace App\Services\Intigo;

use App\Models\SellerOrder;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a seller_order's Intigo parcel: only possible during the pickup
 * phase (1000–1008, or no status yet). Past pickup the parcel is with Intigo:
 * flagged to the admin instead (handled in the Intigo dashboard).
 */
class ParcelCanceller
{
    public function __construct(
        private IntigoClient $client,
        private IntigoAlerts $alerts,
    ) {}

    /**
     * @return array{result:string, message:string}  cancelled | already | none | refused | failed | retry
     */
    public function cancel(int $sellerOrderId, string $why): array
    {
        $so = SellerOrder::find($sellerOrderId);
        if (!$so || !$so->intigo_nid) {
            return ['result' => 'none', 'message' => 'No Intigo parcel to cancel.'];
        }
        if ($so->intigo_sync_status === ParcelSender::CANCELLED || IntigoStatusMap::isCancelled($so->intigo_status_code)) {
            return ['result' => 'already', 'message' => "Parcel {$so->intigo_nid} is already cancelled."];
        }
        if (!IntigoStatusMap::isPickupPhase($so->intigo_status_code)) {
            $label = $so->intigo_status_label ?: IntigoStatusMap::label((int) $so->intigo_status_code);
            $this->alerts->flag($so, 'Intigo parcel past pickup', "{$why}, but parcel {$so->intigo_nid} is already with Intigo ({$label}). Handle it in the Intigo dashboard.", 'cancel_past_pickup');
            return ['result' => 'refused', 'message' => "Parcel {$so->intigo_nid} is past pickup ({$label}): it can't be cancelled through the API."];
        }

        try {
            $this->client->cancel($so->intigo_nid, $so->id);
        } catch (IntigoException $e) {
            if ($e->retryable) {
                return ['result' => 'retry', 'message' => $e->getMessage()];
            }
            if ($e->httpStatus === 409) {
                $this->alerts->flag($so, 'Intigo refused the cancellation', "{$why}: Intigo refused to cancel parcel {$so->intigo_nid} (already picked up?). Handle it in the Intigo dashboard.", 'cancel_conflict');
                return ['result' => 'refused', 'message' => $e->getMessage()];
            }
            $this->alerts->flag($so, 'Intigo cancellation failed', "{$why}: " . $e->getMessage(), 'cancel_failed');
            return ['result' => 'failed', 'message' => $e->getMessage()];
        }

        DB::table('seller_orders')->where('id', $so->id)->update([
            'intigo_sync_status'  => ParcelSender::CANCELLED,
            'intigo_status_label' => 'Annulé (demandé par Choose\'Tounsi)',
            'intigo_status_at'    => now(),
            'intigo_attention'    => null,
            'updated_at'          => now(),
        ]);
        return ['result' => 'cancelled', 'message' => "Parcel {$so->intigo_nid} cancelled at Intigo."];
    }
}
