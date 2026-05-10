<?php
// app/Console/Commands/FixPlatformProducts.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixPlatformProducts extends Command
{
    protected $signature   = 'fix:platform-products';
    protected $description = 'Fix existing brand products: set is_platform_product=true, seller_id=null';

    public function handle(): void
    {
        // Step 1 — find products owned by admin (seller_id=1) 
        // that are NOT platform-flagged yet
        $count = DB::table('products')
            ->where('seller_id', 1)
            ->where('is_platform_product', false)
            ->count();

        $this->info("Found {$count} products to fix.");

        if ($count === 0) {
            $this->warn('Nothing to fix.');
            return;
        }

        // Step 2 — mark them as platform products
        DB::table('products')
            ->where('seller_id', 1)
            ->where('is_platform_product', false)
            ->update([
                'is_platform_product' => true,
                'is_approved'         => true,
                'seller_id'           => null,   // works now that column is nullable
            ]);

        $this->info("Fixed {$count} products.");

        // Step 3 — verify
        $remaining = DB::table('products')
            ->where('is_platform_product', true)
            ->count();

        $this->info("Total platform products now: {$remaining}");
    }
}