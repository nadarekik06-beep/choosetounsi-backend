<?php

namespace App\Notifications\Returns;

use App\Models\Complaint;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Seller bell for a return on one of their sales:
 *   admin_approved       the platform approved it, a pick-up follows
 *   rejected             the platform overrode the shop's acceptance
 *   pickup_scheduled     courier on the way to the client
 *   delivered_to_seller  parcel dropped at the shop: confirm reception + condition
 *   refunded             client refunded: the sale is reversed (earnings / payout)
 *   cancelled            the return was cancelled
 */
class ReturnSellerNotification extends Notification
{
    use Queueable;

    private const ICONS = [
        'admin_approved'      => ['check-circle', 'approved'],
        'rejected'            => ['x-circle', 'rejected'],
        'pickup_scheduled'    => ['truck', 'updated'],
        'delivered_to_seller' => ['package-check', 'pending'],
        'refunded'            => ['package-x', 'refunded'],
        'cancelled'           => ['x-circle', 'rejected'],
    ];

    public function __construct(private Complaint $complaint, private string $event) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $c = $this->complaint->loadMissing('order:id,order_number');
        $params = [
            'return' => (string) $c->reference,
            'order'  => (string) ($c->order?->order_number ?? '#' . $c->order_id),
            'amount' => number_format((float) $c->items_amount, 3, '.', ' '),
        ];
        [$icon, $action] = self::ICONS[$this->event];

        return [
            'type'     => "return_{$this->event}",
            'category' => 'complaints',
            'audience' => 'seller',
            'title'    => __("seller.notif.return.{$this->event}.title", $params),
            'body'     => __("seller.notif.return.{$this->event}.body", $params),
            'link'     => '/seller/complaints?id=' . $c->id,
            'icon'     => $icon,
            'action'   => $action,
            'data'     => ['complaint_id' => $c->id, 'reference' => $c->reference, 'order_id' => $c->order_id, 'status' => $c->status],
        ];
    }
}
