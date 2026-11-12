<?php

namespace App\Notifications\Sellers;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Seller bell + e-mail: a settlement was paid and its proof of payment (receipt)
 * was added or replaced by the admin. In the seller's language
 * (User::preferredLocale()), texts in lang/{fr,en,ar}/settlement_notifications.php.
 */
class SettlementPaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 5;

    /** @param string $amount payout, already formatted (Money::plain) */
    public function __construct(public int $batchId, public string $reference, public string $amount)
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

    private function body(): string
    {
        return __('settlement_notifications.paid.body', ['amount' => $this->amount]);
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type'     => 'settlement_paid',
            'category' => 'finance',
            'audience' => 'seller',
            'title'    => __('settlement_notifications.paid.title'),
            'body'     => $this->body(),
            'link'     => '/seller/earnings',
            'icon'     => 'check-circle',
            'action'   => 'info',
            'data'     => ['settlement_batch_id' => $this->batchId, 'reference' => $this->reference],
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('settlement_notifications.paid.title'))
            ->greeting(__('settlement_notifications.greeting', ['name' => $notifiable->name]))
            ->line($this->body())
            ->line(__('settlement_notifications.paid.reference', ['reference' => $this->reference]))
            ->action(__('settlement_notifications.paid.cta'), rtrim(frontend_url(), '/') . '/seller/earnings');
    }
}
