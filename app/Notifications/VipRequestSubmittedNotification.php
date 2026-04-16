<?php
// app/Notifications/VipRequestSubmittedNotification.php

namespace App\Notifications;

use App\Models\User;
use App\Models\VipRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\DatabaseMessage;

/**
 * Sent to all admins when a Black Pepper seller submits a VIP request.
 * Uses the database channel so it shows up in the existing
 * admin notification bell (AdminNotificationController).
 */
class VipRequestSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private  User       $seller,
        private  VipRequest $vipRequest,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'       => 'vip_request',
            'title'      => '👑 New VIP Request',
            'message'    => "Black Pepper seller {$this->seller->name} submitted a {$this->vipRequest->type_label}.",
            'request_id' => $this->vipRequest->id,
            'seller_id'  => $this->seller->id,
            'seller_name'=> $this->seller->name,
            'request_type' => $this->vipRequest->type,
            'created_at' => now()->toISOString(),
        ];
    }
}