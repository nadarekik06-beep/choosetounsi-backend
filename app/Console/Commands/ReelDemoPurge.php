<?php

namespace App\Console\Commands;

use Database\Seeders\ReelDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Removes everything database/seeders/ReelDemoSeeder.php created (LOCAL demo shop for the reels). */
class ReelDemoPurge extends Command
{
    protected $signature = 'reel:demo-purge {--force : Skip the confirmation}';

    protected $description = 'Delete the reel demo shop (Dar Yasmine Mode), its products, promotions and orders';

    public function handle(): int
    {
        $users = DB::table('users')->where('email', 'like', ReelDemoSeeder::EMAIL_LIKE)->pluck('id')->all();
        if (!$users) {
            $this->info('No reel demo data.');
            return self::SUCCESS;
        }
        if (!$this->option('force') && !$this->confirm(count($users) . ' reel demo users (' . ReelDemoSeeder::EMAIL_LIKE . ') and all their data will be deleted. Continue?')) {
            return self::FAILURE;
        }

        DB::transaction(function () use ($users) {
            $products = DB::table('products')->whereIn('seller_id', $users)->pluck('id')->all();
            $orders   = DB::table('orders')->whereIn('user_id', $users)->pluck('id')->all();
            $promos   = DB::table('promotions')->whereIn('seller_id', $users)->pluck('id')->all();

            DB::table('order_items')->whereIn('order_id', $orders)->delete();
            DB::table('seller_orders')->whereIn('order_id', $orders)->delete();
            DB::table('orders')->whereIn('id', $orders)->delete();
            DB::table('promotion_products')->whereIn('promotion_id', $promos)->delete();
            DB::table('promotions')->whereIn('id', $promos)->delete();
            DB::table('user_interactions')->whereIn('product_id', $products)->delete();
            DB::table('product_images')->whereIn('product_id', $products)->delete();
            DB::table('seller_pickup_addresses')->whereIn('user_id', $users)->delete();
            DB::table('products')->whereIn('id', $products)->delete();
            DB::table('notifications')->where('notifiable_type', \App\Models\User::class)->whereIn('notifiable_id', $users)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', \App\Models\User::class)->whereIn('tokenable_id', $users)->delete();
            DB::table('seller_subscriptions')->whereIn('user_id', $users)->delete();
            DB::table('seller_applications')->whereIn('user_id', $users)->delete();
            DB::table('users')->whereIn('id', $users)->delete();
        });

        \Illuminate\Support\Facades\Storage::disk('public')->deleteDirectory('products/reel-demo');
        $this->info('Reel demo data removed (incl. storage/app/public/products/reel-demo).');
        return self::SUCCESS;
    }
}
