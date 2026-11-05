<?php

namespace App\Notifications\Sellers;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Seller bell + e-mail: Choose'Tounsi created, changed or ended a promotion on
 * the seller's products, or removed products from the seller's own promotion
 * to put them in an admin one ("replaced"). Sent in the seller's language
 * (User::preferredLocale()), texts in lang/{fr,en,ar}/promotion_notifications.php.
 *
 * The seller pays the discount (paid on the discounted price, same as their own
 * promotions), so the e-mail says it plainly.
 *
 * Plain values only (no models), so a deleted promotion can still be announced.
 */
class AdminPromotionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const EVENTS = ['created', 'updated', 'paused', 'resumed', 'ended', 'replaced'];

    public $tries = 5;

    /**
     * @param string   $event    one of EVENTS
     * @param array    $promo    id, name, discount_type, discount_value, starts_at, ends_at (ISO)
     * @param string[] $products product names concerned
     */
    public function __construct(public string $event, public array $promo, public array $products)
    {
        $this->onQueue(config('seller_notifications.queue'));
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function via($notifiable): array
    {
        return filled($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    private function key(string $part): string
    {
        return "promotion_notifications.{$this->event}.{$part}";
    }

    private function params(): array
    {
        $value = $this->promo['discount_type'] === 'percentage'
            ? rtrim(rtrim(number_format((float) $this->promo['discount_value'], 2, '.', ''), '0'), '.') . '%'
            : number_format((float) $this->promo['discount_value'], 3, '.', '') . ' DT';

        return [
            'name'     => $this->promo['name'],
            'discount' => $value,
            'products' => implode(', ', array_slice($this->products, 0, 5)) . (count($this->products) > 5 ? ' …' : ''),
            'count'    => count($this->products),
            'starts'   => $this->date($this->promo['starts_at'] ?? null),
            'ends'     => $this->date($this->promo['ends_at'] ?? null),
        ];
    }

    private function date(?string $iso): string
    {
        return $iso ? \Carbon\Carbon::parse($iso)->timezone('Africa/Tunis')->format('d/m/Y H:i') : '—';
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type'     => 'admin_promotion',
            'category' => 'products',
            'audience' => 'seller',
            'title'    => __($this->key('title'), $this->params()),
            'body'     => __($this->key('body'), $this->params()),
            'link'     => '/seller/promotions',
            'icon'     => 'tag',
            'action'   => in_array($this->event, ['ended', 'replaced', 'paused'], true) ? 'pending' : 'coupon',
            'data'     => [
                'event'        => $this->event,
                'promotion_id' => $this->promo['id'] ?? null,
                'products'     => $this->products,
            ],
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $p = $this->params();

        $mail = (new MailMessage)
            ->subject(__($this->key('title'), $p))
            ->greeting(__('promotion_notifications.greeting', ['name' => $notifiable->name]))
            ->line(__($this->key('body'), $p));

        if (!in_array($this->event, ['ended', 'replaced'], true)) {
            $mail->line(__('promotion_notifications.details', $p))
                 ->line(__('promotion_notifications.funding'));
        }

        return $mail
            ->line(__('promotion_notifications.read_only'))
            ->action(__('promotion_notifications.cta'), rtrim(frontend_url(), '/') . '/seller/promotions');
    }
}
