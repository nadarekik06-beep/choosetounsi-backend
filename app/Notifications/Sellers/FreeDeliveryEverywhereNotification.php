<?php

namespace App\Notifications\Sellers;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Seller bell + e-mail, once: delivery is now free on the whole platform and
 * included in the price the client sees. Sent to the sellers whose products
 * offered free delivery before (legacy_free_delivery_products), by
 * `php artisan pricing:notify-free-delivery-sellers`.
 *
 * In the seller's language (User::preferredLocale()), texts in
 * lang/{fr,en,ar}/promotion_notifications.php → free_delivery_everywhere.
 */
class FreeDeliveryEverywhereNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 5;

    /** @param int $products how many of the seller's products offered free delivery */
    public function __construct(public int $products = 0)
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

    public function toDatabase($notifiable): array
    {
        return [
            'type'     => 'free_delivery_everywhere',
            'category' => 'products',
            'audience' => 'seller',
            'title'    => __('promotion_notifications.free_delivery_everywhere.title'),
            'body'     => __('promotion_notifications.free_delivery_everywhere.body'),
            'link'     => '/seller/products',
            'icon'     => 'truck',
            'action'   => 'info',
            'data'     => ['products' => $this->products],
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('promotion_notifications.free_delivery_everywhere.title'))
            ->greeting(__('promotion_notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('promotion_notifications.free_delivery_everywhere.body'))
            ->line(__('promotion_notifications.free_delivery_everywhere.detail'))
            ->action(__('promotion_notifications.free_delivery_everywhere.cta'), rtrim(frontend_url(), '/') . '/seller/products');
    }
}
