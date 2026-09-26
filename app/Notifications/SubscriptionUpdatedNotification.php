<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Tells a seller something changed on their subscription (admin action,
 * trial / expiry lifecycle, commission override…). Database channel, same
 * shape as the other seller notifications (title / body / icon / link).
 */
class SubscriptionUpdatedNotification extends Notification
{
    /**
     * @param string $key    seller.notif.subscription.{key} — rendered in the recipient's locale
     * @param array  $params placeholders; any "*_date" value (Y-m-d) is formatted for that locale
     */
    public function __construct(
        private string $action,
        private string $key,
        private array $params = [],
        private array $meta = []
    ) {}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        $params = [];
        foreach ($this->params as $name => $value) {
            if (Str::endsWith($name, '_date') && $value) {
                $value = Carbon::parse($value)->translatedFormat('j F Y');
            }
            $params[str_replace('_date', '', $name)] = $value;
        }
        $params['when'] = isset($this->params['days'])
            ? trans_choice('seller.notif.subscription.when', (int) $this->params['days'], ['days' => $this->params['days']])
            : '';

        return [
            'type'   => 'subscription_updated',
            'source' => $this->meta['source'] ?? 'subscription_' . $this->action,
            'action' => $this->action,
            'title'  => __("seller.notif.subscription.{$this->key}.title", $params),
            'body'   => __("seller.notif.subscription.{$this->key}.body", $params),
            'icon'   => $this->meta['icon'] ?? 'credit-card',
            'link'   => '/seller/subscription',
        ] + $this->meta;
    }
}
