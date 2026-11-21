<?php

namespace App\Notifications;

use App\Models\ProductCommissionOverride;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Seller bell + e-mail about a special commission on one of their products:
 * granted, ending in 3 days (reminder), ended. Queued after commit.
 *
 * Carries ONLY the product, the offer, the dates: the admin's internal note
 * is never loaded here, so it cannot leak to the seller.
 */
class ProductCommissionOverrideNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use \App\Support\Queue\RetriesDelivery;

    public const GRANTED  = 'granted';
    public const REMINDER = 'reminder';
    public const ENDED    = 'ended';

    private int $productId;
    private string $productName;
    private string $type;
    private float $value;
    private ?string $startsAt;
    private ?string $endsAt;

    public function __construct(private string $kind, ProductCommissionOverride $o, string $productName)
    {
        $this->productId   = (int) $o->product_id;
        $this->productName = $productName;
        $this->type        = $o->type;
        $this->value       = (float) $o->value;
        $this->startsAt    = $o->starts_at?->toDateString();
        $this->endsAt      = $o->ends_at?->toDateString();
        $this->onQueue(config('seller_notifications.queue'));
        $this->afterCommit();
    }

    public function via($notifiable): array
    {
        return filled($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    private function link(): string
    {
        return '/seller/products';
    }

    private function date(?string $d): string
    {
        return $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('j F Y') : '';
    }

    private function offer(): string
    {
        $v = rtrim(rtrim(number_format($this->value, 2, '.', ''), '0'), '.');
        return $this->type === ProductCommissionOverride::TYPE_FIXED
            ? "{$v}%"
            : '−' . $v . ' ' . __('seller.notif.special_commission.points');
    }

    private function key(string $leaf): string
    {
        return "seller.notif.special_commission.{$this->kind}.{$leaf}";
    }

    private function params(): array
    {
        return ['name' => $this->productName, 'offer' => $this->offer(), 'start' => $this->date($this->startsAt), 'end' => $this->date($this->endsAt)];
    }

    private function body(): string
    {
        $leaf = 'body';
        if ($this->kind === self::GRANTED) {
            $leaf = $this->startsAt && $this->endsAt ? 'body_from' : ($this->endsAt ? 'body_until' : 'body');
        }
        return __($this->key($leaf), $this->params());
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type'       => 'product_commission_override',
            'action'     => $this->kind,
            'title'      => __($this->key('title')),
            'body'       => $this->body(),
            'icon'       => 'percent',
            'link'       => $this->link(),
            'product_id' => $this->productId,
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__($this->key('subject'), $this->params()))
            ->line($this->body())
            ->line(__('seller.notif.special_commission.orders_note'))
            ->action(__('seller.notif.special_commission.mail_action'), frontend_url() . $this->link());
    }
}
