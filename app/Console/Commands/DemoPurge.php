<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes everything `demo:catalog` created: the demo accounts (*@choosetounsi.test — 5 sellers,
 * 12 clients, the demo shopper), their products, orders, reviews, sponsorships, flash sale,
 * favourites, activity, and the generated images in storage/app/public/products/demo/.
 *
 * Starting from the demo accounts, it follows every foreign key in the database to the rows
 * that depend on them (products → images, variants, reviews…; orders → seller orders, items…),
 * so tables added later are covered too. Guests' interactions with demo products go as well.
 *
 * It refuses to run when a real customer's order contains a demo product (deleting it would
 * break that order). Everything is deleted in one transaction.
 *
 *   php artisan demo:purge --dry-run   list what would be deleted, change nothing
 *   php artisan demo:purge             asks for confirmation (--force to skip, required in production)
 */
class DemoPurge extends Command
{
    protected $signature = 'demo:purge {--dry-run : Only list what would be deleted} {--force : Do not ask for confirmation}';
    protected $description = 'Delete the demo catalog created by demo:catalog (sellers, products, clients, orders, reviews)';

    const EMAIL_PATTERN = 'demo.%@choosetounsi.test';
    const IMAGE_DIR = 'products/demo';

    /** @var array<string, array<int, true>> table => ids planned for deletion (closed under foreign keys) */
    private array $plan = [];
    private ?array $foreignKeys = null;

    public function handle(): int
    {
        $users = DB::table('users')->where('email', 'like', self::EMAIL_PATTERN)->orderBy('id')->get(['id', 'name', 'email', 'role']);
        if ($users->isEmpty()) {
            $this->info('No demo data found (no ' . self::EMAIL_PATTERN . ' accounts).');
            return self::SUCCESS;
        }

        $this->collect('users', $users->pluck('id')->all());

        // A real customer's order containing a demo product: stop, a human has to decide.
        $demoOrders = array_keys($this->plan['orders'] ?? []);
        $foreign = DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereIn('oi.product_id', array_keys($this->plan['products'] ?? []) ?: [0])
            ->when($demoOrders, fn ($q) => $q->whereNotIn('o.id', $demoOrders))
            ->distinct()->pluck('o.order_number');
        if ($foreign->isNotEmpty()) {
            $this->error('Real orders contain demo products: ' . $foreign->take(20)->implode(', ')
                . ($foreign->count() > 20 ? ' …' : '') . '. Nothing was deleted.');
            return self::FAILURE;
        }

        $polymorphic = $this->polymorphicRows($users->pluck('id')->all());
        $images = Storage::disk('public')->exists(self::IMAGE_DIR) ? count(Storage::disk('public')->allFiles(self::IMAGE_DIR)) : 0;

        $this->report($users, $polymorphic, $images);

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was deleted.');
            return self::SUCCESS;
        }
        if (app()->environment('production') && !$this->option('force')) {
            $this->error('In production, run with --force (after a mysqldump).');
            return self::FAILURE;
        }
        if (!$this->option('force') && !$this->confirm('Delete all of this permanently? (take a mysqldump first)')) {
            $this->line('Cancelled.');
            return self::SUCCESS;
        }

        $productIds = array_keys($this->plan['products'] ?? []);
        // The plan holds every row that references a deleted row, so no reference is left dangling:
        // foreign key checks can be off for the duration, which frees us from ordering the deletes.
        DB::transaction(function () use ($polymorphic, $users) {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($polymorphic as [$table, $column, $typeColumn]) {
                    DB::table($table)->where($typeColumn, \App\Models\User::class)->whereIn($column, $users->pluck('id'))->delete();
                }
                foreach ($this->plan as $table => $ids) {
                    foreach (array_chunk(array_keys($ids), 500) as $chunk) {
                        DB::table($table)->whereIn('id', $chunk)->delete();
                    }
                }
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            }
        });

        Storage::disk('public')->deleteDirectory(self::IMAGE_DIR);
        $this->forgetSearchDocuments($productIds);
        Cache::forget('reco:pools:v2');
        Cache::forget('search:vocabulary');
        Cache::forget('search_php_vocab');

        $this->info('Demo data deleted.');
        return self::SUCCESS;
    }

    /** Plan the deletion of $ids in $table and, first, of every row that references them. */
    private function collect(string $table, array $ids): void
    {
        $new = array_values(array_diff($ids, array_keys($this->plan[$table] ?? [])));
        if (!$new) {
            return;
        }
        foreach ($new as $id) {
            $this->plan[$table][$id] = true;
        }

        foreach ($this->referencing($table) as [$childTable, $childColumn]) {
            $childIds = [];
            foreach (array_chunk($new, 1000) as $chunk) {
                array_push($childIds, ...DB::table($childTable)->whereIn($childColumn, $chunk)->pluck('id')->all());
            }
            if ($childIds) {
                $this->collect($childTable, $childIds);
            }
        }
    }

    /** @return array<int, array{0: string, 1: string}> [child table, column] pairs with a FK to $table.id */
    private function referencing(string $table): array
    {
        $this->foreignKeys ??= DB::table('information_schema.key_column_usage')
            ->where('table_schema', DB::getDatabaseName())
            ->whereNotNull('referenced_table_name')
            ->get(['table_name as child', 'column_name as col', 'referenced_table_name as parent'])
            ->groupBy('parent')->map(fn ($rows) => $rows->map(fn ($r) => [$r->child, $r->col])->all())->all();

        return $this->foreignKeys[$table] ?? [];
    }

    /** Morph tables pointing at the demo users (no FK): notifications, API tokens. */
    private function polymorphicRows(array $userIds): array
    {
        $out = [];
        foreach ([['notifications', 'notifiable_id', 'notifiable_type'], ['personal_access_tokens', 'tokenable_id', 'tokenable_type']] as $spec) {
            if (DB::getSchemaBuilder()->hasTable($spec[0])
                && DB::table($spec[0])->where($spec[2], \App\Models\User::class)->whereIn($spec[1], $userIds)->exists()) {
                $out[] = $spec;
            }
        }
        return $out;
    }

    private function report($users, array $polymorphic, int $images): void
    {
        $this->line('Demo accounts:');
        $this->table(['id', 'name', 'email', 'role'], $users->map(fn ($u) => (array) $u)->all());

        $products = array_keys($this->plan['products'] ?? []);
        if ($products) {
            $this->line(count($products) . ' products, e.g.: ' . DB::table('products')->whereIn('id', array_slice($products, 0, 6))
                ->pluck('name')->implode(', ') . ' …');
        }

        $rows = [];
        foreach ($this->plan as $table => $ids) {
            $rows[] = [$table, count($ids)];
        }
        sort($rows);
        foreach ($polymorphic as [$table, $column, $type]) {
            $rows[] = [$table, DB::table($table)->where($type, \App\Models\User::class)->whereIn($column, $users->pluck('id'))->count()];
        }
        $rows[] = ['files in storage/app/public/' . self::IMAGE_DIR, $images];
        $this->table(['Table', 'Rows to delete'], $rows);
    }

    private function forgetSearchDocuments(array $productIds): void
    {
        // Photo fingerprints go with their products (foreign keys); drop the cached photo index.
        if ($productIds) {
            app(\App\Services\Search\FingerprintIndex::class)->bump();
        }
    }
}
