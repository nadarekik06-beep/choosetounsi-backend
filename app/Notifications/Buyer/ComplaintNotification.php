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
 */
class ComplaintNotification extends BuyerNotification
{
    use DescribesOrder;

    public const EVENTS = [
        'received', 'seller_replied', 'seller_accepted', 'seller_rejected', 'escalated', 'approved',
        'pickup_scheduled', 'picked_up', 'returned', 'refunded', 'rejected', 'cancelled',
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
        return $this->event === 'seller_replied'
            ? $key . ':' . md5((string) $this->complaint->seller_note)
            : $key;
    }

    protected function type(): string { return "complaint_{$this->event}"; }

    /** The order's return tracking (storefront). */
    protected function link(): ?string
    {
        return '/complaints?id=' . $this->complaint->id;
    }

    protected function icon(): string
    {
        return match ($this->event) {
            'approved', 'returned', 'refunded', 'seller_accepted' => 'check-circle',
            'rejected', 'seller_rejected', 'cancelled'             => 'x-circle',
            'pickup_scheduled', 'picked_up'                        => 'truck',
            'seller_replied'                                       => 'message-circle',
            default                                                => 'alert-circle',
        };
    }

    protected function action(): string
    {
        return match ($this->event) {
            'approved', 'returned', 'refunded', 'seller_accepted' => 'approved',
            'rejected', 'seller_rejected', 'cancelled'             => 'rejected',
            default                                                => 'pending',
        };
    }

    protected function title(): string
    {
        return __("buyer_notifications.complaint.{$this->event}.title", $this->params());
    }

    protected function body(): string
    {
        return __("buyer_notifications.complaint.{$this->event}.body", $this->params());
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
        return __("buyer_notifications.complaint.{$this->event}.subject", $this->params());
    }

    protected function mailButton(): string
    {
        return __('buyer_notifications.complaint.button');
    }

    protected function mailLines(): array
    {
        return [__("buyer_notifications.complaint.{$this->event}.line", $this->params())];
    }

    /** Returned items as bought (name — variant × qty), plus the refund on "refunded". */
    protected function mailTable(): array
    {
        $rows = array_map(fn($line) => [$line, ''], $this->complaint->itemSummaries());
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
        ];
    }
}
