<?php

namespace App\Notifications\Buyer;

use App\Models\Complaint;
use App\Models\Order;
use App\Notifications\Buyer\Concerns\DescribesOrder;
use App\Services\Orders\DeliveryDocumentService;

/**
 * A step of the buyer's sub-order(s), named after the shop(s):
 *
 *   confirmed  admin confirmed the order (sub-order 'confirmed')
 *   packed     the shop packed it, waiting for the courier ('completed')
 *   shipped    handed to the courier ('out_for_delivery')
 *   delivered  'delivered'
 *   cancelled  'cancelled' — reason: seller | admin | payment
 *
 * Sub-orders of one order that reach the same step in the same action are
 * grouped into one notification. Sent through BuyerOrderNotifier only.
 */
class OrderStatusNotification extends BuyerNotification
{
    use DescribesOrder;

    public const EVENTS = ['confirmed', 'packed', 'shipped', 'delivered', 'cancelled'];

    /** @param int[] $sellerOrderIds */
    public function __construct(
        public Order $order,
        public string $event,
        public array $sellerOrderIds,
        public ?string $reason = null,
        public ?string $courier = null,
    ) {
        parent::__construct();
    }

    public function category(): string { return 'orders'; }

    protected function type(): string { return "order_{$this->event}"; }
    protected function link(): ?string { return $this->orderLink($this->order); }

    protected function icon(): string
    {
        return match ($this->event) {
            'confirmed' => 'check-circle',
            'packed'    => 'package',
            'shipped'   => 'truck',
            'delivered' => 'package-check',
            'cancelled' => 'x-circle',
            default     => 'shopping-bag',
        };
    }

    protected function action(): string
    {
        return match ($this->event) {
            'cancelled' => 'cancelled',
            'delivered', 'confirmed' => 'approved',
            default => 'order',
        };
    }

    protected function title(): string
    {
        return __("buyer_notifications.order.{$this->event}.title", $this->params());
    }

    protected function body(): string
    {
        $key = "buyer_notifications.order.{$this->event}.body";

        if ($this->event === 'shipped' && $this->courier) {
            $key .= '_courier';
        } elseif ($this->event === 'cancelled') {
            $key .= match ($this->reason) {
                'seller'  => '_seller',
                'payment' => '_payment',
                default   => '_admin',
            };
        }

        return __($key, $this->params());
    }

    protected function data(): array
    {
        return [
            'order_id'         => $this->order->id,
            'order_number'     => $this->order->order_number,
            'seller_order_ids' => array_values($this->sellerOrderIds),
            'shops'            => $this->shops(),
            'event'            => $this->event,
            'reason'           => $this->reason,
            'courier'          => $this->courier,
        ];
    }

    protected function mailSubject(): string
    {
        return __("buyer_notifications.order.{$this->event}.subject", $this->params());
    }

    protected function mailButton(): string
    {
        return __($this->event === 'cancelled'
            ? 'buyer_notifications.order.cancelled.button'
            : 'buyer_notifications.order.placed.button');
    }

    protected function mailLines(): array
    {
        $order = $this->order;

        return match ($this->event) {
            'confirmed', 'packed' => [__("buyer_notifications.order.{$this->event}.line")],
            'shipped' => array_values(array_filter([
                __('buyer_notifications.order.shipped.line'),
                $this->codAmount() > 0
                    ? __('buyer_notifications.order.shipped.line_cod', ['amount' => self::money($this->codAmount())])
                    : null,
            ])),
            'delivered' => [__('buyer_notifications.order.delivered.line', ['hours' => Complaint::COMPLAINT_WINDOW_HOURS])],
            'cancelled' => [
                $this->wasPaid()
                    ? __('buyer_notifications.order.cancelled.line_paid', ['amount' => self::money($this->cancelledAmount())])
                    : __('buyer_notifications.order.cancelled.line_unpaid'),
                __('buyer_notifications.order.cancelled.line_help'),
            ],
            default => [],
        };
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private ?array $shopsCache = null;

    private function shops(): array
    {
        return $this->shopsCache ??= $this->shopNames($this->sellerOrdersOf($this->order, $this->sellerOrderIds));
    }

    private function params(): array
    {
        return [
            'ref'     => $this->ref($this->order),
            'shops'   => self::joinNames($this->shops()) ?: config('app.name'),
            'courier' => $this->courier,
        ];
    }

    private function wasPaid(): bool
    {
        return $this->order->payment_status === 'paid'
            || ($this->order->payment_method === 'wallet' && $this->order->payment_status !== 'refunded');
    }

    /** What these sub-orders cost the buyer (items − discount), shipping excluded. */
    private function cancelledAmount(): float
    {
        return (float) $this->sellerOrdersOf($this->order, $this->sellerOrderIds)
            ->sum(fn ($so) => (float) $so->subtotal - (float) ($so->discount_amount ?? 0));
    }

    /** Cash the courier collects for these sub-orders (as printed on the delivery slips). */
    private function codAmount(): float
    {
        $documents = app(DeliveryDocumentService::class);
        $order     = $this->order->loadMissing('sellerOrders');

        return (float) $order->sellerOrders
            ->whereIn('id', $this->sellerOrderIds)
            ->sum(fn ($so) => $documents->money($order, $so)['cod']);
    }
}
