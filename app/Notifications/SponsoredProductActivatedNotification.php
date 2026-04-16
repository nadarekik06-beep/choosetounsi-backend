<?php
// app/Notifications/SponsoredProductActivatedNotification.php

namespace App\Notifications;

use App\Models\User;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent to all admins when a Black Pepper seller activates product sponsorship.
 */
class SponsoredProductActivatedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private  User    $seller,
        private  Product $product,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'         => 'sponsored_product',
            'title'        => '⭐ Product Sponsored',
            'message'      => "{$this->seller->name} activated sponsorship for \"{$this->product->name}\".",
            'product_id'   => $this->product->id,
            'product_name' => $this->product->name,
            'seller_id'    => $this->seller->id,
            'seller_name'  => $this->seller->name,
            'created_at'   => now()->toISOString(),
        ];
    }
}