<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCommissionOverride as Override;
use App\Models\User;
use App\Notifications\ProductCommissionOverrideNotification as Notice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Writes special commissions (create / edit / end now / delete / expire) and keeps the
 * audit trail (product_commission_override_logs: actor, product, before → after, when).
 *
 * "One active override per product": inside the transaction the PRODUCT ROW IS LOCKED
 * (SELECT … FOR UPDATE), then the windows of the product's other live/scheduled
 * overrides are compared with the new one. No generated/unique column: identical on
 * XAMPP's MariaDB 10.4 and production's 10.11. Two windows may follow each other
 * (one ends, the next starts) but never overlap, so at any instant at most one applies.
 *
 * It NEVER touches orders: they carry their own snapshot.
 */
class ProductCommissionOverrideService
{
    public const REMINDER_DAYS = 3;

    /** @param array{product_id:int,type:string,value:mixed,starts_at?:?string,ends_at?:?string,note?:?string} $data */
    public function create(array $data, User $admin): Override
    {
        return DB::transaction(function () use ($data, $admin) {
            $product = $this->lockProduct((int) ($data['product_id'] ?? 0));
            $fields  = $this->validated($data, $product);
            $this->assertNoOverlap($product, $fields['starts_at'], $fields['ends_at'], null);

            $o = Override::create($fields + [
                'product_id' => $product->id, 'seller_id' => $product->seller_id,
                'is_active'  => true, 'created_by' => $admin->id, 'updated_by' => $admin->id,
            ]);
            $this->log($o, 'created', $admin, null, $this->snapshot($o));
            $this->notify($o, Notice::GRANTED);
            return $o->fresh();
        });
    }

    public function update(Override $override, array $data, User $admin): Override
    {
        return DB::transaction(function () use ($override, $data, $admin) {
            $product  = $this->lockProduct((int) $override->product_id);
            $override = Override::lockForUpdate()->findOrFail($override->id);
            $before   = $this->snapshot($override);

            $fields = $this->validated($data + [
                'type' => $override->type, 'value' => $override->value,
                'starts_at' => $override->starts_at, 'ends_at' => $override->ends_at, 'note' => $override->note,
            ], $product, $override);
            $this->assertNoOverlap($product, $fields['starts_at'], $fields['ends_at'], $override->id);

            $override->fill($fields + ['updated_by' => $admin->id]);
            // Re-open an expired/ended one only when the new window is live or scheduled
            if (!$override->is_active && ($override->ends_at === null || $override->ends_at->gt(Carbon::now()))) {
                $override->is_active = true;
                $override->ended_notified_at = null;
            }
            // New end date → the 3-day reminder is owed again
            if ($override->isDirty('ends_at')) {
                $override->reminder_sent_at = null;
                $override->ended_notified_at = null;
            }
            $override->save();
            $this->log($override, 'updated', $admin, $before, $this->snapshot($override));
            return $override->fresh();
        });
    }

    /** Ends the offer right now. Orders placed before keep their snapshot. */
    public function endNow(Override $override, User $admin): Override
    {
        return DB::transaction(function () use ($override, $admin) {
            $this->lockProduct((int) $override->product_id);
            $override = Override::lockForUpdate()->findOrFail($override->id);
            $before   = $this->snapshot($override);
            $now      = Carbon::now();

            // Never started yet: nothing was applied, just switch it off. Otherwise stop at this instant.
            $override->is_active = false;
            if ($override->ends_at === null || $override->ends_at->gt($now)) {
                $override->ends_at = $override->starts_at !== null && $override->starts_at->gt($now) ? $override->starts_at : $now;
            }
            $override->updated_by = $admin->id;
            $override->save();
            $this->log($override, 'ended', $admin, $before, $this->snapshot($override));
            $this->notifyEnded($override);
            return $override->fresh();
        });
    }

    public function delete(Override $override, User $admin): void
    {
        DB::transaction(function () use ($override, $admin) {
            $this->lockProduct((int) $override->product_id);
            $override = Override::lockForUpdate()->findOrFail($override->id);
            $before   = $this->snapshot($override);
            $wasLive  = $override->isLiveAt(Carbon::now());

            $override->updated_by = $admin->id;
            $override->is_active  = false;
            $override->save();
            $override->delete();                                   // soft delete: orders keep the id
            $this->log($override, 'deleted', $admin, $before, null);
            if ($wasLive) {
                $this->notifyEnded($override);
            }
        });
    }

    /**
     * Scheduler: switch off what is over, remind 3 days before the end.
     * Rates never depend on this (they compare dates), so a late run is harmless.
     *
     * @return array{expired:int, reminded:int}
     */
    public function runDue(?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $expired = $reminded = 0;

        Override::where('is_active', true)->whereNotNull('ends_at')->where('ends_at', '<=', $now)
            ->orderBy('id')->each(function (Override $o) use ($now, &$expired) {
                DB::transaction(function () use ($o, &$expired) {
                    $o = Override::lockForUpdate()->find($o->id);
                    if (!$o || !$o->is_active) {
                        return;
                    }
                    $before = $this->snapshot($o);
                    $o->is_active = false;
                    $o->save();
                    $this->log($o, 'expired', null, $before, $this->snapshot($o));
                    $this->notifyEnded($o);
                    $expired++;
                });
            });

        $window = $now->copy()->addDays(self::REMINDER_DAYS);
        Override::liveAt($now)->whereNotNull('ends_at')->where('ends_at', '<=', $window)
            ->whereNull('reminder_sent_at')
            // An offer granted inside the window (e.g. a 2-day offer) is not reminded: the seller was just told
            ->where('created_at', '<=', DB::raw("DATE_SUB(ends_at, INTERVAL " . self::REMINDER_DAYS . " DAY)"))
            ->orderBy('id')->each(function (Override $o) use ($now, &$reminded) {
                $this->notify($o, Notice::REMINDER);
                $o->forceFill(['reminder_sent_at' => $now])->saveQuietly();
                $reminded++;
            });

        return ['expired' => $expired, 'reminded' => $reminded];
    }

