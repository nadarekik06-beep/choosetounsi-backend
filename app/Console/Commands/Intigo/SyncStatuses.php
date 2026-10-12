<?php

namespace App\Console\Commands\Intigo;

use App\Jobs\Intigo\SendIntigoParcel;
use App\Services\Intigo\IntigoClient;
use App\Services\Intigo\IntigoStatusMap;
use App\Services\Intigo\IntigoStatusSync;
use App\Services\Intigo\ParcelSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Safety net for missed webhooks (scheduled every 2 hours):
 *   1. events stored but never processed are processed again
 *   2. POST /parcels/status for every sent parcel not final here (delivered,
 *      returned, cancelled, refunded) nor at Intigo; a changed status goes
 *      through the same mapping and deduplication as a webhook
 *   3. sends left 'pending' (queue worker down…) are queued again (the
 *      claim + cid lookup make that safe)
 *   4. logs and events older than config('intigo.retention_days') are pruned
 */
class SyncStatuses extends Command
{
    protected $signature = 'intigo:sync-statuses {--no-prune}';

    protected $description = 'Poll Intigo for the status of open parcels and re-run anything a webhook or job missed';

    private const OUR_FINAL = ['delivered', 'completed', 'returned_to_seller', 'cancelled', 'refunded'];

    public function handle(IntigoClient $client, IntigoStatusSync $sync): int
    {
        // 1. Unprocessed events
        $events = DB::table('intigo_webhook_events')->whereNull('processed_at')
            ->where('created_at', '<', now()->subMinute())->orderBy('id')->limit(500)->pluck('id');
        $reprocessed = 0;
        foreach ($events as $id) {
            try { $sync->process((int) $id); $reprocessed++; } catch (\Throwable $e) { /* logged; next run */ }
        }

        // 2. Poll open parcels
        $open = DB::table('seller_orders')->whereNotNull('intigo_nid')
            ->where('intigo_sync_status', ParcelSender::SENT)
            ->whereNotIn('status', self::OUR_FINAL)
            ->get(['id', 'intigo_nid', 'intigo_status_code']);
        $open = $open->reject(fn ($so) => IntigoStatusMap::isFinal($so->intigo_status_code === null ? null : (int) $so->intigo_status_code));

        $changed = $failedChunks = 0;
        foreach ($open->chunk(50) as $chunk) {
            try {
                $statuses = $client->statuses($chunk->pluck('intigo_nid')->all());
            } catch (\Throwable $e) {
                $failedChunks++;
                $this->warn('Status poll failed: ' . $e->getMessage());
                continue;
            }
            foreach ($chunk as $so) {
                $s = $statuses[$so->intigo_nid] ?? null;
                if (!$s || (int) $s['status'] === (int) $so->intigo_status_code) {
                    continue;
                }
                // One poll event per (parcel, status): repeated polls dedupe on it
                $eventId = $sync->record($so->intigo_nid, (int) $s['status'], $so->intigo_status_code !== null ? (int) $so->intigo_status_code : null, 'poll', $s['status_label'], 'poll');
                if ($eventId) {
                    try { $sync->process($eventId); $changed++; } catch (\Throwable $e) { /* logged */ }
                }
            }
        }

        // 3. Stuck sends
        $stuck = DB::table('seller_orders')->whereNull('intigo_nid')
            ->where('intigo_sync_status', ParcelSender::PENDING)
            ->where('status', 'confirmed')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->where(fn ($q) => $q->whereNull('intigo_locked_at')->orWhere('intigo_locked_at', '<', now()->subSeconds((int) config('intigo.send.lock_seconds', 120))))
            ->where('intigo_attempts', '<', (int) config('intigo.send.max_attempts', 4))
            ->pluck('id');
        foreach ($stuck as $id) {
            SendIntigoParcel::dispatch((int) $id);
        }

        // 4. Retention
        $pruned = 0;
        if (!$this->option('no-prune')) {
            $before = now()->subDays((int) config('intigo.retention_days', 180));
            $pruned = DB::table('intigo_logs')->where('created_at', '<', $before)->delete()
                + DB::table('intigo_webhook_events')->where('created_at', '<', $before)->whereNotNull('processed_at')->delete();
        }

        $this->info(sprintf('Mode %s: %d open parcel(s) polled, %d changed, %d event(s) reprocessed, %d stuck send(s) re-queued, %d old row(s) pruned%s.',
            $client->mode(), $open->count(), $changed, $reprocessed, $stuck->count(), $pruned, $failedChunks ? ", {$failedChunks} poll chunk(s) failed" : ''));
        return self::SUCCESS;
    }
}
