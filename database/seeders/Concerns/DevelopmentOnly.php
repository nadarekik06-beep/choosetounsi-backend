<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * For seeders that create demo accounts or fake data. Refuses to run when
 * APP_ENV=production, whatever the caller passes (--force included).
 */
trait DevelopmentOnly
{
    protected function refuseInProduction(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(static::class . ' creates demo data and cannot run in production.');
        }
    }
}
