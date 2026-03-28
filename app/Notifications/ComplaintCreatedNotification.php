<?php

namespace App\Notifications;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ComplaintCreatedNotification
 *
 * Sent to:
 *   - The seller of the product
 *   - All admins
 *
 * Channels: database (in-app) + mail
 *
 * How to send:
 *   $seller->notify(new ComplaintCreatedNotification($complaint, $client));
 *   Notification::send($admins, new ComplaintCreatedNotification($complaint, $client));
 */
class ComplaintCreatedNotification extends Notification
{
    use Queueable;

    /** @var Complaint */
    private $complaint;

    /** @var User */
    private $client;

    public function __construct(Complaint $complaint, User $client)
    {
        $this->complaint = $complaint;
        $this->client    = $client;
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    // ── In-app (database) notification ────────────────────────────────────

    public function toDatabase($notifiable): array
    {
        $isAdmin   = $notifiable->role === 'admin';
        $typeLabel = $this->complaint->getTypeLabel();

        return [
            // ── Fields read by NotificationBell.tsx ──────────────────────
            'title'  => 'New Complaint Filed',
            'body'   => "From {$this->client->name} — {$typeLabel}",
            'icon'   => 'x-circle',
            'action' => 'created',
            'link'   => $isAdmin
                ? "/complaints/{$this->complaint->id}"
                : "/seller/complaints/{$this->complaint->id}",
            // ── Extra context fields ──────────────────────────────────────
            'type'           => 'complaint_created',
            'complaint_id'   => $this->complaint->id,
            'order_id'       => $this->complaint->order_id,
            'client_name'    => $this->client->name,
            'client_email'   => $this->client->email,
            'complaint_type' => $this->complaint->complaint_type,
            'type_label'     => $typeLabel,
            'status'         => $this->complaint->status,
            'message'        => "New complaint from {$this->client->name} — {$typeLabel}",
            'created_at'     => now()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    // ── Email notification ─────────────────────────────────────────────────

    public function toMail($notifiable): MailMessage
    {
        $isAdmin = $notifiable->role === 'admin';
        $label   = $this->complaint->getTypeLabel();

        $orderNumber = isset($this->complaint->order)
            ? $this->complaint->order->order_number
            : $this->complaint->order_id;

        return (new MailMessage)
            ->subject("New Complaint Filed — {$label}")
            ->greeting("Hello {$notifiable->name},")
            ->line(
                $isAdmin
                    ? "A new complaint has been filed by **{$this->client->name}** ({$this->client->email})."
                    : "A complaint has been filed about one of your products by a customer."
            )
            ->line("**Type:** {$label}")
            ->line("**Order:** #{$orderNumber}")
            ->line("**Description:** {$this->complaint->description}")
            ->action(
                $isAdmin ? 'Review Complaint (Admin Panel)' : 'View Complaint',
                $isAdmin
                    ? url("/admin/complaints/{$this->complaint->id}")
                    : url("/seller/complaints/{$this->complaint->id}")
            )
            ->line('Please review and respond to this complaint as soon as possible.');
    }
}