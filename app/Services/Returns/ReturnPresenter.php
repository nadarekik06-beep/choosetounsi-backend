<?php

namespace App\Services\Returns;

use App\Models\Complaint;

/**
 * What each audience sees of a return.
 *
 *   timeline  the canonical path, each step done / current / upcoming with its
 *             date — what the storefront "Return / Refund tracking" draws;
 *   events    the audit trail (admin & seller see all; the client only the
 *             public steps, never internal notes).
 */
class ReturnPresenter
{
    /** Main path a return follows (seller decision collapses into one step). */
    private const PATH = [
        Complaint::STATUS_REQUESTED,
        'seller_decision',
        Complaint::STATUS_ADMIN_APPROVED,
        Complaint::STATUS_PICKUP_SCHEDULED,
        Complaint::STATUS_PICKED_UP,
        Complaint::STATUS_RETURNED_TO_SELLER,
        Complaint::STATUS_REFUNDED,
    ];

    /** Notes the client may read on their timeline. */
    private const PUBLIC_NOTES = [Complaint::STATUS_SELLER_REJECTED, Complaint::STATUS_REJECTED, Complaint::STATUS_REQUESTED, Complaint::STATUS_ESCALATED];

    public static function forClient(Complaint $c): Complaint
    {
        $events = $c->relationLoaded('events') ? $c->events : $c->events()->get();

        $c->setAttribute('timeline', self::timeline($c, $events));
        $c->setAttribute('cash_refund', ($c->order?->payment_method ?? 'cod') === 'cod');
        $c->setAttribute('public_events', $events
            ->reject(fn($e) => in_array($e->status, ['finance_applied', 'delivered_to_seller'], true))
            ->map(fn($e) => [
                'status'     => $e->status,
                'at'         => $e->created_at,
                'note'       => in_array($e->status, self::PUBLIC_NOTES, true) ? $e->note : null,
                'method'     => $e->status === 'refund_issued' ? ($e->meta['method'] ?? null) : null,
                'reference'  => $e->status === 'refund_issued' ? ($e->meta['reference'] ?? null) : null,
            ])->values());
        $c->unsetRelation('events');

        return $c;
    }

    /** Admin & seller: full audit trail with who did what. */
    public static function forStaff(Complaint $c): Complaint
    {
        $c->loadMissing('events.actor:id,name,role');
        $c->setAttribute('timeline', self::timeline($c, $c->events));
        return $c;
    }

    public static function timeline(Complaint $c, $events): array
    {
        $at = fn(string $status) => optional($events->firstWhere('status', $status))->created_at;
        $terminal = in_array($c->status, [Complaint::STATUS_REJECTED, Complaint::STATUS_CANCELLED, Complaint::STATUS_CLOSED], true);

        $currentIndex = match ($c->status) {
            Complaint::STATUS_REQUESTED          => 0,
            Complaint::STATUS_SELLER_ACCEPTED,
            Complaint::STATUS_SELLER_REJECTED,
            Complaint::STATUS_ESCALATED          => 1,
            Complaint::STATUS_ADMIN_APPROVED     => 2,
            Complaint::STATUS_PICKUP_SCHEDULED   => 3,
            Complaint::STATUS_PICKED_UP          => 4,
            Complaint::STATUS_RETURNED_TO_SELLER => 5,
            Complaint::STATUS_REFUNDED           => 6,
            default                              => self::lastReached($events),
        };

        $steps = [];
        foreach (self::PATH as $i => $step) {
            $status = $step;
            $date   = $at($step);
            if ($step === 'seller_decision') {
                $status = $c->seller_decision === 'rejected' ? Complaint::STATUS_SELLER_REJECTED
                    : ($c->seller_decision === 'approved' ? Complaint::STATUS_SELLER_ACCEPTED : 'seller_decision');
                $date = $c->seller_decided_at ?? $at(Complaint::STATUS_SELLER_ACCEPTED) ?? $at(Complaint::STATUS_SELLER_REJECTED);
                if ($c->status === Complaint::STATUS_ESCALATED) $status = Complaint::STATUS_ESCALATED;
            }
            $steps[] = [
                'key'     => $step,
                'status'  => $status,
                'at'      => $date,
                'done'    => $i < $currentIndex || ($i === $currentIndex && $c->status === Complaint::STATUS_REFUNDED),
                'current' => !$terminal && $i === $currentIndex && $c->status !== Complaint::STATUS_REFUNDED,
                'skipped' => $step === 'seller_decision' && !$c->seller_decision && $i < $currentIndex,
            ];
        }

        if ($terminal) {
            $steps[] = ['key' => $c->status, 'status' => $c->status, 'at' => $at($c->status) ?? $c->resolved_at, 'done' => true, 'current' => true, 'skipped' => false];
        }

        return $steps;
    }

    private static function lastReached($events): int
    {
        $index = 0;
        foreach ($events as $e) {
            $i = array_search($e->status, self::PATH, true);
            if ($i === false && in_array($e->status, [Complaint::STATUS_SELLER_ACCEPTED, Complaint::STATUS_SELLER_REJECTED, Complaint::STATUS_ESCALATED], true)) $i = 1;
            if ($i !== false) $index = max($index, $i + 1);
        }
        return $index;
    }
}
