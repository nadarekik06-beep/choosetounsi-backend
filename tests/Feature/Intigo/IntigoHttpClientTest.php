<?php

namespace Tests\Feature\Intigo;

use App\Jobs\Intigo\SendIntigoParcel;
use App\Mail\IntigoAlertMail;
use App\Services\Intigo\HttpIntigoClient;
use App\Services\Intigo\IntigoClient;
use App\Services\Intigo\IntigoException;
use App\Services\Intigo\ParcelSender;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The live client (HttpIntigoClient) against a mocked Intigo v3 API: exact
 * endpoint, X-API-Key header, body, answers and error classes as documented in
 * https://api.intigo.net/openapi.json (Partner v3 API 1.4.0). No network.
 */
class IntigoHttpClientTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    private const BASE = 'https://api.intigo.test/api/v3';
    private const KEY  = 'test-intigo-key-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
        // Every test fakes Http (Laravel 8 has no preventStrayRequests); the base URL is not a real host anyway
        config(['intigo.base_url' => self::BASE, 'intigo.api_key' => self::KEY, 'intigo.alert_email' => 'alerts@example.com']);
    }

    private function live(): HttpIntigoClient
    {
        return app(HttpIntigoClient::class);
    }

    private function payload(): array
    {
        return [
            'recipient_name' => 'Client Test', 'phone1' => '22123456', 'destination_address' => 'Rue de la Liberté 12',
            'city_name' => 'Tunis', 'district_name' => 'Bab Bhar', 'price' => 58.0, 'package_size' => 1,
            'description' => '1 × Robe', 'additional_info' => 'ORD-ABC-S1', 'can_open' => false, 'pickup_index' => 3, 'cid' => 'CT-SO-1',
        ];
    }

    public function test_201_returns_the_nid_and_sends_the_documented_request(): void
    {
        Http::fake([self::BASE . '/parcels/by-name' => Http::response(['success' => true, 'nid' => 'INT-123456', 'district_name' => 'Bab Bhar', 'district_fallback' => null], 201)]);

        $created = $this->live()->createParcel($this->payload(), null);

        $this->assertSame('INT-123456', $created['nid']);
        $this->assertSame('Bab Bhar', $created['district_name']);
        $this->assertNull($created['district_fallback']);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST'
                && $r->url() === self::BASE . '/parcels/by-name'
                && $r->hasHeader('X-API-Key', self::KEY)
                && $r->hasHeader('X-Intigo-Platform', 'choosetounsi')
                && $r->hasHeader('X-Intigo-Shop', 'choosetounsi.tn')
                && $r->data() === $this->payload();
        });
    }

    public function test_a_guessed_delegation_is_reported(): void
    {
        Http::fake([self::BASE . '/parcels/by-name' => Http::response(['success' => true, 'nid' => 'INT-9', 'district_name' => 'Bab Bhar', 'district_fallback' => 'chef_lieu'], 201)]);
        $this->assertTrue($this->live()->createParcel($this->payload())['district_fallback']);
    }

    /** @return array<string, array{int, mixed, string, bool, string}> */
    public static function errors(): array
    {
        return [
            '400 unknown city'      => [400, ['detail' => 'Ville inconnue'], 'validation', false, 'Rejected by Intigo: Ville inconnue'],
            '422 validation'        => [422, ['detail' => [['loc' => ['body', 'phone1'], 'msg' => 'at least 8 digits', 'type' => 'value_error']]], 'validation', false, 'phone1: at least 8 digits'],
            '401 bad key'           => [401, ['detail' => 'Invalid API key'], 'auth', false, 'refused the API key'],
            '402 balance'           => [402, ['detail' => 'Solde insuffisant'], 'balance', false, 'insufficient account balance'],
            '404 pickup'            => [404, ['detail' => 'Pickup address not found'], 'not_found', false, 'Not found at Intigo'],
            '409 conflict'          => [409, ['detail' => 'Conflict'], 'conflict', false, 'status conflict'],
            '429 rate limited'      => [429, ['detail' => 'Too many requests'], 'rate_limited', true, 'too many requests'],
            '500 server'            => [500, 'Internal Server Error', 'server', true, 'Intigo server error'],
            '503 unavailable'       => [503, ['detail' => 'Service Unavailable'], 'unavailable', true, 'temporarily unavailable'],
        ];
    }

    /** @dataProvider errors */
    public function test_errors_are_classified(int $status, mixed $body, string $kind, bool $retryable, string $message): void
    {
        Http::fake([self::BASE . '/parcels/by-name' => Http::response($body, $status)]);

        try {
            $this->live()->createParcel($this->payload());
            $this->fail("HTTP {$status} must throw");
        } catch (IntigoException $e) {
            $this->assertSame($kind, $e->kind);
            $this->assertSame($retryable, $e->retryable);
            $this->assertSame($status, $e->httpStatus);
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_a_2xx_without_nid_is_ambiguous_and_retryable(): void
    {
        Http::fake([self::BASE . '/parcels/by-name' => Http::response(['success' => true], 201)]);
        try {
            $this->live()->createParcel($this->payload());
            $this->fail('must throw');
        } catch (IntigoException $e) {
            $this->assertTrue($e->retryable);
        }
    }

    public function test_no_call_without_an_api_key(): void
    {
        config(['intigo.api_key' => '']);
        Http::fake();
        try {
            $this->live()->createParcel($this->payload());
            $this->fail('must throw');
        } catch (IntigoException $e) {
            $this->assertSame('auth', $e->kind);
        }
        Http::assertNothingSent();
    }

    public function test_cid_lookup_reads_the_pages_and_matches_the_cid_locally(): void
    {
        // GET /parcels/ has no cid filter: the client pages through the list (pagination.total_pages)
        Http::fake(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $page = (int) ($q['page'] ?? 1);
            $items = $page === 1
                ? [['nid' => 'INT-1', 'cid' => 'CT-SO-7', 'status' => 1000], ['nid' => 'INT-2', 'cid' => null, 'status' => 1000]]
                : [['nid' => 'INT-3', 'cid' => 'CT-SO-42', 'status' => 2000, 'status_label' => 'Enlevé']];
            return Http::response(['success' => true, 'parcels' => $items, 'pagination' => ['total' => 3, 'page' => $page, 'page_size' => 100, 'total_pages' => 2]]);
        });

        $found = $this->live()->findByCid('CT-SO-42');

        $this->assertSame(['INT-3'], array_column($found, 'nid'));
        Http::assertSentCount(2);
        Http::assertSent(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            return $r->method() === 'GET' && str_starts_with($r->url(), self::BASE . '/parcels/?')
                && ($q['page_size'] ?? null) === '100' && !isset($q['cid']) && $r->hasHeader('X-API-Key', self::KEY);
        });
    }

    public function test_cid_lookup_stops_at_the_last_page(): void
    {
        Http::fake([self::BASE . '/parcels/*' => Http::response(['success' => true, 'parcels' => [['nid' => 'INT-1', 'cid' => 'CT-SO-7']], 'pagination' => ['total' => 1, 'page' => 1, 'page_size' => 100, 'total_pages' => 1]])]);
        $this->assertSame([], $this->live()->findByCid('CT-SO-99'));
        Http::assertSentCount(1);
    }

    // ── Through the real send path (live client bound, Intigo mocked) ──────

    /** Requests that went to the (mocked) Intigo API, ignoring other services such as AI image indexing. */
    private function intigoCalls(): \Illuminate\Support\Collection
    {
        return Http::recorded(fn (Request $r) => str_starts_with($r->url(), self::BASE));
    }

    private function preparedLiveParcel(): object
    {
        $this->app->bind(IntigoClient::class, HttpIntigoClient::class);
        $seller = $this->sellerWithPickup(3);
        $this->addToCart($this->product($seller, 40));
        [, $orderId] = $this->placeAndConfirm();
        $so = $this->parcel($orderId, $seller);
        $this->prepare($so, $seller);
        return $so;
    }

    public function test_201_stores_the_nid_on_the_parcel(): void
    {
        Mail::fake();
        Http::fake([self::BASE . '/parcels/by-name' => Http::response(['success' => true, 'nid' => 'INT-777', 'district_name' => null, 'district_fallback' => null], 201)]);

        $so = $this->preparedLiveParcel();

        $fresh = $this->so($so);
        $this->assertSame('INT-777', $fresh->intigo_nid);
        $this->assertSame('sent', $fresh->intigo_sync_status);
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE . '/parcels/by-name' && $r['cid'] === 'CT-SO-' . $so->id && $r['pickup_index'] === 3);
        Mail::assertNotQueued(IntigoAlertMail::class);
    }

    public function test_402_keeps_the_order_marks_the_parcel_failed_and_alerts_by_mail_and_bell(): void
    {
        Mail::fake();
        Http::fake([self::BASE . '/parcels/by-name' => Http::response(['detail' => 'Solde insuffisant'], 402)]);

        $so = $this->preparedLiveParcel();
        (new SendIntigoParcel($so->id))->handle(app(ParcelSender::class));   // a stray job retry: never re-sent

        $fresh = $this->so($so);
        $this->assertNull($fresh->intigo_nid);
        $this->assertSame('failed', $fresh->intigo_sync_status);
        $this->assertStringContainsString('insufficient account balance', $fresh->intigo_error);
        $this->assertSame('confirmed', $fresh->status, 'the order is kept');
        $this->assertNotNull($fresh->prepared_at);
        $this->assertCount(1, $this->intigoCalls(), 'one call, never retried');

        Mail::assertQueued(IntigoAlertMail::class, fn (IntigoAlertMail $m) => $m->hasTo('alerts@example.com') && $m->sellerOrderId === $so->id
            && str_contains($m->reason, 'insufficient account balance'));
        Mail::assertQueued(IntigoAlertMail::class, 1);
        $this->assertTrue($this->admin->fresh()->notifications()->where('type', \App\Notifications\Delivery\IntigoAdminNotification::class)->exists());
    }

    public function test_400_is_alerted_and_an_admin_retry_sends_it_once_fixed(): void
    {
        Mail::fake();
        $answers = [Http::response(['detail' => 'Ville inconnue'], 400), Http::response(['success' => true, 'nid' => 'INT-888'], 201)];
        Http::fake(function (Request $r) use (&$answers) {
            if (!str_starts_with($r->url(), self::BASE)) {
                return Http::response([], 200);   // other services (AI image indexing) are not under test
            }
            return $r->method() === 'GET'
                ? Http::response(['success' => true, 'parcels' => [], 'pagination' => ['total' => 0, 'page' => 1, 'page_size' => 100, 'total_pages' => 0]])
                : array_shift($answers);
        });

        $so = $this->preparedLiveParcel();
        $this->assertSame('failed', $this->so($so)->intigo_sync_status);
        Mail::assertQueued(IntigoAlertMail::class, fn (IntigoAlertMail $m) => str_contains($m->reason, 'Ville inconnue'));

        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/intigo/retry")->assertOk()->assertJsonPath('result', 'sent');
        $this->assertSame('INT-888', $this->so($so)->intigo_nid);
    }

    public function test_the_alert_mail_is_not_sent_without_an_address(): void
    {
        Mail::fake();
        config(['intigo.alert_email' => null]);
        Http::fake([self::BASE . '/parcels/by-name' => Http::response(['detail' => 'Solde insuffisant'], 402)]);

        $this->preparedLiveParcel();

        Mail::assertNothingQueued();
    }
}
