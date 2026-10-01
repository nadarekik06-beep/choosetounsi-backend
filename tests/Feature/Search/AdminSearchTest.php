<?php

namespace Tests\Feature\Search;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Recommendation\MakesCatalog;
use Tests\TestCase;

/** php vendor/bin/phpunit tests/Feature/Search/AdminSearchTest.php */
class AdminSearchTest extends TestCase
{
    use DatabaseTransactions, MakesCatalog;

    public function test_admin_sees_missed_queries_most_searched_first(): void
    {
        $tag = 'zz' . Str::lower(Str::random(6));
        DB::table('search_missed_queries')->insert([
            ['query' => "$tag rare", 'day' => now()->toDateString(), 'searches' => 2, 'results' => 0, 'example' => "$tag Rare"],
            ['query' => "$tag often", 'day' => now()->toDateString(), 'searches' => 5, 'results' => 1, 'example' => "$tag often"],
            ['query' => "$tag often", 'day' => now()->subDay()->toDateString(), 'searches' => 4, 'results' => 2, 'example' => "$tag often"],
            ['query' => "$tag old", 'day' => now()->subDays(90)->toDateString(), 'searches' => 50, 'results' => 0, 'example' => null],
        ]);

        Sanctum::actingAs($this->makeUser('admin'));
        $rows = collect($this->getJson('/api/admin/search/missed?days=30&limit=500')->assertOk()->json('data'))
            ->filter(fn ($r) => str_starts_with($r['query'], $tag))->values();

        $this->assertSame(["$tag often", "$tag rare"], $rows->pluck('query')->all());
        $this->assertSame(9, $rows[0]['searches']);
        $this->assertSame(1, $rows[0]['min_results']);

        $zero = collect($this->getJson('/api/admin/search/missed?zero_only=1&limit=500')->json('data'))
            ->filter(fn ($r) => str_starts_with($r['query'], $tag))->pluck('query')->all();
        $this->assertSame(["$tag rare"], $zero);
    }

    public function test_only_admins(): void
    {
        Sanctum::actingAs($this->makeUser('seller'));
        $this->getJson('/api/admin/search/missed')->assertStatus(403);
    }

    public function test_health_reports_services_down(): void
    {
        Sanctum::actingAs($this->makeUser('admin'));
        $this->getJson('/api/admin/search/health')->assertOk()
            ->assertJsonPath('meilisearch', false)->assertJsonPath('embedder', null);
    }
}
