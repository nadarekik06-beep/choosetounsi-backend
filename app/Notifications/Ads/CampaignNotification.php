<?php

namespace App\Notifications\Ads;

use App\Models\Sponsorship;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Shared shape of the seller's campaign notifications: a NotificationBell row
 * (type/title/body/icon/link) plus the same text by e-mail. Queued; rendered in
 * the seller's locale (User::preferredLocale()).
 */
abstract class CampaignNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Sponsorship $campaign)
    {
        $this->afterCommit = true;
    }

    abstract protected function type(): string;

    abstract protected function title(): string;

    abstract protected function body(): string;

    protected function icon(): string
    {
        return 'megaphone';
    }

    public function via(object $notifiable): array
    {
        return empty($notifiable->email) ? ['database'] : ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'           => $this->type(),
            'action'         => $this->type(),
            'title'          => $this->title(),
            'body'           => $this->body(),
            'icon'           => $this->icon(),
            'link'           => $this->link(),
            'sponsorship_id' => $this->campaign->id,
            'product_id'     => $this->campaign->product_id,
            'created_at'     => now()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->body())
            ->action(__('ads.notif.view'), rtrim((string) config('app.frontend_url'), '/') . $this->link());
    }

    protected function link(): string
    {
        return "/seller/promote/campaigns/{$this->campaign->id}";
    }

    protected function productName(): string
    {
        $product = $this->campaign->product;
        return (string) ($product?->getRawOriginal('name') ?? $product?->name ?? '#' . $this->campaign->product_id);
    }

    protected function money($value): string
    {
        return number_format((float) $value, 3, '.', ' ');
    }
}
