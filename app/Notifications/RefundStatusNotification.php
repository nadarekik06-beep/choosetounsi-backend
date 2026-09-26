<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use App\Models\SellerOrder;

/**
 * RefundStatusNotification
 *
 * Sent to the seller when:
 *   - action = 'refunded'       → seller marked payment as refunded
 *   - action = 'pickup_done'    → seller marked the refunded order as delivered
 *                                 (meaning the returned product was picked up)
 */
class RefundStatusNotification extends Notification
{
    private string $action;
    private SellerOrder $sellerOrder;
    private string $orderNumber;

    public function __construct(string $action, SellerOrder $sellerOrder, string $orderNumber)
    {
        $this->action      = $action;
        $this->sellerOrder = $sellerOrder;
        $this->orderNumber = $orderNumber;
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $map = [
            'refunded' => [
                'title'  => __('seller.notif.refund.refunded.title'),
                'body'   => __('seller.notif.refund.refunded.body', ['order' => $this->orderNumber]),
                'icon'   => 'package-x',
                'action' => 'refunded',
                'color'  => '#a855f7',
            ],
            'pickup_done' => [
                'title'  => __('seller.notif.refund.pickup_done.title'),
                'body'   => __('seller.notif.refund.pickup_done.body', ['order' => $this->orderNumber]),
                'icon'   => 'package-check',
                'action' => 'updated',
                'color'  => '#10b981',
            ],
        ];

        $entry = $map[$this->action] ?? $map['refunded'];

        return [
            'type'            => 'refund_status',
            'action'          => $entry['action'],
            'title'           => $entry['title'],
            'body'            => $entry['body'],
            'icon'            => $entry['icon'],
            'link'            => '/orders',
            'seller_order_id' => $this->sellerOrder->id,
            'order_number'    => $this->orderNumber,
        ];
    }
}