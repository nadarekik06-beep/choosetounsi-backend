<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Tells a seller something changed on their subscription (admin action,
 * trial / expiry lifecycle, commission override…). Database channel, same
 * shape as the other seller notifications (title / body / icon / link).
 */
class SubscriptionUpdatedNotification extends Notification
{
    public function __construct(
        private string $action,
        private string $title,
        private string $body,
        private array $meta = []
    ) {}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'type'   => 'subscription_updated',
            'source' => $this->meta['source'] ?? 'subscription_' . $this->action,
            'action' => $this->action,
            'title'  => $this->title,
            'body'   => $this->body,
            'icon'   => $this->meta['icon'] ?? 'credit-card',
            'link'   => '/seller/subscription',
        ] + $this->meta;
    }
}
