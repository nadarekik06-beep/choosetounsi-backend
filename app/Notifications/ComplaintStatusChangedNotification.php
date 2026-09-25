<?php

namespace App\Notifications;

use App\Models\Complaint;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ComplaintStatusChangedNotification
 *
 * Sent to the CLIENT when an admin approves or rejects their complaint.
 *
 * How to send:
 *   $complaint->user->notify(new ComplaintStatusChangedNotification($complaint));
 */
class ComplaintStatusChangedNotification extends Notification
{
    use Queueable;

    /** @var Complaint */
    private $complaint;

    public function __construct(Complaint $complaint)
    {
        $this->complaint = $complaint;
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    // ── In-app (database) notification ────────────────────────────────────

    public function toDatabase($notifiable): array
    {
        $isApproved = $this->complaint->isApproved();

        return [
            // ── Fields read by NotificationBell.tsx ──────────────────────
            'title'  => $isApproved
                ? __('notifications.complaint_approved.title')
                : __('notifications.complaint_rejected.title'),
            'body'   => $isApproved
                ? __('notifications.complaint_approved.body')
                : __('notifications.complaint_rejected.body', ['reason' => $this->complaint->rejection_reason]),
            'icon'   => $isApproved ? 'check-circle' : 'x-circle',
            'action' => $isApproved ? 'approved' : 'rejected',
            'link'   => '/complaints',
            // ── Extra context fields ──────────────────────────────────────
            'type'             => 'complaint_status_changed',
            'complaint_id'     => $this->complaint->id,
            'order_id'         => $this->complaint->order_id,
            'new_status'       => $this->complaint->status,
            'rejection_reason' => $this->complaint->rejection_reason,
            'message'          => $isApproved
                ? __('notifications.complaint_approved.message')
                : __('notifications.complaint_rejected.body', ['reason' => $this->complaint->rejection_reason]),
            'created_at'       => now()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    // ── Email notification ─────────────────────────────────────────────────

    public function toMail($notifiable): MailMessage
    {
        $isApproved  = $this->complaint->isApproved();
        $orderNumber = isset($this->complaint->order)
            ? $this->complaint->order->order_number
            : "#{$this->complaint->order_id}";

        $mail = (new MailMessage)
            ->subject($isApproved
                ? __('notifications.complaint_approved.subject', ['order' => $orderNumber])
                : __('notifications.complaint_rejected.subject', ['order' => $orderNumber])
            )
            ->greeting(__('notifications.mail.greeting', ['name' => $notifiable->name]));

        if ($isApproved) {
            $mail
                ->line(__('notifications.complaint_approved.line1', ['order' => $orderNumber]))
                ->line(__('notifications.complaint_approved.line2'))
                ->action(__('notifications.complaint_approved.action'), url('/orders'));
        } else {
            $mail
                ->line(__('notifications.complaint_rejected.line1', ['order' => $orderNumber]))
                ->line(__('notifications.complaint_rejected.line2'))
                ->line(__('notifications.complaint_rejected.reason', ['reason' => $this->complaint->rejection_reason]))
                ->line(__('notifications.complaint_rejected.line3'))
                ->action(__('notifications.complaint_rejected.action'), url('/support'));
        }

        return $mail->line(__('notifications.mail.thanks'));
    }
}