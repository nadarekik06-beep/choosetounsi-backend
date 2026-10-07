<?php

namespace App\Console\Commands;

use Database\Seeders\FunnelDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Visitor Insights demo data (database/seeders/FunnelDemoSeeder.php).
 *
 *   php artisan funnel:demo            create it (refuses if it already exists)
 *   php artisan funnel:demo --fresh    purge, then create
 *   php artisan funnel:demo --purge    remove it
 *
 * Only touches accounts matching FunnelDemoSeeder::EMAIL_LIKE and its demo
 * category — never the DemoCatalog / Growth demo accounts on the same domain.
 */
class FunnelDemo extends Command
{
    protected $signature = 'funnel:demo {--purge : Remove the demo data} {--fresh : Purge, then seed again} {--force : Skip the confirmation}';

    protected $description = 'Create (or remove) the Visitor Insights demo shops';

    public function handle(): int
    {
        if ($this->option('purge') || $this->option('fresh')) {
            if (!$this->purge()) return self::FAILURE;
            if ($this->option('purge')) return self::SUCCESS;
        }
        $this->call('db:seed', ['--class' => FunnelDemoSeeder::class, '--force' => true]);
        return self::SUCCESS;
    }

    private function purge(): bool
    {
        $users = DB::table('users')->where('email', 'like', FunnelDemoSeeder::EMAIL_LIKE)->pluck('email', 'id')->all();
        $cats  = DB::table('categories')->where('slug', 'like', FunnelDemoSeeder::CATEGORY_LIKE)->pluck('id')->all();
        if (!$users && !$cats) {
            $this->info('No Visitor Insights demo data.');
            return true;
        }
        $this->line('Matched users: ' . count($users) . ' (' . FunnelDemoSeeder::EMAIL_LIKE . ')');
        if (!$this->option('force') && !$this->confirm(count($users) . ' funnel demo users and all their data will be deleted. Continue?')) {
            return false;
        }
        $ids = array_keys($users);

        DB::transaction(function () use ($ids, $cats) {
            $products = DB::table('products')->whereIn('seller_id', $ids)->pluck('id')->all();
            $orders   = DB::table('orders')->whereIn('user_id', $ids)->pluck('id')->all();
            $promos   = DB::table('promotions')->whereIn('seller_id', $ids)->pluck('id')->all();

            DB::table('reviews')->whereIn('product_id', $products)->delete();
            DB::table('order_items')->whereIn('order_id', $orders)->delete();
            DB::table('seller_orders')->whereIn('order_id', $orders)->delete();
            DB::table('orders')->whereIn('id', $orders)->delete();
            DB::table('promotion_products')->whereIn('promotion_id', $promos)->delete();
            DB::table('promotions')->whereIn('id', $promos)->delete();
            DB::table('user_interactions')->where(fn ($q) => $q->whereIn('product_id', $products)->orWhereIn('user_id', $ids))->delete();
            DB::table('product_funnel_events')->whereIn('product_id', $products)->delete();
            DB::table('product_daily_stats')->whereIn('product_id', $products)->delete();
            DB::table('product_daily_traffic')->whereIn('product_id', $products)->delete();
            DB::table('favorites')->where(fn ($q) => $q->whereIn('product_id', $products)->orWhereIn('user_id', $ids))->delete();
            DB::table('carts')->where(fn ($q) => $q->whereIn('product_id', $products)->orWhereIn('user_id', $ids))->delete();
            DB::table('growth_actions')->whereIn('seller_id', $ids)->delete();
            DB::table('growth_cards')->whereIn('seller_id', $ids)->delete();
            DB::table('growth_snapshots')->whereIn('seller_id', $ids)->delete();
            DB::table('product_variants')->whereIn('product_id', $products)->delete();
            DB::table('product_images')->whereIn('product_id', $products)->delete();
            DB::table('products')->whereIn('id', $products)->delete();
            DB::table('subcategories')->whereIn('category_id', $cats)->delete();
            DB::table('categories')->whereIn('id', $cats)->delete();
            DB::table('notifications')->where('notifiable_type', \App\Models\User::class)->whereIn('notifiable_id', $ids)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', \App\Models\User::class)->whereIn('tokenable_id', $ids)->delete();
            DB::table('seller_subscriptions')->whereIn('user_id', $ids)->delete();
            DB::table('seller_applications')->whereIn('user_id', $ids)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
        });
        // Benchmarks included the demo peers
        app(\App\Services\VisitorInsights\FunnelBenchmarks::class)
            ->build(\Carbon\CarbonImmutable::now(config('funnel.timezone'))->startOfDay()->subDay());
        $this->info('Visitor Insights demo data removed.');
        return true;
    }
}
