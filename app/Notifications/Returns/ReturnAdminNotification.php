<?php

namespace App\Notifications\Returns;

use App\Models\Complaint;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Admin bell + e-mail when a return needs the platform:
 *   seller_accepted      the shop accepted → approve it (or override)
 *   escalated            the client contests the shop's refusal → decide
 *   delivered_to_seller  the courier dropped the parcel at the shop → reception
 *   returned_to_seller   received & inspected → issue the refund
 *   fee_exceeds_refund   the client-paid return fee is above the items' value: refund 0
 * Exchanges:
 *   exchange_needs_check        no clear answer from Intigo: check there, paste the nid
 *   exchange_send_failed        Intigo refused the exchange parcel
 *   exchange_failed             refused at the door: decide (retry / return / cancel)
 *   exchange_carrier_cancelled  Intigo cancelled the exchange parcel
 */
class ReturnAdminNotification extends Notification
{
    use Queueable;

    /** :Kind = Return / Exchange, :kind = return / exchange. */
    private const TEXT = [
        'seller_accepted'     => [':Kind accepted by the seller', ':shop accepted :kind :return (order :order). Your approval is required.', 'check-circle', 'approved'],
        'escalated'           => [':Kind escalated by the client', 'The client contests the refusal of :kind :return (order :order). Your decision is required.', 'alert-circle', 'pending'],
        'delivered_to_seller' => ['Return parcel delivered to the seller', 'Return :return (order :order) was dropped at :shop. Reception & inspection pending.', 'truck', 'pending'],
        'returned_to_seller'  => ['Return received — refund due', 'Return :return (order :order) was received and inspected. Issue the refund of :amount DT.', 'package-check', 'pending'],
        'fee_exceeds_refund'  => ['Return fee above the refund', 'Return :return (order :order): the return fee (:fee DT) is above the items\' value (:items DT). The client would get 0: check before approving.', 'alert-circle', 'pending'],
        'exchange_needs_check' => ['Exchange parcel: check in Intigo', 'Exchange :return (order :order): Intigo did not answer clearly. The parcel may exist: check the Intigo dashboard and paste its tracking number (never re-sent automatically).', 'alert-circle', 'pending'],
        'exchange_send_failed' => ['Exchange parcel refused by Intigo', 'Exchange :return (order :order): Intigo refused the exchange parcel (:error). Fix it and book again, or book it by hand.', 'x-circle', 'pending'],
        'exchange_failed'     => ['Exchange not delivered', 'Exchange :return (order :order) was not delivered (refused at the door). The replacement goes back to :shop: retry, switch to a return or cancel.', 'x-circle', 'pending'],
        'exchange_carrier_cancelled' => ['Exchange parcel cancelled by Intigo', 'Intigo cancelled the exchange parcel of :return (order :order). Mark it failed, then retry or switch to a return.', 'x-circle', 'pending'],
    ];

    public function __construct(private Complaint $complaint, private string $event) {}

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase($notifiable): array
    {
        [$title, , $icon, $action] = self::TEXT[$this->event];

        return [
            'type'     => "return_{$this->event}",
            'category' => 'complaints',
            'audience' => 'admin',
            'title'    => $this->fill($title),
            'body'     => $this->body(),
            'link'     => '/complaints?id=' . $this->complaint->id,
            'icon'     => $icon,
            'action'   => $action,
            'data'     => [
                'complaint_id' => $this->complaint->id,
                'reference'    => $this->complaint->reference,
                'order_id'     => $this->complaint->order_id,
                'status'       => $this->complaint->status,
            ],
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $title = $this->fill(self::TEXT[$this->event][0]);
        $url = admin_url() . '/complaints?id=' . $this->complaint->id;

        $mail = (new MailMessage)
            ->subject("{$title} — {$this->complaint->reference}")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->body())
            ->line('**Reason:** ' . $this->complaint->getTypeLabel())
            ->line('**Item(s):** ' . (implode(' · ', $this->complaint->itemSummaries()) ?: '—'));

        if ($this->event === 'escalated') {
            if ($this->complaint->rejection_reason) $mail->line("**Seller's reason:** {$this->complaint->rejection_reason}");
            if ($this->complaint->escalation_note)  $mail->line("**Client's note:** {$this->complaint->escalation_note}");
        }

        return $mail->action('Open the return', $url);
    }

    private function body(): string
    {
        return $this->fill(self::TEXT[$this->event][1]);
    }

    private function fill(string $text): string
    {
        $c = $this->complaint->loadMissing(['order:id,order_number', 'seller:id,name', 'seller.sellerApplication']);
        $exchange = $c->isExchange();
        return strtr($text, [
            ':shop'   => $c->seller?->sellerApplication?->business_name ?? $c->seller?->name ?? 'The seller',
            ':return' => (string) $c->reference,
            ':order'  => (string) ($c->order?->order_number ?? '#' . $c->order_id),
            ':amount' => number_format((float) $c->refund_amount, 3, '.', ' '),
            ':fee'    => number_format($c->caseFee(), 3, '.', ' '),
            ':items'  => number_format((float) $c->items_amount, 3, '.', ' '),
            ':error'  => (string) $c->exchange_sync_error,
            ':Kind'   => $exchange ? 'Exchange' : 'Return',
            ':kind'   => $exchange ? 'exchange' : 'return',
        ]);
    }
}
