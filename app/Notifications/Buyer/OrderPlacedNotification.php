<?php

namespace App\Notifications\Buyer;

use App\Models\Order;
use App\Notifications\Buyer\Concerns\DescribesOrder;

/**
 * Order placed: the buyer's receipt (bell + e-mail with the items and total).
 * COD / wallet right away, card / D17 once paid (BuyerOrderNotifier).
 */
class OrderPlacedNotification extends BuyerNotification
{
    use DescribesOrder;

    public function __construct(public Order $order)
    {
        parent::__construct();
    }

    public function category(): string { return 'orders'; }
    public function dedupeKey(): ?string { return "order:{$this->order->id}:placed"; }

    protected function type(): string { return 'order_placed'; }
    protected function icon(): string { return 'shopping-bag'; }
    protected function action(): string { return 'order'; }
    protected function link(): ?string { return $this->orderLink($this->order); }

    protected function title(): string
    {
        return __('buyer_notifications.order.placed.title', ['ref' => $this->ref($this->order)]);
    }

    protected function body(): string
    {
        $count = $this->itemCount($this->order);
        return trans_choice('buyer_notifications.order.placed.body', max(1, $count), [
            'count' => $count,
            'total' => self::money($this->order->total_amount),
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
        return __('buyer_notifications.order.placed.subject', ['ref' => $this->ref($this->order)]);
    }

    protected function mailButton(): string
    {
        return __('buyer_notifications.order.placed.button');
    }

    protected function mailLines(): array
    {
        $order = $this->order;
        $total = self::money($order->total_amount);
        $lines = [];

        $lines[] = $order->payment_method === 'cod'
            ? __('buyer_notifications.order.placed.line_cod', ['total' => $total])
            : __('buyer_notifications.order.placed.line_paid', ['total' => $total]);

        $shops = $this->shopNames($this->sellerOrdersOf($order));
        if (count($shops) > 1) {
            $lines[] = __('buyer_notifications.order.placed.line_shops', ['shops' => self::joinNames($shops)]);
        }

        $address = $order->formattedShippingAddress();
        if ($address !== '') {
            $lines[] = __('buyer_notifications.order.placed.line_address', ['address' => $address]);
        }

        return $lines;
    }

    protected function mailTable(): array
    {
        $order = $this->order;
        $rows  = [];

        foreach ($order->items()->get(['product_name', 'variant_label', 'quantity', 'total']) as $item) {
            // "|" would break the Markdown table
            $name = str_replace('|', '/', trim($item->product_name . ($item->variant_label ? " ({$item->variant_label})" : '')));
            $rows[] = ["{$item->quantity} × {$name}", (float) $item->total > 0 ? self::money($item->total) : '—'];
        }

        $money = $order->moneySummary();
        if ($money['discount_amount'] > 0) {
            $rows[] = [__('buyer_notifications.order.placed.discount'), '-' . self::money($money['discount_amount'])];
        }
        $rows[] = [
            __('buyer_notifications.order.placed.shipping'),
            $money['shipping_fee'] > 0 ? self::money($money['shipping_fee']) : __('buyer_notifications.order.placed.shipping_free'),
        ];
        $rows[] = ['**' . __('buyer_notifications.order.placed.total') . '**', '**' . self::money($order->total_amount) . '**'];

        return $rows;
    }
}
