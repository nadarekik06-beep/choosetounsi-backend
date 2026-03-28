<?php

namespace App\Notifications;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FILE: app/Notifications/SellerRejectedComplaintNotification.php  ← NEW FILE
 *
 * Sent to ALL ADMINS when a seller rejects a complaint.
 * Admin must validate: confirm rejection OR override to approved.
 *
 * Usage:
 *   Notification::send($admins, new SellerRejectedComplaintNotification($complaint, $seller));
 */
class SellerRejectedComplaintNotification extends Notification
{
    use Queueable;

    /** @var Complaint */
    private $complaint;

    /** @var User */
    private $seller;

    public function __construct(Complaint $complaint, User $seller)
    {
        $this->complaint = $complaint;
        $this->seller    = $seller;
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    // ── In-app notification (matches NotificationBell field names) ─────────

    public function toDatabase($notifiable): array
    {
        return [
            // Fields read by NotificationBell.tsx
            'title'  => '⚠️ Seller Rejected a Complaint',
            'body'   => "Seller {$this->seller->name} rejected complaint #{$this->complaint->id}. Your decision is required.",
            'icon'   => 'x-circle',
            'action' => 'rejected',
            'link'   => "/complaints/{$this->complaint->id}",
            // Extra context
            'type'           => 'seller_complaint_rejected',
            'complaint_id'   => $this->complaint->id,
            'order_id'       => $this->complaint->order_id,
            'seller_name'    => $this->seller->name,
            'seller_email'   => $this->seller->email,
            'complaint_type' => $this->complaint->complaint_type,
            'type_label'     => $this->complaint->getTypeLabel(),
            'status'         => $this->complaint->status,
            'message'        => "Seller {$this->seller->name} rejected complaint #{$this->complaint->id}. Admin action required.",
            'created_at'     => now()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    // ── Email ──────────────────────────────────────────────────────────────

    public function toMail($notifiable): MailMessage
    {
        $orderNumber = isset($this->complaint->order)
            ? $this->complaint->order->order_number
            : "#{$this->complaint->order_id}";

        return (new MailMessage)
            ->subject("⚠️ Admin Action Required — Seller Rejected Complaint #{$this->complaint->id}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Seller **{$this->seller->name}** has rejected a complaint and your final decision is required.")
            ->line("**Complaint ID:** #{$this->complaint->id}")
            ->line("**Order:** {$orderNumber}")
            ->line("**Type:** {$this->complaint->getTypeLabel()}")
            ->line("**Seller's Reason:** {$this->complaint->rejection_reason}")
            ->line("**Seller's Note:** {$this->complaint->seller_note}")
            ->action('Review & Decide', url("/admin/complaints/{$this->complaint->id}"))
            ->line('Please review the complaint and make the final decision (confirm rejection or override to approved).');
    }
}