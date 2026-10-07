<?php

namespace App\Notifications\Buyer;

use App\Models\Complaint;
use App\Notifications\Buyer\Concerns\DescribesOrder;

/**
 * The buyer's complaint / return, step by step:
 *
 *   received        complaint opened (acknowledgement)
 *   seller_replied  the shop answered and is examining it
 *   escalated       the shop contested it: the admin decides
 *   approved        accepted (return_refund: a courier picks the item up, then refund)
 *   rejected        refused, with the reason
 */
class ComplaintNotification extends BuyerNotification
{
    use DescribesOrder;

    public const EVENTS = ['received', 'seller_replied', 'escalated', 'approved', 'rejected'];

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
    protected function link(): ?string { return '/complaints?id=' . $this->complaint->id; }

    protected function icon(): string
    {
        return match ($this->event) {
            'approved' => 'check-circle',
            'rejected' => 'x-circle',
            'seller_replied' => 'message-circle',
            default => 'alert-circle',
        };
    }

    protected function action(): string
    {
        return match ($this->event) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            default    => 'pending',
        };
    }

    protected function title(): string
    {
        return __("buyer_notifications.complaint.{$this->event}.title", $this->params());
    }

    protected function body(): string
    {
        $key = "buyer_notifications.complaint.{$this->event}.body";
        if (in_array($this->event, ['received', 'approved'], true) && $this->isRefund()) {
            $key .= '_refund';
        }
        return __($key, $this->params());
    }

    protected function data(): array
    {
        return [
            'complaint_id'     => $this->complaint->id,
            'order_id'         => $this->complaint->order_id,
            'order_number'     => $this->complaint->order?->order_number,
            'status'           => $this->complaint->status,
            'resolution_type'  => $this->complaint->resolution_type,
            'rejection_reason' => $this->event === 'rejected' ? $this->complaint->rejection_reason : null,
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

    /** The complained items as bought (name — variant × qty). */
    protected function mailTable(): array
    {
        return array_map(fn($line) => [$line, ''], $this->complaint->itemSummaries());
    }

    private function isRefund(): bool
    {
        return $this->complaint->resolution_type === Complaint::RESOLUTION_RETURN_REFUND;
    }

    private function params(): array
    {
        $complaint = $this->complaint->loadMissing(['order', 'seller:id,name']);

        return [
            'ref'    => $complaint->order ? $this->ref($complaint->order) : '#' . $complaint->order_id,
            'shop'   => $this->shopNames(collect([(object) ['seller_id' => $complaint->seller_id, 'seller' => $complaint->seller]]))[0]
                        ?? config('app.name'),
            'reason' => (string) $complaint->rejection_reason,
            'note'   => \Illuminate\Support\Str::limit((string) $complaint->seller_note, 200),
        ];
    }
}
