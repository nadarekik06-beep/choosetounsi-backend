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
    private $reasonCodes;

    /**
     * @param string      $action       'approved' | 'rejected' | 'changes_requested'
     * @param int         $productId
     * @param string      $productName
     * @param string|null $reason       free-text reason / admin notes (optional)
     * @param string[]    $reasonLabels predefined reason labels (optional)
     */
    /**
     * Pass $reasonCodes (ProductModerationLog::REASONS keys) so the labels are rendered in the
     * seller's language; $reasonLabels stays for callers that only have ready-made text.
     */
    public function __construct($action, $productId, $productName, $reason = null, array $reasonLabels = [], array $reasonCodes = [])
    {
        $this->reasonCodes  = $reasonCodes;
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

        $labels = $this->reasonCodes
            ? \App\Models\ProductModerationLog::labelsFor($this->reasonCodes)
            : $this->reasonLabels;

        if ($this->action === 'approved') {
            return $base + [
                'title' => __('seller.notif.product_reviewed.approved.title'),
                'body'  => __('seller.notif.product_reviewed.approved.body', ['name' => $this->productName]),
                'icon'  => 'check-circle',
            ];
        }

        $details = implode(', ', $labels);
        if ($this->reason) {
            $details = $details ? $details . ' — ' . $this->reason : $this->reason;
        }

        if ($this->action === 'changes_requested') {
            $body = __('seller.notif.product_reviewed.changes.body', ['name' => $this->productName]);
            if ($details) {
                $body .= ' ' . rtrim($details, '. ') . '.';
            }
            $body .= ' ' . __('seller.notif.product_reviewed.changes.resubmit');

            return $base + [
                'title'   => __('seller.notif.product_reviewed.changes.title'),
                'body'    => $body,
                'icon'    => 'alert-triangle',
                'reason'  => $this->reason,
                'reasons' => $labels,
            ];
        }

        $body = __('seller.notif.product_reviewed.rejected.body', ['name' => $this->productName]);
        if ($details) {
            $body .= ' ' . __('seller.notif.product_reviewed.reason', ['reason' => $details]);
        }

        return $base + [
            'title'   => __('seller.notif.product_reviewed.rejected.title'),
            'body'    => $body,
            'icon'    => 'x-circle',
            'reason'  => $this->reason,
            'reasons' => $labels,
        ];
    }
}
