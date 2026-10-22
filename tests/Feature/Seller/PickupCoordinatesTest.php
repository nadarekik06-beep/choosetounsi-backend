<?php

namespace Tests\Feature\Seller;

use App\Mail\IntigoAlertMail;
use App\Models\IntigoZone;
use App\Models\SellerPickupAddress;
use App\Models\User;
use App\Notifications\Delivery\IntigoAdminNotification;
use App\Services\Intigo\HttpIntigoClient;
use App\Services\Intigo\IntigoClient;
use App\Services\Intigo\ZoneGeocoder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Intigo\IntigoTestHelpers;
use Tests\TestCase;

/**
 * The pickup point chain: the pin the seller places on the map (application / profile)
 * → seller_pickup_addresses → the admin preview → POST /pickup-addresses at Intigo, unchanged,
 * [latitude, longitude] in that order, as numbers, spec field order. Intigo is mocked (live client).
 */
class PickupCoordinatesTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    private const BASE = 'https://api.intigo.test/api/v3';
    // Omaima's pin (Sfax): latitude 34.x, longitude 10.x. A swap would give latitude 10.x.
    private const LAT = 34.7564733;
    private const LNG = 10.7508552;

    private IntigoZone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
        config(['intigo.base_url' => self::BASE, 'intigo.api_key' => 'test-key-0123456789', 'intigo.alert_email' => 'alerts@example.com']);
        // Zone centre ~1.9 km from the pin (delegation precision, warning at 5 km)
        $this->zone = $this->pickupZone(IntigoZone::SECTEUR, 'route kaied mhamed', 'Sfax Medina', 'Sfax',
            ['latitude' => 34.7392689, 'longitude' => 10.7549009, 'geocode_precision' => 'delegation']);
    }

    private function application(array $overrides = []): array
    {
        return $overrides + [
            'full_name' => 'Vendeuse Test', 'phone_number' => '28522353', 'business_name' => 'Librairie ' . Str::random(5),
            'business_category' => 'books',
            'contact_name' => 'Vendeuse Test', 'phone' => '28522353',
            'adresse_complete' => 'Route kaied mohamed km 2', 'intigo_zone_id' => $this->zone->id, 'code_postal' => '3000',
            'latitude' => self::LAT, 'longitude' => self::LNG,
        ];
    }

    /** Seller who applied with the pin, address verified by an admin; Intigo answers with a live client mock. */
    private function appliedAndVerified(array $pickupList = null): User
    {
        $user = $this->makeUser('client');
        $this->as($user)->postJson('/api/seller-applications', $this->application())->assertCreated();
        $this->as($this->admin)->postJson("/api/admin/sellers/{$user->id}/pickup/verify")->assertOk();

        $this->app->bind(IntigoClient::class, HttpIntigoClient::class);
        Http::fake(function (Request $r) use ($pickupList) {
            if (!str_starts_with($r->url(), self::BASE)) {
                return Http::response([], 200);
            }
            if ($r->method() === 'POST') {
                return Http::response(['success' => true, 'pickup_address' => ['id' => 23378, 'index' => 1,
                    'street_address' => $r['street_address'], 'city_name' => 'Sfax', 'district_name' => 'Sfax Medina', 'has_gps' => true]], 201);
            }
            return Http::response(['success' => true, 'pickup_addresses' => $pickupList ?? [[
                'id' => 23378, 'index' => 1, 'street_address' => 'Route kaied mohamed km 2 — Tél : +216 28 522 353 — Carte : https://maps.google.com/?q=34.7564733,10.7508552',
                'city_name' => 'Sfax', 'district_name' => 'Sfax Medina', 'has_gps' => true]]]);
        });
        return $user;
    }

    private function sentPickupBody(): array
    {
        $sent = Http::recorded(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::BASE . '/pickup-addresses/');
        $this->assertCount(1, $sent, 'one POST /pickup-addresses/');
        return json_decode($sent->first()[0]->body(), true);
    }

    public function test_the_pin_reaches_intigo_unchanged_in_latitude_longitude_order(): void
    {
        Mail::fake();
        $user = $this->appliedAndVerified();

        // DB: exactly the pin, 7 decimals, no zone centre, no geocoding
        $row = SellerPickupAddress::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('34.7564733', (string) $row->latitude);
        $this->assertSame('10.7508552', (string) $row->longitude);

        // Admin preview = what will be sent
        $preview = $this->as($this->admin)->getJson("/api/admin/sellers/{$user->id}/pickup")->assertOk()->json('data.pickup.intigo_payload');

        $this->as($this->admin)->postJson("/api/admin/sellers/{$user->id}/pickup/intigo-create")->assertOk()
            ->assertJsonPath('readback', []);
        $body = $this->sentPickupBody();

        // Official spec field names, in the spec's order
        $this->assertSame(['street_address', 'city_id', 'district_id', 'neighborhood_id', 'latitude', 'longitude', 'is_primary'], array_keys($body));
        // Numbers, the seller's pin, latitude first
        $this->assertSame(self::LAT, $body['latitude']);
        $this->assertSame(self::LNG, $body['longitude']);
        $this->assertIsFloat($body['latitude']);
        $this->assertIsInt($body['city_id']);
        $this->assertSame($this->zone->intigo_id, $body['neighborhood_id']);
        // Swap guard: Tunisia's latitudes are 30-38, longitudes 7-12
        $this->assertTrue($body['latitude'] > 30 && $body['latitude'] < 38, 'latitude must be the 34.x value (lat/lng swapped?)');
        $this->assertTrue($body['longitude'] > 7 && $body['longitude'] < 12, 'longitude must be the 10.x value (lat/lng swapped?)');
        $this->assertTrue(ZoneGeocoder::inTunisia($body['latitude'], $body['longitude']));
        $this->assertFalse(ZoneGeocoder::inTunisia($body['longitude'], $body['latitude']), 'the swapped pair is outside Tunisia');

        $this->assertSame($preview + ['is_primary' => false], $body, 'the admin preview is exactly what is sent');
        $this->assertSame(1, $user->fresh()->intigo_pickup_index);
        Mail::assertNotQueued(IntigoAlertMail::class);
    }

    public function test_the_profile_map_pin_is_what_an_update_sends(): void
    {
        Mail::fake();
        $user = $this->appliedAndVerified();
        $this->as($this->admin)->postJson("/api/admin/sellers/{$user->id}/pickup/intigo-create")->assertOk();
        $user->forceFill(['role' => 'seller', 'is_approved' => true])->save();

        // The seller moves the pin in the profile (settings), the admin verifies and updates Intigo
        $this->as($user)->putJson('/api/seller/pickup-address', $this->application(['latitude' => 34.7601234, 'longitude' => 10.7487654]))->assertOk();
        $this->as($this->admin)->postJson("/api/admin/sellers/{$user->id}/pickup/verify")->assertOk();
        $this->as($this->admin)->postJson("/api/admin/sellers/{$user->id}/pickup/intigo-create")->assertOk();

        $patch = Http::recorded(fn (Request $r) => $r->method() === 'PATCH')->first();
        $this->assertNotNull($patch, 'an update is a PATCH on the same index');
        $this->assertSame(self::BASE . '/pickup-addresses/1', $patch[0]->url());
        $sent = json_decode($patch[0]->body(), true);
        $this->assertSame([34.7601234, 10.7487654], [$sent['latitude'], $sent['longitude']]);
    }

    public function test_a_swapped_pin_or_a_point_outside_tunisia_is_refused(): void
    {
        $user = $this->makeUser('client');
        foreach ([
            'swapped'        => [self::LNG, self::LAT],
            'Tebessa (DZ)'   => [35.40, 8.12],
            'El Kala (DZ)'   => [36.89, 8.44],
            'Zuwara (LY)'    => [32.93, 12.08],
            'Gulf, open sea' => [36.0, 11.0],
            'Paris'          => [48.85, 2.35],
        ] as $case => [$lat, $lng]) {
            $this->as($user)->postJson('/api/seller-applications', $this->application(['latitude' => $lat, 'longitude' => $lng]))
                ->assertStatus(422)->assertJsonValidationErrors('latitude');
        }
        $this->assertSame(0, SellerPickupAddress::where('user_id', $user->id)->count());
    }

    public function test_border_towns_and_islands_are_accepted(): void
    {
        foreach ([[36.954, 8.758], [36.45, 8.44], [34.39, 7.94], [33.73, 7.59], [33.14, 11.22], [31.97, 10.71],
                  [30.25, 9.55], [33.875, 10.857], [34.71, 11.20], [37.274, 9.874], [36.847, 11.093], [self::LAT, self::LNG]] as [$lat, $lng]) {
            $this->assertTrue(ZoneGeocoder::inTunisia($lat, $lng), "{$lat},{$lng} is in Tunisia");
        }
    }

    public function test_a_pin_more_than_5_km_from_the_delegation_is_flagged(): void
    {
        $near = ZoneGeocoder::check($this->zone, self::LAT, self::LNG);
        $this->assertFalse($near['far']);
        $this->assertSame(5, $near['limit_km']);
        $this->assertEqualsWithDelta(1.9, $near['distance_km'], 0.2);

        $far = ZoneGeocoder::check($this->zone, 34.80, 10.75);   // ~6.8 km north of the centre
        $this->assertTrue($far['far']);

        $user = $this->makeUser('client');
        $this->as($user)->postJson('/api/seller-applications', $this->application(['latitude' => 34.80, 'longitude' => 10.75]))->assertCreated();
        $this->as($this->admin)->getJson("/api/admin/sellers/{$user->id}/pickup")->assertOk()->assertJsonPath('data.pickup.pin_check.far', true);
    }

    public function test_read_back_alerts_the_admins_when_intigo_differs(): void
    {
        Mail::fake();
        $user = $this->appliedAndVerified([[
            'id' => 23378, 'index' => 1, 'street_address' => 'Route kaied mohamed km 2 — Tél : +216 28 522 353 — Carte : https://maps.google.com/?q=34.7564733,10.7508552',
            'city_name' => 'Sfax', 'district_name' => 'Sakiet Ezzit', 'has_gps' => false,
        ]]);

        $res = $this->as($this->admin)->postJson("/api/admin/sellers/{$user->id}/pickup/intigo-create")->assertOk();
        $problems = $res->json('readback');
        $this->assertCount(2, $problems);
        $this->assertStringContainsString('has_gps false', $problems[0]);
        $this->assertStringContainsString('Sakiet Ezzit', $problems[1]);
        $this->assertStringContainsString('WARNING', $res->json('message'));

        Mail::assertQueued(IntigoAlertMail::class, fn (IntigoAlertMail $m) => $m->hasTo('alerts@example.com') && str_contains($m->reason, 'has_gps false'));
        $this->assertTrue($this->admin->fresh()->notifications()->where('type', IntigoAdminNotification::class)->exists());
    }

    public function test_the_address_sent_to_intigo_carries_a_map_link_of_the_exact_pin(): void
    {
        Mail::fake();
        $user = $this->appliedAndVerified();
        $preview = $this->as($this->admin)->getJson("/api/admin/sellers/{$user->id}/pickup")->assertOk()->json('data.pickup');

        $expected = 'Route kaied mohamed km 2 — Tél : +216 28 522 353 — Carte : https://maps.google.com/?q=34.7564733,10.7508552';
        $this->assertSame($expected, $preview['intigo_street_address'], 'admin card shows the full text');
        $this->assertSame($expected, $preview['intigo_payload']['street_address']);

        $this->as($this->admin)->postJson("/api/admin/sellers/{$user->id}/pickup/intigo-create")->assertOk();
        $sent = $this->sentPickupBody()['street_address'];
        $this->assertSame($expected, $sent);
        // Intigo prints this text on the bordereau the client sees: never the seller's name
        $this->assertStringNotContainsString('Vendeuse Test', $sent);
        $this->assertStringNotContainsString('Contact', $sent);
    }

    public function test_a_long_address_is_shortened_but_never_the_phone_or_the_map_link(): void
    {
        $user = $this->makeUser('client');
        $long = 'Immeuble ' . str_repeat('Les Jasmins, ', 14) . 'fin';   // < 200 chars, too long with contact + link
        $this->as($user)->postJson('/api/seller-applications', $this->application(['adresse_complete' => $long]))->assertCreated();

        $text = app(\App\Services\Sellers\PickupAddressService::class)
            ->intigoStreetAddress(SellerPickupAddress::where('user_id', $user->id)->firstOrFail());

        $this->assertLessThanOrEqual(255, mb_strlen($text));
        $this->assertStringEndsWith('Carte : https://maps.google.com/?q=34.7564733,10.7508552', $text);
        $this->assertStringContainsString('+216 28 522 353', $text);
        $this->assertStringStartsWith('Immeuble Les Jasmins', $text);
    }

    public function test_the_map_link_can_be_switched_off(): void
    {
        config(['intigo.pickup_map_link_in_address' => false]);
        $user = $this->makeUser('client');
        $this->as($user)->postJson('/api/seller-applications', $this->application())->assertCreated();
        $text = app(\App\Services\Sellers\PickupAddressService::class)
            ->intigoStreetAddress(SellerPickupAddress::where('user_id', $user->id)->firstOrFail());
        $this->assertSame('Route kaied mohamed km 2 — Tél : +216 28 522 353', $text);
        $this->assertSame('https://maps.google.com/?q=36.8,10.18', \App\Services\Sellers\PickupAddressService::mapLink('36.8000000', 10.18));
        $this->assertNull(\App\Services\Sellers\PickupAddressService::mapLink(null, 10.18));
    }

    public function test_fake_mode_does_not_read_back(): void
    {
        Mail::fake();
        $user = $this->makeUser('client');
        $this->as($user)->postJson('/api/seller-applications', $this->application())->assertCreated();
        $this->as($this->admin)->postJson("/api/admin/sellers/{$user->id}/pickup/verify")->assertOk();
        $this->scripted();
        $this->as($this->admin)->postJson("/api/admin/sellers/{$user->id}/pickup/intigo-create")->assertOk()->assertJsonPath('readback', null);
        Mail::assertNotQueued(IntigoAlertMail::class);
    }
}
