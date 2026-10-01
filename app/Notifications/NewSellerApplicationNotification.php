<?php
// app/Notifications/NewSellerApplicationNotification.php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class NewSellerApplicationNotification extends Notification
{
    private $applicationId;
    private $applicantName;
    private $businessName;
    private $userId;

    /**
     * @param int    $applicationId
     * @param string $applicantName  full_name from the application form
     * @param string $businessName
     * @param int    $userId         the applicant's user id
     */
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

    public function toDatabase($notifiable)
    {
        return [
            'type'           => 'seller_application',
            'action'         => 'submitted',
            'title'          => 'New seller application',
            'body'           => $this->applicantName . ' applied to become a seller (' . $this->businessName . ').',
            'icon'           => 'store',
            'link'           => '/seller-applications/' . $this->applicationId,
            'application_id' => $this->applicationId,
            'user_id'        => $this->userId,
            'applicant_name' => $this->applicantName,
            'business_name'  => $this->businessName,
        ];
    }
}