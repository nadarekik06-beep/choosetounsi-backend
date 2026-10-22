<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * A parcel that needs a human (Intigo refused it, it could not be sent, possible
 * duplicate), to config intigo.alert_email, in French. Sent by IntigoAlerts with
 * the bell notification, once per parcel and reason.
 */
class IntigoAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $title,
        public string $reason,
        public ?string $orderNumber,
        public ?int $orderId,
        public int $sellerOrderId,
    ) {
        $this->queue  = 'emails';
        $this->locale = 'fr';
    }

    public function build(): self
    {
        $ref = $this->orderNumber ? "{$this->orderNumber}-S{$this->sellerOrderId}" : "colis #{$this->sellerOrderId}";

        return $this
            ->subject("[Intigo] {$this->title} — {$ref}")
            ->view('emails.intigo.alert', [
                'title'    => $this->title,
                'reason'   => $this->reason,
                'ref'      => $ref,
                'orderUrl' => $this->orderId ? admin_url('/orders?order=' . $this->orderId) : null,
                'panelUrl' => admin_url('/intigo'),
            ]);
    }
}
