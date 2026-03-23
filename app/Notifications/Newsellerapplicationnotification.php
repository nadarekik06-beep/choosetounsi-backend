<?php
// app/Notifications/NewSellerApplicationNotification.php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewSellerApplicationNotification extends Notification
{
    use Queueable;

    private $applicationId;
    private $applicantName;
    private $businessName;
    private $userId;

    public function __construct($applicationId, $applicantName, $businessName, $userId)
    {
        $this->applicationId = $applicationId;
        $this->applicantName = $applicantName;
        $this->businessName  = $businessName;
        $this->userId        = $userId;
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
        return [
            'type'           => 'seller_application',
            'action'         => 'submitted',
            'title'          => 'New Seller Application',
            'body'           => $this->applicantName . ' wants to become a seller (' . $this->businessName . ').',
            'icon'           => 'store',
            'application_id' => $this->applicationId,
            'applicant_name' => $this->applicantName,
            'business_name'  => $this->businessName,
            'user_id'        => $this->userId,
            'link'           => '/seller-applications',
        ];
    }
}