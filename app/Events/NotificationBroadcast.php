<?php
// app/Events/NotificationBroadcast.php
// Only needed for Pusher — ignore if using polling

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationBroadcast implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $payload;
    public $notifiableType;
    public $notifiableId;

    public function __construct($payload, $notifiableType, $notifiableId)
    {
        $this->payload        = $payload;
        $this->notifiableType = $notifiableType;
        $this->notifiableId   = $notifiableId;
    }

    public function broadcastOn()
    {
        $prefix = strpos($this->notifiableType, 'Admin') !== false ? 'admin' : 'user';

        return new PrivateChannel($prefix . '.' . $this->notifiableId);
    }

    public function broadcastAs()
    {
        return 'new-notification';
    }

    public function broadcastWith()
    {
        return $this->payload;
    }
}