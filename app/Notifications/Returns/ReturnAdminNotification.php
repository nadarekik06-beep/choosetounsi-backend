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
 */
class ReturnAdminNotification extends Notification
{
    use Queueable;

    private const TEXT = [
        'seller_accepted'     => ['Return accepted by the seller', ':shop accepted return :return (order :order). Your approval is required to schedule the pick-up.', 'check-circle', 'approved'],
        'escalated'           => ['Return escalated by the client', 'The client contests the refusal of return :return (order :order). Your decision is required.', 'alert-circle', 'pending'],
        'delivered_to_seller' => ['Return parcel delivered to the seller', 'Return :return (order :order) was dropped at :shop. Reception & inspection pending.', 'truck', 'pending'],
        'returned_to_seller'  => ['Return received — refund due', 'Return :return (order :order) was received and inspected. Issue the refund of :amount DT.', 'package-check', 'pending'],
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
            'title'    => $title,
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
        [$title] = self::TEXT[$this->event];
        $url = rtrim((string) config('app.admin_url'), '/') . '/complaints?id=' . $this->complaint->id;

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
        $c = $this->complaint->loadMissing(['order:id,order_number', 'seller:id,name', 'seller.sellerApplication']);
        return strtr(self::TEXT[$this->event][1], [
            ':shop'   => $c->seller?->sellerApplication?->business_name ?? $c->seller?->name ?? 'The seller',
            ':return' => (string) $c->reference,
            ':order'  => (string) ($c->order?->order_number ?? '#' . $c->order_id),
            ':amount' => number_format((float) $c->refund_amount, 3, '.', ' '),
        ]);
    }
}
