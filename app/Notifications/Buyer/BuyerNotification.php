<?php

namespace App\Notifications\Buyer;

use App\Notifications\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Base of every notification sent to someone as a buyer (whatever their role:
 * a seller who buys on the storefront gets these too, in the buyer bell).
 *
 *   - payload follows the contract (App\Notifications\Support\Payload);
 *   - channels follow the user's choices for the category
 *     (App\Notifications\Support\NotificationPreferences);
 *   - queued, one job per channel, only after the open transaction commits.
 *
 * Send through App\Services\Notifications\BuyerNotifier when dedupeKey() is set:
 * it claims the key once per user, so a retry or a double status update can't
 * send the same notification twice.
 */
abstract class BuyerNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $tries = 5;

    public function __construct()
    {
        $this->onQueue(config('notifications.queue'));
        $this->afterCommit();
    }

    /** orders | payments | complaints | reviews | promotions | account */
    abstract public function category(): string;

    abstract protected function type(): string;
    abstract protected function title(): string;
    abstract protected function body(): string;

    public function audience($notifiable = null): string
    {
        return 'buyer';
    }

    /** One send per user and key (BuyerNotifier); null = no dedupe. */
    public function dedupeKey(): ?string
    {
        return null;
    }

    protected function link(): ?string { return null; }
    protected function icon(): string { return 'bell'; }
    protected function action(): string { return 'info'; }
    protected function data(): array { return []; }

    /** Extra e-mail paragraphs after the body. */
    protected function mailLines(): array { return []; }
    /** Optional summary table: [[label, value], …]. */
    protected function mailTable(): array { return []; }
    protected function mailButton(): string { return __('buyer_notifications.mail.button_default'); }
    protected function mailSubject(): string { return $this->title(); }
    /** False: bell only, never an e-mail. */
    protected function hasMail(): bool { return true; }

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function via($notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return $this->hasMail() ? ['mail'] : [];
        }

        $channels = [];
        if (NotificationPreferences::allows($notifiable, $this->category(), 'in_app')) {
            $channels[] = 'database';
        }
        if ($this->hasMail() && NotificationPreferences::allows($notifiable, $this->category(), 'email')) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type'     => $this->type(),
            'category' => $this->category(),
            'audience' => $this->audience($notifiable),
            'title'    => $this->title(),
            'body'     => $this->body(),
            'link'     => $this->link(),
            'icon'     => $this->icon(),
            'action'   => $this->action(),
            'data'     => $this->data(),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $link = $this->link();

        return (new MailMessage)
            ->subject($this->mailSubject())
            ->markdown('emails.buyer.notification', [
                'heading' => $this->title(),
                'name'    => $this->firstName($notifiable),
                'body'    => $this->body(),
                'lines'   => $this->mailLines(),
                'table'   => $this->mailTable(),
                'button'  => $link ? $this->mailButton() : null,
                'url'     => $link ? rtrim((string) config('app.frontend_url'), '/') . $link : null,
                'footer'  => __("buyer_notifications.mail.footer.{$this->category()}"),
            ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error(sprintf('[BuyerNotification] %s failed: %s', static::class, $e->getMessage()));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    protected static function money($amount): string
    {
        return __('buyer_notifications.money', ['amount' => number_format((float) $amount, 2, ',', ' ')]);
    }

    /** "A", "A et B", "A, B et C". */
    protected static function joinNames(array $names): string
    {
        $names = array_values(array_unique(array_filter($names)));
        if (count($names) <= 1) return $names[0] ?? '';
        $last = array_pop($names);
        return implode(', ', $names) . ' ' . __('buyer_notifications.and') . ' ' . $last;
    }

    private function firstName($notifiable): ?string
    {
        $name = trim((string) ($notifiable->first_name ?? $notifiable->name ?? ''));
        return $name === '' ? null : preg_split('/\s+/u', $name)[0];
    }
}
