<?php
// app/Notifications/ProductReviewedNotification.php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ProductReviewedNotification extends Notification
{
    private $action;
    private $productId;
    private $productName;
    private $reason;

    /**
     * @param string      $action      'approved' | 'rejected'
     * @param int         $productId
     * @param string      $productName
     * @param string|null $reason      rejection reason (optional)
     */
    public function __construct($action, $productId, $productName, $reason = null)
    {
        $this->action      = $action;
        $this->productId   = $productId;
        $this->productName = $productName;
        $this->reason      = $reason;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        if ($this->action === 'approved') {
            return [
                'type'       => 'product_reviewed',
                'action'     => 'approved',
                'title'      => 'Product approved!',
                'body'       => 'Your product "' . $this->productName . '" has been approved and is now live.',
                'icon'       => 'check-circle',
                'link'       => '/seller/products/' . $this->productId,
                'product_id' => $this->productId,
            ];
        }

        $body = 'Your product "' . $this->productName . '" was rejected.';
        if ($this->reason) {
            $body .= ' Reason: ' . $this->reason;
        }

        return [
            'type'       => 'product_reviewed',
            'action'     => 'rejected',
            'title'      => 'Product rejected',
            'body'       => $body,
            'icon'       => 'x-circle',
            'link'       => '/seller/products/' . $this->productId,
            'product_id' => $this->productId,
            'reason'     => $this->reason,
        ];
    }
}