<?php

namespace App\Console\Commands;

use Database\Seeders\GrowthDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Growth Radar demo data (database/seeders/GrowthDemoSeeder.php).
 *
 *   php artisan growth:demo            create it (refuses if it already exists)
 *   php artisan growth:demo --fresh    purge, then create
 *   php artisan growth:demo --purge    remove it
 *
 * Only touches accounts matching GrowthDemoSeeder::EMAIL_LIKE, its demo category
 * and its calendar event — never the DemoCatalog accounts on the same domain.
 */
class GrowthDemo extends Command
{
    protected $signature = 'growth:demo {--purge : Remove the demo data} {--fresh : Purge, then seed again} {--force : Skip the confirmation}';

    protected $description = 'Create (or remove) the Growth Radar demo shops';

    public function handle(): int
    {
        if ($this->option('purge') || $this->option('fresh')) {
            if (!$this->purge()) return self::FAILURE;
            if ($this->option('purge')) return self::SUCCESS;
        }
        $this->call('db:seed', ['--class' => GrowthDemoSeeder::class, '--force' => true]);
        return self::SUCCESS;
    }

    private function purge(): bool
    {
        $users = DB::table('users')->where('email', 'like', GrowthDemoSeeder::EMAIL_LIKE)->pluck('email', 'id')->all();
        $cats  = DB::table('categories')->where('slug', 'like', GrowthDemoSeeder::CATEGORY_LIKE)->pluck('id')->all();
        if (!$users && !$cats) {
            $this->info('No Growth Radar demo data.');
            return true;
        }
        $this->line('Matched users: ' . implode(', ', $users));
        if (!$this->option('force') && !$this->confirm(count($users) . ' growth demo users (' . GrowthDemoSeeder::EMAIL_LIKE . ') and all their data will be deleted. Continue?')) {
            return false;
        }
        $ids = array_keys($users);

        DB::transaction(function () use ($ids, $cats) {
            $products = DB::table('products')->whereIn('seller_id', $ids)->pluck('id')->all();
            $orders   = DB::table('orders')->whereIn('user_id', $ids)->pluck('id')->all();
            $promos   = DB::table('promotions')->whereIn('seller_id', $ids)->pluck('id')->all();
            $coupons  = DB::table('coupons')->whereIn('seller_id', $ids)->pluck('id')->all();
            $events   = DB::table('calendar_events')->where('key', GrowthDemoSeeder::EVENT_KEY)->pluck('id')->all();

            DB::table('order_items')->whereIn('order_id', $orders)->delete();
            DB::table('seller_orders')->whereIn('order_id', $orders)->delete();
            DB::table('orders')->whereIn('id', $orders)->delete();
            DB::table('promotion_products')->whereIn('promotion_id', $promos)->delete();
            DB::table('promotions')->whereIn('id', $promos)->delete();
            DB::table('coupon_audiences')->whereIn('coupon_id', $coupons)->delete();
            DB::table('coupon_products')->whereIn('coupon_id', $coupons)->delete();
            DB::table('coupons')->whereIn('id', $coupons)->delete();
            DB::table('coupon_audiences')->whereIn('user_id', $ids)->delete();
            DB::table('user_interactions')->where(fn ($q) => $q->whereIn('product_id', $products)->orWhereIn('user_id', $ids))->delete();
            DB::table('favorites')->where(fn ($q) => $q->whereIn('product_id', $products)->orWhereIn('user_id', $ids))->delete();
            DB::table('carts')->where(fn ($q) => $q->whereIn('product_id', $products)->orWhereIn('user_id', $ids))->delete();
            DB::table('sponsorships')->whereIn('seller_id', $ids)->delete();
            DB::table('growth_actions')->whereIn('seller_id', $ids)->delete();
            DB::table('growth_cards')->whereIn('seller_id', $ids)->delete();
            DB::table('growth_snapshots')->whereIn('seller_id', $ids)->delete();
            DB::table('forecast_daily_sales')->whereIn('seller_id', $ids)->delete();
            DB::table('forecast_snapshots')->whereIn('seller_id', $ids)->delete();
            DB::table('product_price_history')->whereIn('product_id', $products)->delete();
            DB::table('product_images')->whereIn('product_id', $products)->delete();
            DB::table('products')->whereIn('id', $products)->delete();
            DB::table('search_missed_queries')->whereIn('category_id', $cats)->delete();
            DB::table('calendar_event_effects')->whereIn('calendar_event_id', $events)->delete();
            DB::table('calendar_events')->whereIn('id', $events)->delete();
            DB::table('categories')->whereIn('id', $cats)->delete();
            DB::table('notifications')->where('notifiable_type', \App\Models\User::class)->whereIn('notifiable_id', $ids)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', \App\Models\User::class)->whereIn('tokenable_id', $ids)->delete();
            DB::table('seller_subscriptions')->whereIn('user_id', $ids)->delete();
            DB::table('seller_applications')->whereIn('user_id', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        });
        $this->info('Growth Radar demo data removed.');
        return true;
    }
}
