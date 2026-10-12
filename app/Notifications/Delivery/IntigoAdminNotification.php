<?php

namespace App\Notifications\Delivery;

use Illuminate\Notifications\Notification;

/**
 * Admin bell: an Intigo parcel needs attention (send failed, cancelled by the
 * carrier, cancel refused…). Database only: no e-mail storm on the admin box.
 */
class IntigoAdminNotification extends Notification
{
    public function __construct(
        private string $title,
        private string $body,
        private ?int $orderId = null,
        private ?int $sellerOrderId = null,
        private string $kind = 'intigo',
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type'            => 'intigo_' . $this->kind,
            'title'           => $this->title,
            'body'            => $this->body,
            'icon'            => 'truck',
            'link'            => $this->orderId ? '/orders?order=' . $this->orderId : '/intigo',
            'order_id'        => $this->orderId,
            'seller_order_id' => $this->sellerOrderId,
        ];
    }
}
