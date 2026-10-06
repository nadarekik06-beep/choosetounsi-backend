<?php

namespace App\Notifications\Growth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Seller bell (+ e-mail at most weekly) when Growth Radar finds a new
 * high-impact action, or when the results of an applied action are in.
 * Texts are rendered here, in the seller's language.
 *
 *   event = new_cards: params {count, high, type, params, action_kind}
 *   event = result:    params {kind, product, verdict}
 */
class GrowthRadarNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function __construct(public string $event, public array $params, public bool $withEmail)
    {
        $this->onQueue(config('growth.queue'));
    }

    public function via($notifiable): array
    {
        return $this->withEmail && filled($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type'   => 'growth_radar',
            'event'  => $this->event,
            'action' => 'growth_' . $this->event,
            'icon'   => $this->event === 'result' ? 'gauge' : 'trending-up',
            'title'  => $this->title(),
            'body'   => $this->body(),
            'link'   => $this->event === 'result' ? '/seller/growth-radar?tab=history' : '/seller/growth-radar',
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $data = [
            'subject' => __('growth.notify.subject'),
            'title'   => $this->title(),
            'body'    => $this->body(),
            'cta'     => __('growth.notify.cta'),
            'url'     => rtrim((string) config('app.frontend_url'), '/') . '/seller/growth-radar',
            'footer'  => __('growth.notify.footer'),
            'rtl'     => app()->getLocale() === 'ar',
            'logoUrl' => config('seller_notifications.logo_url'),
        ];
        return (new MailMessage)->subject($data['subject'])
            ->view(['emails.forecast.alert', 'emails.forecast.alert-text'], $data);
    }

    private function title(): string
    {
        if ($this->event === 'result') {
            return __('growth.notify.result_title', ['kind' => __('growth.kinds.' . ($this->params['kind'] ?? 'discount'))]);
        }
        return trans_choice('growth.notify.title', (int) $this->params['count'], ['count' => $this->params['count'], 'high' => $this->params['high']]);
    }

    private function body(): string
    {
        $presenter = app(\App\Services\GrowthRadar\Presenter::class);
        if ($this->event === 'result') {
            return __('growth.cards.results.headline.' . $this->params['verdict'], [
                'kind' => __('growth.kinds.' . $this->params['kind']), 'product' => $this->params['product'] ?? '',
            ]);
        }
        $params = $presenter->params($this->params['params'] ?? [], app()->getLocale());
        return $presenter->texts($this->params['type'], $params, ['kind' => $this->params['action_kind'] ?? null])[0];
    }
}
