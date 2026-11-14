<?php

namespace App\Notifications\Returns;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Admin bell: a parcel left a draft settlement because its client was refunded in
 * cash at pick-up and the item is not back yet (the payout is frozen until the
 * inspection / dispute decision). When that leaves the draft empty, it is deleted.
 */
class SettlementDraftChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $orderNumber,
        private string $batchReference,
        private bool $draftDeleted,
        private ?int $complaintId = null,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $body = __('returns_policy.order_removed_from_draft', ['order' => '#' . ltrim($this->orderNumber, '#')]);
        if ($this->draftDeleted) {
            $body .= ' · ' . __('returns_policy.draft_deleted', ['batch' => $this->batchReference]);
        }

        return [
            'type'     => 'settlement_draft_changed',
            'category' => 'payments',
            'audience' => 'admin',
            'title'    => $body,
            'body'     => $this->batchReference,
            'link'     => '/finance',
            'icon'     => 'alert-circle',
            'action'   => 'pending',
            'data'     => ['order_number' => $this->orderNumber, 'batch' => $this->batchReference, 'draft_deleted' => $this->draftDeleted, 'complaint_id' => $this->complaintId],
        ];
    }
}
