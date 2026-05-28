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
            'title'         => '✅ Your refund has been processed',
            'message'       => "The refund for order #{$this->order->order_number} has been completed. Your order status has been updated to Refunded.",
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
            ->subject("✅ Refund Completed — Order #{$this->order->order_number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("We are pleased to inform you that the refund for your order **#{$this->order->order_number}** has been successfully processed.")
            ->line("Our delivery agent has picked up the item and the return has been confirmed.")
            ->line("Your order status has been updated to **Refunded**.")
            ->action('View My Orders', url('/orders'))
            ->line("Thank you for shopping with Choose'Tounsi. We apologize for any inconvenience caused.");
    }
}