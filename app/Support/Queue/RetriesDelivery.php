<?php

namespace App\Support\Queue;

/**
 * Queued e-mails / notifications: 5 tries, retried after 1 min, 5 min, 15 min
 * and 1 h (same schedule as the seller order e-mails). A short SMTP or DNS
 * outage (smtp-relay.brevo.com unreachable for a few minutes, seen on
 * 2026-11-01 and 2026-11-05) then delays the e-mail instead of losing it in
 * failed_jobs. The class value wins over the worker's --tries.
 *
 * A class using this trait must not declare its own $tries.
 */
trait RetriesDelivery
{
    public $tries = 5;

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }
}
