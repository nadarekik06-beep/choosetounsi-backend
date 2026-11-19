<?php

namespace App\Services\Orders\WhatsApp;

use App\Models\SellerOrder;
use App\Models\SellerOrderReminder;
use App\Models\User;
use App\Support\BusinessDays;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle of the WhatsApp notices the admin sends sellers about confirmed
 * parcels (seller_order_reminders). The only writer of that table and of
 * seller_orders.prepared_at / overdue_at.
 *
 *   admin confirms        attempt 0 due now (initial notice)
 *                         attempt 1 pending, due 2 working hours later
 *   attempt 1 sent        attempt 2 pending, due 4 working hours later
 *   attempt 2 sent        overdue_at = 4 working hours later: still not prepared
 *                         by then → overdue (red in the admin panel, call the seller)
 *   prepared / cancelled / handed to the courier → open reminders cancelled
 *
 * Made-to-order ("sur commande") parcels: the longest preparation time among their
 * items (preparation_days, business days: same rules and holidays as the storefront
 * delivery estimate, see BusinessDays) shifts reminder 1 and the overdue flag, so the
 * seller is not chased while still making the item. Parcels with no made-to-order
 * item keep the 2h / 4h timing exactly.
 *
 * Working hours: ReminderSchedule. Due switching: orders:whatsapp-reminders.
 */
class SellerReminderService
{
    /**
     * Start the notices for parcels the admin just confirmed. Safe to call
     * twice: a parcel with open reminders is left alone; one whose reminders
     * were all closed (cancelled, then re-confirmed) starts over.
     *
     * @param iterable<SellerOrder> $parcels
     */
    public function schedule(iterable $parcels, ?CarbonInterface $at = null): void
    {
        $at ??= now();
        foreach ($parcels as $parcel) {
            if (!$this->needsReminders($parcel)) {
                continue;
            }
            $existing = SellerOrderReminder::where('seller_order_id', $parcel->id);
            if ((clone $existing)->whereIn('status', SellerOrderReminder::OPEN)->exists()) {
                continue;
            }
            $existing->delete();
            DB::table('seller_orders')->where('id', $parcel->id)->update(['overdue_at' => null]);
            $this->createInitial($parcel, $at);
        }
    }

    /**
     * Record that attempt $attempt went out, then schedule the next step.
     * A parcel confirmed before reminders existed starts them with attempt 0.
     *
     * @throws \DomainException when the parcel no longer needs it
     */
    public function markSent(SellerOrder $parcel, int $attempt, User $admin): SellerOrderReminder
    {
        return DB::transaction(function () use ($parcel, $attempt, $admin) {
            $parcel = SellerOrder::whereKey($parcel->id)->lockForUpdate()->firstOrFail();
            if (!$this->needsReminders($parcel)) {
                throw new \DomainException('This parcel no longer needs a WhatsApp notice (prepared, cancelled or already with the courier).');
            }

            if ($attempt === 0 && !SellerOrderReminder::where('seller_order_id', $parcel->id)->exists()) {
                $this->createInitial($parcel, now());
            }

            $reminder = SellerOrderReminder::where('seller_order_id', $parcel->id)->where('attempt', $attempt)->lockForUpdate()->first();
            if (!$reminder) {
                throw new \DomainException("Reminder {$attempt} is not scheduled for this parcel yet.");
            }
            if ($reminder->status === SellerOrderReminder::SENT) {
                return $reminder;   // double click / second admin
            }
            if ($reminder->status === SellerOrderReminder::CANCELLED) {
                throw new \DomainException('This reminder was cancelled.');
            }

            $now = now();
            $reminder->update(['status' => SellerOrderReminder::SENT, 'sent_at' => $now, 'sent_by_admin_id' => $admin->id]);

            // Earlier notices still waiting are superseded by this one
            SellerOrderReminder::where('seller_order_id', $parcel->id)
                ->where('attempt', '<', $attempt)
                ->whereIn('status', SellerOrderReminder::OPEN)
                ->update(['status' => SellerOrderReminder::CANCELLED, 'updated_at' => $now]);

            if ($attempt === 1) {
                $this->create($parcel, 2, ReminderSchedule::dueAfter($now, config('seller_whatsapp.delays.reminder_2')));
            } elseif ($attempt === 2) {
                $from = $this->notBefore($now, $this->readyAt($parcel, $parcel->admin_confirmed_at ?? $now));
                DB::table('seller_orders')->where('id', $parcel->id)
                    ->update(['overdue_at' => ReminderSchedule::dueAfter($from, config('seller_whatsapp.delays.overdue'))]);
            }

            return $reminder->fresh();
        });
    }

