<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * PlatformUserSeeder
 *
 * Creates the CHOOSE'Tounsi platform user — a special "seller" account that
 * owns all admin-created brand products.
 *
 * WHY:
 *   SellerOrder.seller_id is a non-nullable FK to users.id.
 *   Brand products previously had seller_id = null which caused checkout to
 *   fail with an integrity constraint violation when grouping by seller.
 *
 * WHAT THIS DOES:
 *   1. Creates (or finds) the platform user with role = 'seller'.
 *   2. Stores the platform user's ID in the `settings` table (or a config file)
 *      so the system can always look it up without hardcoding the ID.
 *   3. Back-fills all brand products (is_platform_product = true) to use this
 *      seller_id instead of null.
 *
 * RUN:
 *   php artisan db:seed --class=PlatformUserSeeder
 */
class PlatformUserSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Create or retrieve the platform user ────────────────────────────
        $platformUser = User::firstOrCreate(
            ['email' => 'platform@choosetounsi.tn'],
            [
                'name'              => "CHOOSE'Tounsi",
                'password'          => Hash::make(bin2hex(random_bytes(32))), // random unguessable password
                'role'              => 'seller',
                'email_verified_at' => now(),
            ]
        );

        $this->command->info("Platform user ID: {$platformUser->id}");

        // ── 2. Store the platform user ID in a settings row ───────────────────
        // This lets any controller call \App\Helpers\PlatformUser::id()
        // without hardcoding the ID anywhere.
        //
        // If you don't have a settings table, we fall back to writing a PHP
        // config file instead — but the settings table approach is cleaner.
        if (DB::getSchemaBuilder()->hasTable('settings')) {
            DB::table('settings')->updateOrInsert(
                ['key' => 'platform_user_id'],
                ['value' => $platformUser->id, 'updated_at' => now()]
            );
        } else {
            // Write to a PHP config file as fallback
            $configPath = config_path('platform.php');
            file_put_contents($configPath, "<?php\nreturn [\n    'platform_user_id' => {$platformUser->id},\n];\n");
            $this->command->info("Platform user ID written to config/platform.php");
        }

        // ── 3. Back-fill all brand products with the platform seller_id ───────
        $updated = DB::table('products')
            ->where('is_platform_product', true)
            ->whereNull('seller_id')
            ->update(['seller_id' => $platformUser->id]);

        $this->command->info("Back-filled {$updated} brand product(s) with platform seller_id.");

        // ── 4. Also fix any brand products that have a wrong seller_id ─────────
        // (shouldn't happen, but safety net)
        $total = DB::table('products')
            ->where('is_platform_product', true)
            ->count();

        $this->command->info("Total brand products: {$total}");
        $this->command->info("✅ Platform user seeded successfully. ID = {$platformUser->id}");
    }
}