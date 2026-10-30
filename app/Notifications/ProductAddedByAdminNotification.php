<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Seller bell + e-mail: an admin created a product in the seller's shop
 * (admin panel "Add product", or "Split into separate products").
 * Queued after the commit, so a failing e-mail never blocks the creation.
 */
class ProductAddedByAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function __construct(private int $productId, private string $productName, private bool $published)
    {
        $this->onQueue(config('seller_notifications.queue'));
        $this->afterCommit();
    }

    public function via($notifiable): array
    {
        return filled($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    private function link(): string
    {
        return '/seller/products/' . $this->productId;
    }

    private function body(): string
    {
        return __($this->published
            ? 'seller.notif.product_added_by_admin.body'
            : 'seller.notif.product_added_by_admin.body_pending', ['name' => $this->productName]);
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type'       => 'product_added_by_admin',
            'action'     => 'added_by_admin',
            'title'      => __('seller.notif.product_added_by_admin.title'),
            'body'       => $this->body(),
            'icon'       => 'package',
            'link'       => $this->link(),
            'product_id' => $this->productId,
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('seller.notif.product_added_by_admin.subject', ['name' => $this->productName]))
            ->line($this->body())
            ->line(__('seller.notif.product_added_by_admin.mail_edit'))
            ->action(__('seller.notif.product_added_by_admin.mail_action'), frontend_url() . $this->link());
    }
}
