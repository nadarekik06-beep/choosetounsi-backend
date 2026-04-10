<?php
// app/Notifications/SellerUpgradedNotification.php

namespace App\Notifications;

use App\Models\User;
use App\Models\SubscriptionPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SellerUpgradedNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected User                $seller,
        protected SubscriptionPayment $payment
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $planLabel = match($this->payment->plan) {
            'red'   => 'Red Pepper',
            'black' => 'Black Pepper',
            default => $this->payment->plan,
        };

        $planColor = match($this->payment->plan) {
            'red'   => '#db142e',
            'black' => '#f59e0b',
            default => '#198f41',
        };

        return [
            'type'    => 'seller_upgraded',
            'action'  => 'upgraded',
            'title'   => "Seller upgraded to {$planLabel}",
            'body'    => "{$this->seller->name} just upgraded their subscription to {$planLabel} ({$this->payment->amount} DT).",
            'icon'    => 'package-check',
            'color'   => $planColor,
            'link'    => "/sellers",   // admin panel link to seller list
            'meta'    => [
                'seller_id'  => $this->seller->id,
                'seller_name'=> $this->seller->name,
                'plan'       => $this->payment->plan,
                'amount'     => $this->payment->amount,
                'payment_id' => $this->payment->id,
            ],
        ];
    }
}