    /** The seller prepared the parcel: reminders stop. Idempotent. */
    public function markPrepared(SellerOrder $parcel, ?int $by): void
    {
        // Only a parcel an admin confirmed (same rule as the endpoint: never prepare an unconfirmed order)
        if (!\App\Services\Orders\ParcelStatus::isAdminConfirmed($parcel)) {
            throw \App\Services\Orders\IllegalTransition::notAdminConfirmed($parcel->id, (string) $parcel->status, 'prepared');
        }
        DB::table('seller_orders')->where('id', $parcel->id)->where('status', 'confirmed')
            ->whereNotNull('admin_confirmed_at')->whereNull('prepared_at')
            ->update(['prepared_at' => now(), 'prepared_by' => $by, 'overdue_at' => null, 'updated_at' => now()]);
        $this->cancel([$parcel->id]);
    }

    /** @param int[] $sellerOrderIds */
    public function cancel(array $sellerOrderIds): int
    {
        if (!$sellerOrderIds) {
            return 0;
        }
        return SellerOrderReminder::whereIn('seller_order_id', $sellerOrderIds)
            ->whereIn('status', SellerOrderReminder::OPEN)
            ->update(['status' => SellerOrderReminder::CANCELLED, 'updated_at' => now()]);
    }

    /**
     * Scheduler pass: close reminders of parcels that moved on (prepared,
     * cancelled, shipped…), then open the ones whose time has come.
     *
     * @return array{cancelled:int, due:int, overdue:int}
     */
    public function refresh(): array
    {
        $movedOn = SellerOrder::select('id')
            ->where(fn ($q) => $q->where('status', '!=', 'confirmed')->orWhereNotNull('prepared_at'));

        $cancelled = SellerOrderReminder::whereIn('status', SellerOrderReminder::OPEN)
            ->whereIn('seller_order_id', $movedOn)
            ->update(['status' => SellerOrderReminder::CANCELLED, 'updated_at' => now()]);

        $due = SellerOrderReminder::where('status', SellerOrderReminder::PENDING)
            ->where('due_at', '<=', now())
            ->update(['status' => SellerOrderReminder::DUE, 'updated_at' => now()]);

        return ['cancelled' => $cancelled, 'due' => $due, 'overdue' => self::overdue()->count()];
    }

    /** A seller's confirmed parcel still waiting to be prepared. */
    public function needsReminders(SellerOrder $parcel): bool
    {
        return $parcel->status === 'confirmed'
            && $parcel->prepared_at === null
            && $parcel->seller_id !== null
            && !$parcel->isPlatformParcel();
    }

    public static function isOverdue(SellerOrder $parcel): bool
    {
        return $parcel->status === 'confirmed'
            && $parcel->prepared_at === null
            && $parcel->overdue_at !== null
            && $parcel->overdue_at->lte(now());
    }

    /** Parcels whose last reminder got no reaction in time. */
    public static function overdue(): Builder
    {
        return SellerOrder::where('status', 'confirmed')
            ->whereNull('prepared_at')
            ->whereNotNull('overdue_at')
            ->where('overdue_at', '<=', now());
    }

    // ── Internals ──────────────────────────────────────────────────────────

    private function createInitial(SellerOrder $parcel, CarbonInterface $at): void
    {
        $this->create($parcel, 0, $at, SellerOrderReminder::DUE);
        $from = $this->notBefore($at, $this->readyAt($parcel, $at));
        $this->create($parcel, 1, ReminderSchedule::dueAfter($from, config('seller_whatsapp.delays.reminder_1')));
    }

    /** Longest preparation time (business days) among the parcel's made-to-order items; 0 = none. */
    public function preparationDays(SellerOrder $parcel): int
    {
        return (int) DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->where('oi.seller_order_id', $parcel->id)
            ->where('p.is_made_to_order', true)
            ->max('p.preparation_days');
    }

    /**
     * When the seller is expected to have the parcel ready: opening time (Tunis) of the day
     * reached $days business days after $from. NULL for parcels with no made-to-order item.
     */
    private function readyAt(SellerOrder $parcel, CarbonInterface $from): ?Carbon
    {
        $days = $this->preparationDays($parcel);
        if ($days < 1) {
            return null;
        }
        [$h, $m] = array_map('intval', explode(':', config('seller_whatsapp.working_hours.start')) + [1 => 0]);
        return BusinessDays::add(Carbon::instance($from), $days)->setTime($h, $m);
    }

    /** The later of $now and $ready ($ready NULL = $now, i.e. unchanged timing). */
    private function notBefore(CarbonInterface $now, ?Carbon $ready): CarbonInterface
    {
        return $ready && $ready->gt($now) ? $ready : $now;
    }

    private function create(SellerOrder $parcel, int $attempt, CarbonInterface $dueAt, string $status = SellerOrderReminder::PENDING): void
    {
        $now = now();
        DB::table('seller_order_reminders')->insertOrIgnore([
            'seller_order_id' => $parcel->id,
            'attempt'         => $attempt,
            'due_at'          => $dueAt,
            'status'          => $status,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }
}
