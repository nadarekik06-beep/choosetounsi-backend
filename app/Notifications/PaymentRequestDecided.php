<?php

namespace App\Notifications;

use App\Models\PaymentRequest;
use App\Models\SellerSubscription;
use App\Models\SubscriptionPlan;
use App\Services\Payments\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A seller's manual (WhatsApp) payment request was approved or rejected:
 * NotificationBell row + the same text by e-mail (rejection reason included).
 * Queued after commit, rendered in the seller's locale.
 */
class PaymentRequestDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public PaymentRequest $request)
    {
        $this->afterCommit = true;
    }

    public function via(object $notifiable): array
    {
        return empty($notifiable->email) ? ['database'] : ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'               => 'payment_request_' . $this->request->status,
            'action'             => 'payment_request_' . $this->request->status,
            'title'              => $this->title(),
            'body'               => $this->body(),
            'icon'               => $this->request->status === PaymentRequest::STATUS_APPROVED ? 'check-circle' : 'x-circle',
            'link'               => $this->link(),
            'payment_request_id' => $this->request->id,
            'reference'          => $this->request->reference,
            'created_at'         => now()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->body())
            ->action(__('payments.notif.view'), rtrim((string) config('app.frontend_url'), '/') . $this->link());
    }

    private function key(): string
    {
        $status = $this->request->status === PaymentRequest::STATUS_APPROVED ? 'approved' : 'rejected';
        return "payments.notif.{$this->request->type}.{$status}";
    }

    private function params(): array
    {
        $r = $this->request;
        return [
            'reference' => $r->reference,
            'amount'    => Money::plain($r->amount_received ?? $r->amount),
            'plan'      => $r->requested_plan ? SubscriptionPlan::forSlug($r->requested_plan)->name : '',
            'end'       => $this->cycleEnd(),
            'reason'    => (string) $r->rejection_reason,
        ];
    }

    private function cycleEnd(): string
    {
        if ($this->request->isTopUp()) {
            return '';
        }
        $end = SellerSubscription::where('user_id', $this->request->seller_id)->latest('id')->first()?->billing_cycle_end;
        return $end ? $end->translatedFormat('j F Y') : '—';
    }

    private function title(): string
    {
        return __($this->key() . '.title', $this->params());
    }

    private function body(): string
    {
        return __($this->key() . '.body', $this->params());
    }

    private function link(): string
    {
        return $this->request->isTopUp() ? '/seller/promote/wallet' : '/seller/subscription';
    }
}
