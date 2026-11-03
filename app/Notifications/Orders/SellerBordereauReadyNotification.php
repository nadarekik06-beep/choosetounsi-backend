<?php

namespace App\Notifications\Orders;

use App\Models\SellerOrder;
use App\Services\Intigo\BordereauService;
use Illuminate\Support\Facades\DB;

/**
 * The seller's bordereau is ready to print (uploaded by the admin, or Intigo's while
 * INTIGO_BORDEREAU_ENABLED is on); "updated" when the admin replaced it. Links to
 * the order page, never to the PDF. Bell + e-mail. Sent through SellerOrderNotifier::bordereauReady().
 *
 * Saving the bell entry stamps bordereau_seller_notified_at (for that file);
 * a final failure stores bordereau_notify_error, so the admin can resend.
 */
class SellerBordereauReadyNotification extends SellerOrderNotification
{
    public function __construct(SellerOrder $sellerOrder, public bool $updated = false, public ?int $uploadId = null)
    {
        parent::__construct($sellerOrder);
    }

    public function event(): string  { return $this->updated ? 'bordereau_updated' : 'bordereau_ready'; }
    protected function icon(): string   { return 'printer'; }
    protected function action(): string { return 'order'; }

    protected function mailView(): string
    {
        return 'emails.seller-orders.bordereau-ready';
    }

    protected function mailSummary(array $summary): array
    {
        return [
            'reference'     => $summary['reference'],
            'dashboard_url' => $summary['dashboard_url'],
        ];
    }

    /** Not if the order was cancelled, the PDF removed, or (manual) a newer file replaced this one. */
    public function shouldSend($notifiable, string $channel): bool
    {
        $so = $this->sellerOrder->fresh();
        if (!$so || $so->status === 'cancelled' || !BordereauService::availableToSeller($so)) {
            return false;
        }
        return $this->uploadId === null || (int) $so->bordereau_upload_id === $this->uploadId;
    }

    public function toDatabase($notifiable): array
    {
        $data = parent::toDatabase($notifiable);
        $this->current()->update(['bordereau_seller_notified_at' => now(), 'bordereau_notify_error' => null]);
        return $data;
    }

    public function failed(\Throwable $e): void
    {
        parent::failed($e);
        $this->current()->update(['bordereau_notify_error' => mb_substr('Notification failed: ' . $e->getMessage(), 0, 255)]);
    }

    /** This seller_order, only while it still holds the file this notification is about. */
    private function current()
    {
        return DB::table('seller_orders')->where('id', $this->sellerOrder->id)
            ->when($this->uploadId !== null, fn ($q) => $q->where('bordereau_upload_id', $this->uploadId));
    }
}
