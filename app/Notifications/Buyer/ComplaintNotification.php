<?php

namespace App\Notifications\Buyer;

use App\Models\Complaint;
use App\Notifications\Buyer\Concerns\DescribesOrder;

/**
 * The client's return, step by step (bell + e-mail):
 *
 *   received          return requested (acknowledgement)
 *   seller_replied    the shop left a note (legacy, kept for old rows)
 *   seller_accepted   the shop accepted, the platform validates
 *   seller_rejected   the shop refused, with its reason — the client may escalate
 *   escalated         the client asked the platform to decide
 *   approved          platform approved: a courier will collect the item
 *   pickup_scheduled  pick-up planned
 *   picked_up         courier collected the item
 *   returned          item back at the shop and inspected
 *   refunded          money sent: amount, method, reference
 *   rejected          refused, with the reason
 *   cancelled         cancelled (by the client or the platform)
 *
 * Exchanges use exchange_{event} texts when they exist (received, approved…), plus:
 *   exchange_in_transit  replacement on its way: cash to hand the courier, tracking
 *   exchanged            replacement delivered, old item taken back
 *   exchange_failed      not delivered (refused / absent): our team decides next
 *   exchange_completed   the shop got the old item back: case closed
 */
class ComplaintNotification extends BuyerNotification
{
    use DescribesOrder;

    public const EVENTS = [
        'received', 'seller_replied', 'seller_accepted', 'seller_rejected', 'escalated', 'approved',
        'pickup_scheduled', 'picked_up', 'returned', 'refunded', 'rejected', 'cancelled',
        'exchange_in_transit', 'exchanged', 'exchange_failed', 'exchange_completed',
    ];

    public function __construct(public Complaint $complaint, public string $event)
    {
        parent::__construct();
    }

    public function category(): string { return 'complaints'; }

    public function dedupeKey(): ?string
    {
        $key = "complaint:{$this->complaint->id}:{$this->event}";
        // A shop may answer several times: one message per distinct note.
        // A retried exchange is a new parcel: one message per parcel.
        return match ($this->event) {
            'seller_replied'      => $key . ':' . md5((string) $this->complaint->seller_note),
            'exchange_in_transit', 'exchange_failed' => $key . ':' . $this->complaint->intigo_exchange_nid,
            default               => $key,
        };
    }

    protected function type(): string { return "complaint_{$this->event}"; }

    /** Text key: the exchange wording of a shared step when there is one. */
    private function textKey(): string
    {
        if ($this->complaint->isExchange() && !str_starts_with($this->event, 'exchange')
            && \Illuminate\Support\Facades\Lang::has("buyer_notifications.complaint.exchange_{$this->event}.title")) {
            return "exchange_{$this->event}";
        }
        return $this->event;
    }

    /** The order's return tracking (storefront). */
    protected function link(): ?string
    {
        return '/complaints?id=' . $this->complaint->id;
    }

    protected function icon(): string
    {
        return match ($this->event) {
            'approved', 'returned', 'refunded', 'seller_accepted', 'exchanged', 'exchange_completed' => 'check-circle',
            'rejected', 'seller_rejected', 'cancelled', 'exchange_failed'                            => 'x-circle',
            'pickup_scheduled', 'picked_up', 'exchange_in_transit'                                   => 'truck',
            'seller_replied'                                                                         => 'message-circle',
            default                                                                                  => 'alert-circle',
        };
    }

    protected function action(): string
    {
        return match ($this->event) {
            'approved', 'returned', 'refunded', 'seller_accepted', 'exchanged', 'exchange_completed' => 'approved',
            'rejected', 'seller_rejected', 'cancelled', 'exchange_failed'                            => 'rejected',
            default                                                                                  => 'pending',
        };
    }

    protected function title(): string
    {
        return __("buyer_notifications.complaint.{$this->textKey()}.title", $this->params());
    }

    protected function body(): string
    {
        return __($this->variant('body'), $this->params());
    }

