<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Database\Seeders\AlertTestSeeder;

class SeedAlertTestProducts extends Command
{
    protected $signature   = 'ct:seed-alert-tests {--seller-id=1 : The seller user ID}';
    protected $description = 'Seed test products for alert system development';

    public function handle(): void
    {
        $sellerId = (int)$this->option('seller-id');
        $this->info("Seeding alert test products for seller #{$sellerId}...");

        $seeder = new AlertTestSeeder();
        $seeder->setCommand($this);
        $seeder->run($sellerId);
    }
}