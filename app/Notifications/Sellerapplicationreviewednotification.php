<?php
// app/Notifications/SellerApplicationReviewedNotification.php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SellerApplicationReviewedNotification extends Notification
{
    use Queueable;

    private $decision;
    private $businessName;
    private $reason;

    public function __construct($decision, $businessName, $reason = null)
    {
        $this->decision     = $decision;
        $this->businessName = $businessName;
        $this->reason       = $reason;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        return $this->payload();
    }

    public function toDatabase($notifiable)
    {
        return $this->payload();
    }

    private function payload()
    {
        $approved = $this->decision === 'approved';

        $body = $approved
            ? 'Your seller application for "' . $this->businessName . '" was approved! You can now sell.'
            : 'Your seller application for "' . $this->businessName . '" was not approved.' . ($this->reason ? ' Reason: ' . $this->reason : '');

        return [
            'type'          => 'application_reviewed',
            'action'        => $this->decision,
            'title'         => $approved ? 'Application Approved' : 'Application Rejected',
            'body'          => $body,
            'icon'          => $approved ? 'check-circle' : 'x-circle',
            'business_name' => $this->businessName,
            'reason'        => $this->reason,
            'link'          => '/seller',
        ];
    }
}