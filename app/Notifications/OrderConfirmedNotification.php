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
            'title'        => '✅ Order Confirmed — Ready to Prepare',
            'body'         => "Order {$this->orderNumber} has been confirmed by admin. Please start preparing it now.",
            'icon'         => 'package-check',
            'link'         => '/orders',
            'order_id'     => $this->order->id,
            'order_number' => $this->orderNumber,
            'admin_note'   => $this->adminNote,
        ];
    }
}