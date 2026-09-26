<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use App\Models\Order;

class OrderConfirmedNotification extends Notification
{
    public function __construct(
        private Order  $order,
        private string $orderNumber,
        private ?string $adminNote = null
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type'         => 'order_confirmed',
            'title'        => __('seller.notif.order_confirmed.title'),
            'body'         => __('seller.notif.order_confirmed.body', ['order' => $this->orderNumber]),
            'icon'         => 'package-check',
            'link'         => '/orders',
            'order_id'     => $this->order->id,
            'order_number' => $this->orderNumber,
            'admin_note'   => $this->adminNote,
        ];
    }
}