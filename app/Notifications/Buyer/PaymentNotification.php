<?php

namespace App\Notifications\Buyer;

use App\Models\Order;
use App\Notifications\Buyer\Concerns\DescribesOrder;

/**
 * Online payment of an order:
 *   pending  D17: the order waits for the buyer's payment
 *   failed   card payment refused (Stripe payment_intent.payment_failed)
 */
class PaymentNotification extends BuyerNotification
{
    use DescribesOrder;

    public function __construct(public Order $order, public string $event)
    {
        parent::__construct();
    }

    public function category(): string { return 'payments'; }
    public function dedupeKey(): ?string { return "order:{$this->order->id}:payment_{$this->event}"; }

    protected function type(): string { return "payment_{$this->event}"; }
    protected function icon(): string { return $this->event === 'failed' ? 'alert-triangle' : 'credit-card'; }
    protected function action(): string { return $this->event === 'failed' ? 'rejected' : 'pending'; }
    protected function link(): ?string { return $this->orderLink($this->order); }

    protected function title(): string
    {
        return __("buyer_notifications.payment.{$this->event}.title", ['ref' => $this->ref($this->order)]);
    }

    protected function body(): string
    {
        return __("buyer_notifications.payment.{$this->event}.body", [
            'total'  => self::money($this->order->total_amount),
            'method' => __('buyer_notifications.payment.methods.' . ($this->order->payment_method ?? 'cod')),
        ]);
    }

    protected function data(): array
    {
        return [
            'order_id'       => $this->order->id,
            'order_number'   => $this->order->order_number,
            'total'          => (float) $this->order->total_amount,
            'payment_method' => $this->order->payment_method,
        ];
    }

    protected function mailSubject(): string
    {
        return __("buyer_notifications.payment.{$this->event}.subject", ['ref' => $this->ref($this->order)]);
    }

    protected function mailButton(): string
    {
        return __('buyer_notifications.payment.failed.button');
    }

    protected function mailLines(): array
    {
        return [__("buyer_notifications.payment.{$this->event}.line", ['minutes' => 30])];
    }
}
