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

    /** @var string name shown to the seller / admin ("Guest" suffix for guest returns) */
    private $clientName;

    /** @var string|null account e-mail, or the guest's phone (admins only) */
    private $clientContact;

    /** @var string|null */
    private $clientEmail;

    /** $client null: a guest's return (no account) — name and phone come from the order. */
    public function __construct(Complaint $complaint, ?User $client = null)
    {
        $this->complaint = $complaint;
        if ($client) {
            $this->clientName    = (string) $client->name;
            $this->clientEmail   = $client->email;
            $this->clientContact = $client->email;
        } else {
            $buyer = $complaint->loadMissing('order')->buyerInfo();
            $this->clientName    = trim(($buyer['name'] ?: '—') . ' (' . __('seller.notif.complaint_created.guest') . ')');
            $this->clientEmail   = null;
            $this->clientContact = $buyer['phone'];
        }
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
            'body'   => __('seller.notif.complaint_created.body', ['client' => $this->clientName, 'type' => $typeLabel]),
            'icon'   => 'x-circle',
            'action' => 'created',
            'link'   => $isAdmin
                ? '/complaints'
                : '/seller/complaints?id=' . $this->complaint->id,
            // ── Extra context fields ──────────────────────────────────────
            'type'           => 'complaint_created',
            'complaint_id'   => $this->complaint->id,
            'order_id'       => $this->complaint->order_id,
            'client_name'    => $this->clientName,
            'client_email'   => $this->clientEmail,
            'is_guest'       => $this->complaint->isGuest(),
            'complaint_type' => $this->complaint->complaint_type,
            'type_label'     => $typeLabel,
            'status'         => $this->complaint->status,
            'message'        => __('seller.notif.complaint_created.message', ['client' => $this->clientName, 'type' => $typeLabel]),
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
                    ? __('seller.notif.complaint_created.line_admin', ['client' => $this->clientName, 'email' => $this->clientContact ?? '—'])
                    : __('seller.notif.complaint_created.line_seller')
            )
            ->line(__('seller.notif.complaint_created.type', ['type' => $label]))
            ->line(__('seller.notif.complaint_created.order', ['order' => $orderNumber]))
            ->line(__('seller.notif.complaint_created.items', ['items' => implode(' · ', $this->complaint->itemSummaries()) ?: '—']))
            ->line(__('seller.notif.complaint_created.description', ['description' => $this->complaint->description]))
            ->action(
                $isAdmin ? __('seller.notif.complaint_created.action_admin') : __('seller.notif.complaint_created.action_seller'),
                $isAdmin
                    ? admin_url("/complaints?id={$this->complaint->id}")
                    : frontend_url() . '/seller/complaints?id=' . $this->complaint->id
            )
            ->line(__('seller.notif.complaint_created.outro'));
    }
}