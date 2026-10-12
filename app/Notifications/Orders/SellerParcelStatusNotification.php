<?php

namespace App\Notifications\Orders;

/**
 * The delivery agency moved the seller's parcel: out for delivery, delivered
 * (cash collected, payout on its way) or returned to the seller's pickup
 * address. Dashboard bell only. Sent through SellerOrderNotifier::parcelUpdate().
 */
class SellerParcelStatusNotification extends SellerOrderNotification
{
    public const STEPS = [
        'out_for_delivery'   => 'parcel_out_for_delivery',
        'delivered'          => 'parcel_delivered',
        'returned_to_seller' => 'parcel_returned',
    ];

    public function __construct(\App\Models\SellerOrder $sellerOrder, public string $step)
    {
        parent::__construct($sellerOrder);
    }

    public function event(): string
    {
        return self::STEPS[$this->step] ?? 'parcel_out_for_delivery';
    }

    protected function icon(): string
    {
        return match ($this->step) {
            'delivered'          => 'package-check',
            'returned_to_seller' => 'undo-2',
            default              => 'truck',
        };
    }

    protected function action(): string
    {
        return $this->step === 'delivered' ? 'approved' : 'order';
    }

    public function via($notifiable): array
    {
        return ['database'];
    }
}