    // ── internals ─────────────────────────────────────────────────────────────

    private function lockProduct(int $productId): Product
    {
        $product = Product::whereKey($productId)->lockForUpdate()->first();
        if (!$product) {
            throw ValidationException::withMessages(['product_id' => 'Product not found.']);
        }
        if ($product->seller_id === null) {
            throw ValidationException::withMessages(['product_id' => 'This product has no seller: a special commission needs one.']);
        }
        return $product;
    }

    /** @return array{type:string,value:float,starts_at:?Carbon,ends_at:?Carbon,note:?string} */
    private function validated(array $data, Product $product, ?Override $existing = null): array
    {
        $errors = [];
        $type   = $data['type'] ?? null;
        if (!in_array($type, Override::TYPES, true)) {
            $errors['type'] = 'Type must be fixed_rate or reduction_points.';
        }

        $raw = $data['value'] ?? null;
        if (!is_numeric($raw)) {
            $errors['value'] = 'Value must be a number.';
        } else {
            $value = (float) $raw;
            if ($value < 0 || $value > 100) {
                $errors['value'] = 'Value must be between 0 and 100.';
            } elseif (abs($value * 100 - round($value * 100)) > 1e-9) {
                $errors['value'] = 'Value has at most 2 decimals.';
            }
        }

        $starts = $this->date($data['starts_at'] ?? null, 'starts_at', $errors);
        $ends   = $this->date($data['ends_at'] ?? null, 'ends_at', $errors);
        if ($ends !== null && !isset($errors['ends_at'])) {
            if ($starts !== null && $ends->lte($starts)) {
                $errors['ends_at'] = 'The end must be after the start.';
            } elseif ($starts === null && $ends->lte(Carbon::now()) && (!$existing || !$existing->ends_at || !$existing->ends_at->eq($ends))) {
                $errors['ends_at'] = 'The end date is already past.';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $note = isset($data['note']) ? trim((string) $data['note']) : null;
        return [
            'type' => $type, 'value' => round((float) $raw, 2), 'starts_at' => $starts, 'ends_at' => $ends,
            'note' => $note !== '' ? $note : null,
        ];
    }

    private function date($v, string $field, array &$errors): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return $v instanceof \DateTimeInterface ? Carbon::instance($v) : Carbon::parse((string) $v);
        } catch (\Throwable) {
            $errors[$field] = 'Invalid date.';
            return null;
        }
    }

    /** Windows [start, end) of the product's other switched-on, not-yet-over overrides must not meet the new one. */
    private function assertNoOverlap(Product $product, ?Carbon $starts, ?Carbon $ends, ?int $ignoreId): void
    {
        $now = Carbon::now();
        $clash = Override::where('product_id', $product->id)
            ->where('is_active', true)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))   // not over
            // other.start < new.end   (null = open)
            ->when($ends !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<', $ends)))
            // other.end > new.start   (null = open); a new offer without start begins now
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $starts ?? $now))
            ->first();

        if ($clash) {
            throw ValidationException::withMessages([
                'product_id' => 'This product already has a special commission for that period (#' . $clash->id . '). End or edit it first.',
            ]);
        }
    }

    private function snapshot(Override $o): array
    {
        return [
            'type' => $o->type, 'value' => (float) $o->value,
            'starts_at' => $o->starts_at?->toIso8601String(), 'ends_at' => $o->ends_at?->toIso8601String(),
            'is_active' => (bool) $o->is_active, 'note' => $o->note,
        ];
    }

    private function log(Override $o, string $action, ?User $actor, ?array $before, ?array $after): void
    {
        DB::table('product_commission_override_logs')->insert([
            'override_id' => $o->id, 'product_id' => $o->product_id, 'seller_id' => $o->seller_id,
            'actor_id' => $actor?->id, 'actor_role' => $actor ? 'admin' : 'system', 'action' => $action,
            'before' => $before ? json_encode($before) : null, 'after' => $after ? json_encode($after) : null,
            'created_at' => Carbon::now(),
        ]);
    }

    private function notify(Override $o, string $kind): void
    {
        try {
            $seller  = User::find($o->seller_id);
            $product = Product::withTrashed()->find($o->product_id);
            if (!$seller || !$product) {
                return;
            }
            $seller->notify(new Notice($kind, $o, $product->name));
            if ($kind === Notice::GRANTED) {
                $o->forceFill(['granted_notified_at' => Carbon::now()])->saveQuietly();
            }
        } catch (\Throwable $e) {
            // A failing notification must never block (or roll back) the finance decision
            Log::warning('[ProductCommissionOverride] notification failed: ' . $e->getMessage(), ['override_id' => $o->id, 'kind' => $kind]);
        }
    }

    private function notifyEnded(Override $o): void
    {
        // Only sellers who were told about the offer, and only once
        if ($o->granted_notified_at !== null && $o->ended_notified_at === null) {
            $this->notify($o, Notice::ENDED);
            $o->forceFill(['ended_notified_at' => Carbon::now()])->saveQuietly();
        }
    }
}
