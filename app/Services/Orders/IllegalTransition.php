<?php

namespace App\Services\Orders;

/** A parcel status change the workflow (ParcelStatus::TRANSITIONS) does not allow. */
class IllegalTransition extends \DomainException
{
    public function __construct(string $message, public readonly string $from = '', public readonly string $to = '')
    {
        parent::__construct($message);
    }

    public static function of(string $from, string $to, ?int $parcelId = null): self
    {
        $allowed = ParcelStatus::TRANSITIONS[$from] ?? [];
        $parcel  = $parcelId ? "Parcel #{$parcelId}" : 'This parcel';
        $why = match (true) {
            $to === 'cancelled' && !in_array($from, ParcelStatus::BEFORE_COURIER, true)
                => 'only a parcel not yet handed to the courier can be cancelled',
            $to === 'refused'   => 'only a shipped parcel (out for delivery) can be refused',
            $to === 'delivered' => 'only a shipped parcel (out for delivery) can be delivered',
            $to === 'returned_to_seller' => 'only a refused parcel can be returned to the seller',
            default => $allowed ? 'allowed next: ' . implode(', ', $allowed) : 'its status is final',
        };
        return new self("{$parcel} is {$from} and can't become {$to}: {$why}.", $from, $to);
    }

    public static function moneyCommitted(int $parcelId, string $from, string $to, string $batch): self
    {
        return new self("Parcel #{$parcelId} can't become {$to}: its payout is already settled ({$batch}). A return goes through the return flow.", $from, $to);
    }

    public static function reopenUnavailable(int $parcelId, string $to, string $why): self
    {
        return new self("Parcel #{$parcelId} can't be re-opened: {$why}. Nothing was reserved.", 'cancelled', $to);
    }
}
