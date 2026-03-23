<?php
// app/Notifications/ProductReviewedNotification.php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProductReviewedNotification extends Notification
{
    use Queueable;

    private $decision;
    private $productId;
    private $productName;
    private $reason;

    public function __construct($decision, $productId, $productName, $reason = null)
    {
        $this->decision    = $decision;
        $this->productId   = $productId;
        $this->productName = $productName;
        $this->reason      = $reason;
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
            ? 'Your product "' . $this->productName . '" is now live.'
            : 'Your product "' . $this->productName . '" was rejected.' . ($this->reason ? ' Reason: ' . $this->reason : '');

        return [
            'type'         => 'product_reviewed',
            'action'       => $this->decision,
            'title'        => $approved ? 'Product Approved' : 'Product Rejected',
            'body'         => $body,
            'icon'         => $approved ? 'check-circle' : 'x-circle',
            'product_id'   => $this->productId,
            'product_name' => $this->productName,
            'reason'       => $this->reason,
            'link'         => '/seller/products',
        ];
    }
}