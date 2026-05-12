<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * BlackSmartNotification  -- Phase 3
 *
 * Generic smart notification for Black Pepper sellers.
 * Sent by BlackDailyNotify command.
 * Shape matches NotificationBell.tsx contract exactly.
 *
 * Usage:
 *   $seller->notify(new BlackSmartNotification([
 *       'notify_type' => 'auto_promo',
 *       'source'      => 'black_daily_notify',
 *       'title'       => 'Your hottest product is not sponsored yet',
 *       'body'        => '...',
 *       'icon'        => 'zap',
 *       'action'      => 'promote',
 *       'link'        => '/seller/black',
 *       'product_id'  => 42,
 *   ]));
 */
class BlackSmartNotification extends Notification
{
    use Queueable;

    public function __construct(private array $payload) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return array_merge($this->payload, [
            'type'       => 'black_smart',
            'created_at' => now()->format('Y-m-d\TH:i:s\Z'),
        ]);
    }
}