<?php
// app/Notifications/ProductActionNotification.php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ProductActionNotification extends Notification
{
    private $action;
    private $productId;
    private $productName;
    private $actorName;
    private $actorId;

    /**
     * @param string $action      'created' | 'updated' | 'deleted'
     * @param int    $productId
     * @param string $productName
     * @param string $actorName   seller's name (or 'Admin')
     * @param int    $actorId     seller's user id (or 0 for admin)
     */
    public function __construct($action, $productId, $productName, $actorName, $actorId)
    {
        $this->action      = $action;
        $this->productId   = $productId;
        $this->productName = $productName;
        $this->actorName   = $actorName;
        $this->actorId     = $actorId;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        $map = [
            'created' => [
                'title' => 'New product submitted',
                'body'  => $this->actorName . ' submitted "' . $this->productName . '" for review.',
                'icon'  => 'package-plus',
                'link'  => '/products?highlight=' . $this->productId,
            ],
            'updated' => [
                'title' => 'Product updated',
                'body'  => $this->actorName . ' updated "' . $this->productName . '".',
                'icon'  => 'package-check',
                'link'  => '/products/' . $this->productId,
            ],
            'deleted' => [
                'title' => 'Product deleted',
                'body'  => $this->actorName . ' deleted "' . $this->productName . '".',
                'icon'  => 'package-x',
                'link'  => '/products',
            ],
        ];

        $entry = isset($map[$this->action]) ? $map[$this->action] : $map['updated'];

        return [
            'type'       => 'product_action',
            'action'     => $this->action,
            'title'      => $entry['title'],
            'body'       => $entry['body'],
            'icon'       => $entry['icon'],
            'link'       => $entry['link'],
            'product_id' => $this->productId,
            'actor_id'   => $this->actorId,
            'actor_name' => $this->actorName,
        ];
    }
}