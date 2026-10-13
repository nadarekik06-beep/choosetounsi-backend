<?php

namespace Tests\Feature\Intigo;

use App\Models\IntigoZone;
use App\Services\Intigo\FakeIntigoClient;
use App\Services\Intigo\IntigoException;
use App\Services\Intigo\IntigoLogger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * intigo:sync-zones builds Intigo's "Zone (Délégation/Secteur)" list from the
 * (faked) /regions endpoints: Intigo's spelling and ids, resumable, idempotent.
 * --no-snapshot everywhere: tests never rewrite database/data/intigo_regions.json.
 */
class IntigoZonesSyncTest extends TestCase
{
    use DatabaseTransactions;

    private function fakeIntigo(): void
    {
        $base = config('intigo.base_url');
        Http::fake([
            "{$base}/regions/cities" => Http::response(['success' => true, 'cities' => [
                ['id' => 9701, 'name' => 'Ariana Zz', 'name_ar' => 'أريانة'],
            ]]),
            "{$base}/regions/cities/9701/districts" => Http::response(['success' => true, 'districts' => [
                ['id' => 9801, 'name' => 'La Soukra Zz', 'name_ar' => 'سكرة'],
                ['id' => 9802, 'name' => 'Raoued Zz', 'name_ar' => null],
            ]]),
            "{$base}/regions/districts/9801/neighborhoods" => Http::response(['success' => true, 'district_id' => 9801, 'neighborhoods' => [
                ['id' => 99901, 'name' => 'Aeroport Tunis Carthage', 'name_ar' => 'مطار تونس قرطاج', 'district_id' => 9801],
                ['id' => 99902, 'name' => 'Chotrana 1', 'name_ar' => null, 'district_id' => 9801],
            ]]),
            "{$base}/regions/districts/9802/neighborhoods" => Http::response(['success' => true, 'district_id' => 9802, 'neighborhoods' => []]),
            "{$base}/regions/districts/*" => Http::response(['success' => true, 'neighborhoods' => []]),
            "{$base}/regions/cities/*" => Http::response(['success' => true, 'districts' => []]),
        ]);
    }

    public function test_sync_builds_labelled_zones_and_resumes(): void
    {
        DB::table('intigo_districts')->update(['neighborhoods_synced_at' => now()]);   // pretend the real ones are done
        $this->fakeIntigo();

        $this->artisan('intigo:sync-zones', ['--delay' => 0, '--no-snapshot' => true])->assertExitCode(0);

        $zones = IntigoZone::whereIn('delegation_id', [9801, 9802])->orderBy('display_label')->pluck('type', 'display_label')->all();
        $this->assertSame([
            'Aeroport Tunis Carthage, La Soukra Zz (Ariana Zz)' => 'SECTEUR',
            'Chotrana 1, La Soukra Zz (Ariana Zz)'              => 'SECTEUR',
            'La Soukra Zz (Ariana Zz)'                          => 'DELEGATION',
            'Raoued Zz (Ariana Zz)'                             => 'DELEGATION',
        ], $zones);
        $airport = IntigoZone::where('type', 'SECTEUR')->where('intigo_id', 99901)->first();
        $this->assertSame([9701, 9801, 'Ariana Zz', 'La Soukra Zz'], [$airport->gouvernorat_id, $airport->delegation_id, $airport->gouvernorat, $airport->delegation]);
        $this->assertStringContainsString('مطار', $airport->search_text);

        // Re-run: nothing re-fetched, nothing duplicated
        $hoodCalls = fn () => Http::recorded(fn ($request) => str_contains($request->url(), '/neighborhoods'))->count();
        $before = $hoodCalls();
        $this->assertSame(2, $before);
        $this->artisan('intigo:sync-zones', ['--delay' => 0, '--no-snapshot' => true])->assertExitCode(0);
        $this->assertSame($before, $hoodCalls(), 'resumable: délégations already done are skipped');

        // --fresh re-fetches them
        $this->artisan('intigo:sync-zones', ['--delay' => 0, '--no-snapshot' => true, '--fresh' => true])->assertExitCode(0);
        $this->assertGreaterThan($before, $hoodCalls());
        $this->assertSame(1, IntigoZone::where('type', 'SECTEUR')->where('intigo_id', 99901)->count());
    }

    public function test_quartier_no_longer_listed_is_deactivated_not_deleted(): void
    {
        DB::table('intigo_districts')->update(['neighborhoods_synced_at' => now()]);
        $this->fakeIntigo();
        $this->artisan('intigo:sync-zones', ['--delay' => 0, '--no-snapshot' => true]);

        DB::table('intigo_neighborhoods')->where('id', 99902)->delete();
        app(\App\Services\Intigo\IntigoZones::class)->rebuild();

        $this->assertFalse(IntigoZone::where('type', 'SECTEUR')->where('intigo_id', 99902)->value('active'));
        $this->assertTrue(IntigoZone::where('type', 'SECTEUR')->where('intigo_id', 99901)->value('active'));
    }

    public function test_fake_client_validates_and_simulates_pickup_addresses(): void
    {
        $file   = storage_path('app/intigo/fake-pickup-addresses.json');
        $backup = is_file($file) ? file_get_contents($file) : null;
        try {
            DB::table('intigo_cities')->insertOrIgnore(['id' => 9701, 'name' => 'Ariana Zz', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('intigo_districts')->insertOrIgnore(['id' => 9801, 'city_id' => 9701, 'name' => 'La Soukra Zz', 'created_at' => now(), 'updated_at' => now()]);
            $fake = new FakeIntigoClient(app(IntigoLogger::class));

            try {
                $fake->createPickupAddress(['street_address' => 'x', 'city_id' => 9701, 'district_id' => 1]);
                $this->fail('district of another city accepted');
            } catch (IntigoException $e) {
                $this->assertStringContainsString('délégation', $e->getMessage());
            }

            $created = $fake->createPickupAddress(['street_address' => '12 Rue des Fleurs', 'city_id' => 9701, 'district_id' => 9801, 'latitude' => 36.87, 'longitude' => 10.23]);
            $this->assertGreaterThanOrEqual(3, $created['index']);
            $this->assertStringContainsString('12 Rue des Fleurs', $created['label']);

            $updated = $fake->updatePickupAddress($created['index'], ['street_address' => '5 Avenue de France']);
            $this->assertSame($created['index'], $updated['index']);
            $this->assertContains($created['index'], array_column($fake->pickupAddresses(), 'index'));
        } finally {
            $backup === null ? @unlink($file) : file_put_contents($file, $backup);
        }
    }
}
