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
                'title'         => __('seller.notif.application.approved.title'),
                'body'          => __('seller.notif.application.approved.body', ['name' => $this->businessName]),
                'icon'          => 'check-circle',
                'link'          => '/seller/dashboard',
                'business_name' => $this->businessName,
            ];
        }

        $body = __('seller.notif.application.rejected.body', ['name' => $this->businessName]);
        if ($this->reason) {
            $body .= ' ' . __('seller.notif.product_reviewed.reason', ['reason' => $this->reason]);
        }

        return [
            'type'          => 'seller_application_reviewed',
            'action'        => 'rejected',
            'title'         => __('seller.notif.application.rejected.title'),
            'body'          => $body,
            'icon'          => 'x-circle',
            'link'          => '/apply-seller',
            'business_name' => $this->businessName,
            'reason'        => $this->reason,
        ];
    }
}