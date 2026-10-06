<?php

namespace App\Console\Commands;

use App\Services\Forecast\Calendar;
use Illuminate\Console\Command;

/**
 * Add computed Islamic-holiday dates (Ramadan, Aïds, Ras el Am, Mouled) for a
 * year to the calendar. Computed with the tabular Hijri calendar: may differ by
 * 1–2 days from the official announcement — check them in the admin panel.
 */
class ForecastHijri extends Command
{
    protected $signature = 'forecast:hijri {year? : Gregorian year (default: this year and next)}';

    protected $description = 'Add computed Islamic holiday dates to the Tunisian calendar';

    public function handle(): int
    {
        $years = $this->argument('year') ? [(int) $this->argument('year')] : [(int) date('Y'), (int) date('Y') + 1];
        foreach ($years as $y) {
            $this->info("$y: " . Calendar::seedHijriYear($y) . ' Islamic holiday(s), '
                . Calendar::seedFixedYear($y) . ' fixed-date moment(s) added');
        }
        return self::SUCCESS;
    }
}
