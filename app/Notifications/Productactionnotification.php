<?php
// app/Notifications/ProductActionNotification.php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProductActionNotification extends Notification
{
    use Queueable;

    private $action;
    private $productId;
    private $productName;
    private $sellerName;
    private $sellerId;

    public function __construct($action, $productId, $productName, $sellerName, $sellerId)
    {
        $this->action      = $action;
        $this->productId   = $productId;
        $this->productName = $productName;
        $this->sellerName  = $sellerName;
        $this->sellerId    = $sellerId;
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
        $titles = [
            'created' => 'New product by ' . $this->sellerName,
            'updated' => 'Product updated by ' . $this->sellerName,
            'deleted' => 'Product deleted by ' . $this->sellerName,
        ];

        $icons = [
            'created' => 'package-plus',
            'updated' => 'package-check',
            'deleted' => 'package-x',
        ];

        return [
            'type'         => 'product_action',
            'action'       => $this->action,
            'title'        => isset($titles[$this->action]) ? $titles[$this->action] : 'Product ' . $this->action,
            'body'         => '"' . $this->productName . '" was ' . $this->action . '.',
            'icon'         => isset($icons[$this->action]) ? $icons[$this->action] : 'package',
            'product_id'   => $this->productId,
            'product_name' => $this->productName,
            'seller_id'    => $this->sellerId,
            'seller_name'  => $this->sellerName,
            'link'         => '/products',
        ];
    }
}