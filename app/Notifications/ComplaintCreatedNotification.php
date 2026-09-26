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
            'title'  => __('seller.notif.complaint_created.title'),
            'body'   => __('seller.notif.complaint_created.body', ['client' => $this->client->name, 'type' => $typeLabel]),
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
            'message'        => __('seller.notif.complaint_created.message', ['client' => $this->client->name, 'type' => $typeLabel]),
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
            ->subject(__('seller.notif.complaint_created.subject', ['type' => $label]))
            ->greeting(__('seller.notif.complaint_created.greeting', ['name' => $notifiable->name]))
            ->line(
                $isAdmin
                    ? __('seller.notif.complaint_created.line_admin', ['client' => $this->client->name, 'email' => $this->client->email])
                    : __('seller.notif.complaint_created.line_seller')
            )
            ->line(__('seller.notif.complaint_created.type', ['type' => $label]))
            ->line(__('seller.notif.complaint_created.order', ['order' => $orderNumber]))
            ->line(__('seller.notif.complaint_created.description', ['description' => $this->complaint->description]))
            ->action(
                $isAdmin ? __('seller.notif.complaint_created.action_admin') : __('seller.notif.complaint_created.action_seller'),
                $isAdmin
                    ? url("/admin/complaints/{$this->complaint->id}")
                    : url("/seller/complaints/{$this->complaint->id}")
            )
            ->line(__('seller.notif.complaint_created.outro'));
    }
}