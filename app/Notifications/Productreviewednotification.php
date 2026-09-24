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
    private $reasonLabels;

    /**
     * @param string      $action       'approved' | 'rejected' | 'changes_requested'
     * @param int         $productId
     * @param string      $productName
     * @param string|null $reason       free-text reason / admin notes (optional)
     * @param string[]    $reasonLabels predefined reason labels (optional)
     */
    public function __construct($action, $productId, $productName, $reason = null, array $reasonLabels = [])
    {
        $this->action       = $action;
        $this->productId    = $productId;
        $this->productName  = $productName;
        $this->reason       = $reason;
        $this->reasonLabels = $reasonLabels;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        $base = [
            'type'       => 'product_reviewed',
            'action'     => $this->action,
            'link'       => '/seller/products/' . $this->productId,
            'product_id' => $this->productId,
        ];

        if ($this->action === 'approved') {
            return $base + [
                'title' => 'Product approved!',
                'body'  => 'Your product "' . $this->productName . '" has been approved and is now live.',
                'icon'  => 'check-circle',
            ];
        }

        $details = implode(', ', $this->reasonLabels);
        if ($this->reason) {
            $details = $details ? $details . ' — ' . $this->reason : $this->reason;
        }

        if ($this->action === 'changes_requested') {
            $body = 'Changes were requested on your product "' . $this->productName . '".';
            if ($details) {
                $body .= ' ' . rtrim($details, '. ') . '.';
            }
            $body .= ' Edit the product to resubmit it for review.';

            return $base + [
                'title'   => 'Changes requested',
                'body'    => $body,
                'icon'    => 'alert-triangle',
                'reason'  => $this->reason,
                'reasons' => $this->reasonLabels,
            ];
        }

        $body = 'Your product "' . $this->productName . '" was rejected.';
        if ($details) {
            $body .= ' Reason: ' . $details;
        }

        return $base + [
            'title'   => 'Product rejected',
            'body'    => $body,
            'icon'    => 'x-circle',
            'reason'  => $this->reason,
            'reasons' => $this->reasonLabels,
        ];
    }
}
