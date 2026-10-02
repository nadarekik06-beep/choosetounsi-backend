<?php

namespace App\Console\Commands;

use Database\Seeders\ForecastDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Removes everything database/seeders/ForecastDemoSeeder.php created. */
class ForecastDemoPurge extends Command
{
    protected $signature = 'forecast:demo-purge {--force : Skip the confirmation}';

    protected $description = 'Delete the forecast demo shops, orders and calendar demo events';

    public function handle(): int
    {
        $users = DB::table('users')->where('email', 'like', ForecastDemoSeeder::EMAIL_LIKE)->pluck('id')->all();
        if (!$users) {
            $this->info('No forecast demo data.');
            return self::SUCCESS;
        }
        if (!$this->option('force') && !$this->confirm(count($users) . ' forecast demo users (' . ForecastDemoSeeder::EMAIL_LIKE . ') and all their data will be deleted. Continue?')) {
            return self::FAILURE;
        }

        DB::transaction(function () use ($users) {
            $products = DB::table('products')->whereIn('seller_id', $users)->pluck('id')->all();
            $orders   = DB::table('orders')->whereIn('user_id', $users)->pluck('id')->all();
            $variants = DB::table('product_variants')->whereIn('product_id', $products)->pluck('id')->all();
            $promos   = DB::table('promotions')->whereIn('seller_id', $users)->pluck('id')->all();
            $cats     = DB::table('categories')->where('slug', 'like', 'demo-forecast-%')->pluck('id')->all();

            DB::table('order_items')->whereIn('order_id', $orders)->delete();
            DB::table('seller_orders')->whereIn('order_id', $orders)->delete();
            DB::table('orders')->whereIn('id', $orders)->delete();
            DB::table('variant_attribute_values')->whereIn('variant_id', $variants)->delete();
            DB::table('product_variants')->whereIn('id', $variants)->delete();
            DB::table('promotion_products')->whereIn('promotion_id', $promos)->delete();
            DB::table('promotions')->whereIn('id', $promos)->delete();
            DB::table('user_interactions')->whereIn('product_id', $products)->delete();
            DB::table('forecast_daily_sales')->whereIn('seller_id', $users)->delete();
            DB::table('forecast_snapshots')->whereIn('seller_id', $users)->delete();
            DB::table('forecast_settings')->whereIn('seller_id', $users)->delete();
            DB::table('forecast_alert_log')->whereIn('seller_id', $users)->delete();
            DB::table('products')->whereIn('id', $products)->delete();
            DB::table('calendar_events')->where('key', 'demo_event')->delete();   // effects cascade
            DB::table('calendar_event_effects')->whereIn('category_id', $cats)->delete();
            DB::table('categories')->whereIn('id', $cats)->delete();
            DB::table('notifications')->where('notifiable_type', \App\Models\User::class)->whereIn('notifiable_id', $users)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', \App\Models\User::class)->whereIn('tokenable_id', $users)->delete();
            DB::table('seller_subscriptions')->whereIn('user_id', $users)->delete();
            DB::table('seller_applications')->whereIn('user_id', $users)->delete();
            DB::table('users')->whereIn('id', $users)->delete();
        });

        $this->info('Forecast demo data removed. (Computed Islamic holiday dates are kept — manage them in the admin calendar.)');
        return self::SUCCESS;
    }
}
