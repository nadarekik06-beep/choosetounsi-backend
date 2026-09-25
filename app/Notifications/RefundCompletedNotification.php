<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\RefundDeliveryTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification: RefundCompletedNotification
 *
 * Sent to the customer when the delivery guy marks the refund as completed.
 * The customer sees this in their storefront notification bell and receives
 * an email confirming the refund process is done.
 *
 * Implements ShouldQueue so it doesn't block the refund completion response.
 */
class RefundCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public RefundDeliveryTask $task;
    public Order $order;

    public function __construct(RefundDeliveryTask $task, Order $order)
    {
        $this->task  = $task;
        $this->order = $order;
    }

    /**
     * Deliver via database (in-app bell) and email.
     */
    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    // ── In-app notification ────────────────────────────────────────────────

    public function toArray($notifiable): array
    {
        return [
            'type'          => 'refund_completed',
            'title'         => __('notifications.refund_completed.title'),
            'message'       => __('notifications.refund_completed.message', ['order' => $this->order->order_number]),
            'order_id'      => $this->order->id,
            'order_number'  => $this->order->order_number,
            'complaint_id'  => $this->task->complaint_id,
            'task_id'       => $this->task->id,
        ];
    }

    // ── Email notification ─────────────────────────────────────────────────

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.refund_completed.subject', ['order' => $this->order->order_number]))
            ->greeting(__('notifications.mail.greeting', ['name' => $notifiable->name]))
            ->line(__('notifications.refund_completed.line1', ['order' => $this->order->order_number]))
            ->line(__('notifications.refund_completed.line2'))
            ->line(__('notifications.refund_completed.line3'))
            ->action(__('notifications.refund_completed.action'), url('/orders'))
            ->line(__('notifications.refund_completed.thanks'));
    }
}