<?php
// app/Notifications/SellerApplicationReviewedNotification.php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class SellerApplicationReviewedNotification extends Notification
{
    private $action;
    private $businessName;
    private $reason;

    /**
     * @param string      $action       'approved' | 'rejected'
     * @param string      $businessName
     * @param string|null $reason       rejection reason (optional)
     */
    public function __construct($action, $businessName, $reason = null)
    {
        $this->action       = $action;
        $this->businessName = $businessName;
        $this->reason       = $reason;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        if ($this->action === 'approved') {
            return [
                'type'          => 'seller_application_reviewed',
                'action'        => 'approved',
                'title'         => 'Application approved!',
                'body'          => 'Congratulations! Your seller application for "' . $this->businessName . '" has been approved. You can now list products.',
                'icon'          => 'check-circle',
                'link'          => '/seller/dashboard',
                'business_name' => $this->businessName,
            ];
        }

        $body = 'Your seller application for "' . $this->businessName . '" was not approved.';
        if ($this->reason) {
            $body .= ' Reason: ' . $this->reason;
        }

        return [
            'type'          => 'seller_application_reviewed',
            'action'        => 'rejected',
            'title'         => 'Application not approved',
            'body'          => $body,
            'icon'          => 'x-circle',
            'link'          => '/apply-seller',
            'business_name' => $this->businessName,
            'reason'        => $this->reason,
        ];
    }
}