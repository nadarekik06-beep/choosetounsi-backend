<?php

namespace App\Notifications\Channels;

use App\Notifications\Support\Payload;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * The 'database' channel of every notification (bound in AppServiceProvider):
 * stores the payload in the shared contract (App\Notifications\Support\Payload)
 * and copies audience / category to their own indexed columns.
 */
class ContractDatabaseChannel extends DatabaseChannel
{
    protected function buildPayload($notifiable, Notification $notification)
    {
        $declared = array_filter([
            'audience' => method_exists($notification, 'audience') ? $notification->audience($notifiable) : null,
            'category' => method_exists($notification, 'category') ? $notification->category() : null,
        ]);

        $payload = Payload::normalize(
            $this->getData($notifiable, $notification),
            get_class($notification),
            $notifiable,
            $declared,
        );

        return [
            'id'       => $notification->id,
            'type'     => method_exists($notification, 'databaseType')
                ? $notification->databaseType($notifiable)
                : get_class($notification),
            'audience' => $payload['audience'],
            'category' => $payload['category'],
            'data'     => $payload,
            'read_at'  => null,
        ];
    }
}
