<?php
// app/Notifications/ProductUpdateRequestNotification.php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ProductUpdateRequestNotification extends Notification
{
    private string $action;
    private int    $requestId;
    private int    $productId;
    private string $productName;
    private string $actorName;
    private ?string $adminComment;

    /**
     * @param string      $action      'submitted' | 'approved' | 'rejected'
     * @param int         $requestId
     * @param int         $productId
     * @param string      $productName
     * @param string      $actorName
     * @param string|null $adminComment
     */
    public function __construct(
        string  $action,
        int     $requestId,
        int     $productId,
        string  $productName,
        string  $actorName,
        ?string $adminComment = null
    ) {
        $this->action       = $action;
        $this->requestId    = $requestId;
        $this->productId    = $productId;
        $this->productName  = $productName;
        $this->actorName    = $actorName;
        $this->adminComment = $adminComment;
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        switch ($this->action) {
            case 'submitted':
                return [
                    'type'       => 'product_update_request',
                    'action'     => 'submitted',
                    'title'      => 'Product update requested',
                    'body'       => $this->actorName . ' requested changes to "' . $this->productName . '".',
                    'icon'       => 'file-edit',
                    'link'       => '/product-update-requests?highlight=' . $this->requestId,
                    'request_id' => $this->requestId,
                    'product_id' => $this->productId,
                    'actor_name' => $this->actorName,
                ];

            case 'approved':
                return [
                    'type'       => 'product_update_request',
                    'action'     => 'approved',
                    'title'      => __('seller.notif.update_request.approved.title'),
                    'body'       => __('seller.notif.update_request.approved.body', ['name' => $this->productName]),
                    'icon'       => 'check-circle',
                    'link'       => '/seller/products/' . $this->productId,
                    'request_id' => $this->requestId,
                    'product_id' => $this->productId,
                ];

            case 'rejected':
            default:
                $body = __('seller.notif.update_request.rejected.body', ['name' => $this->productName]);
                if ($this->adminComment) {
                    $body .= ' ' . __('seller.notif.product_reviewed.reason', ['reason' => $this->adminComment]);
                }
                return [
                    'type'          => 'product_update_request',
                    'action'        => 'rejected',
                    'title'         => __('seller.notif.update_request.rejected.title'),
                    'body'          => $body,
                    'icon'          => 'x-circle',
                    'link'          => '/seller/products/' . $this->productId,
                    'request_id'    => $this->requestId,
                    'product_id'    => $this->productId,
                    'admin_comment' => $this->adminComment,
                ];
        }
    }
}