<?php

namespace App\Notifications\Buyer;

use App\Models\Order;
use App\Models\RefundDeliveryTask;
use App\Notifications\Buyer\Concerns\DescribesOrder;

/**
 * Return pick-up of an approved complaint (refund_delivery_tasks):
 *   picked_up  the courier collected the item
 *   completed  refund done (or exchange done, for an exchange complaint)
 */
class RefundNotification extends BuyerNotification
{
    use DescribesOrder;

    public function __construct(public RefundDeliveryTask $task, public Order $order, public string $event)
    {
        parent::__construct();
    }

    public function category(): string { return 'payments'; }
    public function dedupeKey(): ?string { return "refund_task:{$this->task->id}:{$this->event}"; }

    protected function type(): string { return ($this->isExchange() ? 'exchange_' : 'refund_') . $this->event; }
    protected function icon(): string { return $this->event === 'completed' ? 'check-circle' : 'truck'; }
    protected function action(): string { return $this->event === 'completed' ? 'approved' : 'pending'; }
    protected function link(): ?string { return $this->orderLink($this->order); }

    protected function title(): string { return __($this->key('title'), $this->params()); }
    protected function body(): string { return __($this->key('body'), $this->params()); }

    protected function data(): array
    {
        return [
            'order_id'     => $this->order->id,
            'order_number' => $this->order->order_number,
            'complaint_id' => $this->task->complaint_id,
            'task_id'      => $this->task->id,
        ];
    }

    protected function mailSubject(): string { return __($this->key('subject'), $this->params()); }
    protected function mailButton(): string { return __('buyer_notifications.refund.completed.button'); }

    protected function mailLines(): array
    {
        return $this->event === 'completed' ? [__($this->key('line'))] : [];
    }

    private function key(string $field): string
    {
        $event = ($this->isExchange() ? 'exchange_' : '') . $this->event;
        return "buyer_notifications.refund.{$event}.{$field}";
    }

    private function isExchange(): bool
    {
        return (bool) $this->task->complaint?->isExchange();
    }

    private function params(): array
    {
        return ['ref' => $this->ref($this->order)];
    }
}
