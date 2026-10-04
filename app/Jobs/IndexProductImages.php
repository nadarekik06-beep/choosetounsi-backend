<?php

namespace App\Jobs;

use App\Services\Search\ImageIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Embeds a product's photos and refreshes them in the image search index. Queued so an upload
 * request never waits for the model; one pending job per product (an upload of 6 photos
 * dispatches 6 times but runs once, after they are all saved).
 */
class IndexProductImages implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 5;
    public $uniqueFor = 600;

    public function __construct(public int $productId)
    {
        $this->afterCommit = true;
        $this->delay(now()->addSeconds(5));
    }

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    /** Embedding service restarting or Meilisearch busy: wait and retry. */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(ImageIndexer $indexer): void
    {
        try {
            $indexer->syncProduct($this->productId);
        } catch (Throwable $e) {
            // On a real queue the job is retried (backoff). Run inline (QUEUE_CONNECTION=sync) it
            // would fail the seller's request instead: log it, `php artisan search:reindex` catches up.
            if (!$this->runsInline()) {
                throw $e;
            }
            Log::warning("[Search] Photos of product {$this->productId} not indexed: {$e->getMessage()}");
        }
    }

    private function runsInline(): bool
    {
        return !$this->job || $this->job->getConnectionName() === 'sync';
    }
}
