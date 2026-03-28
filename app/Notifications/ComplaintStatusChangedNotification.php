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
                ? 'Complaint Approved ✅'
                : 'Complaint Rejected ❌',
            'body'   => $isApproved
                ? 'Your complaint has been approved. We will contact you about next steps.'
                : "Your complaint was not approved. Reason: {$this->complaint->rejection_reason}",
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
                ? 'Your complaint has been approved. Please check your email for next steps.'
                : "Your complaint was not approved. Reason: {$this->complaint->rejection_reason}",
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
                ? "✅ Your Complaint Has Been Approved — Order {$orderNumber}"
                : "❌ Update on Your Complaint — Order {$orderNumber}"
            )
            ->greeting("Hello {$notifiable->name},");

        if ($isApproved) {
            $mail
                ->line("Great news! Your complaint for order **{$orderNumber}** has been **approved**.")
                ->line("Our team will contact you shortly regarding the next steps (refund or replacement).")
                ->action('View My Complaints', url('/orders'));
        } else {
            $mail
                ->line("We have reviewed your complaint for order **{$orderNumber}**.")
                ->line("Unfortunately, after careful review, your complaint could not be approved.")
                ->line("**Reason:** {$this->complaint->rejection_reason}")
                ->line("If you believe this is incorrect, please contact our support team.")
                ->action('Contact Support', url('/support'));
        }

        return $mail->line("Thank you for shopping with ChooseTounsi.");
    }
}