    /** Cash-on-delivery orders: the courier pays the client back at pick-up (…_cash texts). */
    private function variant(string $field): string
    {
        $key = "buyer_notifications.complaint.{$this->textKey()}.{$field}";
        if ($this->complaint->isExchange()) {
            // Exchange: "…_free" when the client pays nothing (the shop was at fault)
            return $this->complaint->clientFee() <= 0 && \Illuminate\Support\Facades\Lang::has("{$key}_free") ? "{$key}_free" : $key;
        }
        $cod = ($this->complaint->order?->payment_method ?? 'cod') === 'cod';
        return $cod && \Illuminate\Support\Facades\Lang::has("{$key}_cash") ? "{$key}_cash" : $key;
    }

    protected function data(): array
    {
        return [
            'complaint_id'     => $this->complaint->id,
            'reference'        => $this->complaint->reference,
            'order_id'         => $this->complaint->order_id,
            'order_number'     => $this->complaint->order?->order_number,
            'status'           => $this->complaint->status,
            'rejection_reason' => in_array($this->event, ['rejected', 'seller_rejected'], true) ? $this->complaint->rejection_reason : null,
            'refund_amount'    => $this->event === 'refunded' ? (float) $this->complaint->refund_amount : null,
            'refund_method'    => $this->event === 'refunded' ? $this->complaint->refund_method : null,
            'refund_reference' => $this->event === 'refunded' ? $this->complaint->refund_reference : null,
        ];
    }

    protected function mailSubject(): string
    {
        return __("buyer_notifications.complaint.{$this->textKey()}.subject", $this->params());
    }

    protected function mailButton(): string
    {
        return __('buyer_notifications.complaint.button');
    }

    protected function mailLines(): array
    {
        return [__($this->variant('line'), $this->params())];
    }

    /** Returned items as bought (name — variant × qty), plus the refund on "refunded". */
    protected function mailTable(): array
    {
        $rows = array_map(fn($line) => [$line, ''], $this->complaint->itemSummaries());
        if ($this->complaint->isExchange() && in_array($this->event, ['received', 'approved', 'exchange_in_transit'], true)) {
            foreach ($this->complaint->items()->whereNotNull('replacement_label')->get() as $item) {
                $rows[] = [__('buyer_notifications.complaint.replacement'), $item->replacement_label . ' × ' . $item->quantity];
            }
            $rows[] = [__('buyer_notifications.complaint.to_pay_courier'), self::money($this->complaint->clientFee())];
            if ($this->complaint->intigo_exchange_nid) {
                $rows[] = [__('buyer_notifications.complaint.tracking'), $this->complaint->intigo_exchange_nid];
            }
        }
        if ($this->event === 'refunded') {
            $rows[] = [__('buyer_notifications.complaint.refund_amount'), self::money($this->complaint->refund_amount)];
            $rows[] = [__('buyer_notifications.complaint.refund_method'), __('buyer_notifications.complaint.methods.' . $this->complaint->refund_method)];
            if ($this->complaint->refund_reference) {
                $rows[] = [__('buyer_notifications.complaint.refund_reference'), $this->complaint->refund_reference];
            }
        }
        return $rows;
    }

    private function params(): array
    {
        $complaint = $this->complaint->loadMissing(['order', 'seller:id,name']);

        return [
            'ref'       => $complaint->order ? $this->ref($complaint->order) : '#' . $complaint->order_id,
            'return'    => (string) ($complaint->reference ?: '#' . $complaint->id),
            'shop'      => $this->shopNames(collect([(object) ['seller_id' => $complaint->seller_id, 'seller' => $complaint->seller]]))[0]
                           ?? config('app.name'),
            'reason'    => (string) $complaint->rejection_reason,
            'note'      => \Illuminate\Support\Str::limit((string) $complaint->seller_note, 200),
            'amount'    => self::money($complaint->refund_amount),
            'method'    => $complaint->refund_method ? __('buyer_notifications.complaint.methods.' . $complaint->refund_method) : '',
            'reference' => (string) $complaint->refund_reference,
            'days'      => Complaint::ESCALATION_WINDOW_DAYS,
            'fee'       => self::money($complaint->clientFee()),
            'tracking'  => (string) $complaint->intigo_exchange_nid,
        ];
    }
}
