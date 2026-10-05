<?php

namespace App\Console\Commands\Search;

use App\Services\Search\Fingerprint;
use App\Services\Search\FingerprintClient;
use App\Services\Search\ImageSearch;
use App\Services\Search\SearchUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Photo search from the command line, for tuning config/search.php → image: the predicted
 * category and the top results with their similarity and score. Nothing is cached or logged.
 *
 *   php artisan image-search:try C:\photos\robe.jpg C:\photos\sac.jpg
 *   php artisan image-search:try robe.jpg --exclude=32          leave product 32 out (a dress we don't sell)
 *   php artisan image-search:try robe.jpg --top=10
 */
class TryImageSearch extends Command
{
    protected $signature = 'image-search:try {photos* : Image files (jpg, png, webp)}
                            {--exclude= : Product ids to leave out, comma separated (also out of the category centroids)}
                            {--top=5 : Results shown per photo}';
    protected $description = 'Run photo search on local image files and print the ranking';

    public function handle(FingerprintClient $client, ImageSearch $search): int
    {
        $exclude = array_filter(array_map('intval', explode(',', (string) $this->option('exclude'))));
        $top = max(1, (int) $this->option('top'));

        foreach ($this->argument('photos') as $path) {
            if (!is_file($path)) {
                $this->error("Not a file: $path");
                continue;
            }
            try {
                $query = $client->query(file_get_contents($path));
                $r = $search->rank(Fingerprint::unpack(Fingerprint::pack($query['vector'])), $query['color'], $exclude);
            } catch (SearchUnavailable $e) {
                $this->error($e->getMessage());
                return self::FAILURE;
            }

            $p = $r['prediction'];
            $names = $this->groupNames($p);
            $this->newLine();
            $this->info(basename($path) . "  (color {$query['color']})");
            $this->line(sprintf('  Predicted: %s  centroid %.3f, margin %.3f, %s%s%s',
                $names[0], $p['similarity'] ?? 0, $p['margin'] ?? 0, ($p['confident'] ?? false) ? 'confident' : 'not confident',
                $names[1] ? ", 2nd: {$names[1]}" : '', $r['fallback'] ? '  → FALLBACK (nothing above the threshold)' : ''));

            $rows = [];
            foreach (['exact' => 'exact', 'similar' => $r['fallback'] ? 'fallback' : 'similar'] as $section => $label) {
                foreach ($r[$section] as $pid => $hit) {
                    $rows[] = [$label, $pid, $this->productLabel($pid), number_format($hit['similarity'], 3), number_format($hit['score'], 3),
                               $hit['color_option_id'] ? "color #{$hit['color_option_id']}" : ''];
                }
            }
            $this->table(['Section', 'Id', 'Product (category)', 'Similarity', 'Score', 'Photo'], array_slice($rows, 0, $top));
            if (!$rows) {
                $this->line('  No result.');
            }
        }
        return self::SUCCESS;
    }

    private function productLabel(int $id): string
    {
        $row = DB::table('products as p')->leftJoin('subcategories as s', 's.id', '=', 'p.subcategory_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.id', $id)->first(['p.name', 's.name as sub', 'c.name as cat']);
        return $row ? mb_strimwidth($row->name, 0, 34, '…') . ' (' . ($row->sub ?? $row->cat) . ')' : "#$id";
    }

    /** @return array{0: string, 1: ?string} */
    private function groupNames(?array $p): array
    {
        $name = function (?string $group) {
            if (!$group) {
                return null;
            }
            $table = $group[0] === 's' ? 'subcategories' : 'categories';
            return DB::table($table)->where('id', (int) substr($group, 1))->value('name') ?? $group;
        };
        return [$name($p['group'] ?? null) ?? '—', $name($p['second'] ?? null)];
    }
}
