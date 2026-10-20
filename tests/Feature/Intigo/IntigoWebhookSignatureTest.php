<?php

namespace Tests\Feature\Intigo;

use App\Jobs\Intigo\ProcessIntigoEvent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * POST /api/webhooks/intigo as Intigo sends it (official docs): headers
 * X-Webhook-Event / X-Webhook-Timestamp / X-Webhook-Signature, signature
 * "sha256=" + HMAC-SHA256(raw body, secret). Signed → 200 + queued job;
 * missing / bad signature → 401, nothing stored, nothing queued.
 */
class IntigoWebhookSignatureTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.intigo.webhook_secret' => self::SECRET]);
        Queue::fake();
    }

    private function body(string $nid = 'TEST-NOT-A-PARCEL-1'): string
    {
        return json_encode([
            'event'      => 'parcel.status_changed',
            'event_type' => 'delivered',
            'timestamp'  => '2026-10-20T10:00:00.000+01:00',
            'data'       => ['nid' => $nid, 'old_status' => 4000, 'new_status' => 5000, 'status_label' => 'Livré'],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function send(string $body, ?string $signature)
    {
        $server = [
            'CONTENT_TYPE'             => 'application/json',
            'HTTP_X_WEBHOOK_EVENT'     => 'parcel.status_changed',
            'HTTP_X_WEBHOOK_TIMESTAMP' => '2026-10-20T10:00:00.000+01:00',
        ];
        if ($signature !== null) {
            $server['HTTP_X_WEBHOOK_SIGNATURE'] = $signature;
        }
        return $this->call('POST', '/api/webhooks/intigo', [], [], [], $server, $body);
    }

    public function test_a_correctly_signed_webhook_is_accepted_and_queued(): void
    {
        $body = $this->body();
        $this->send($body, 'sha256=' . hash_hmac('sha256', $body, self::SECRET))
            ->assertOk()
            ->assertJson(['success' => true, 'duplicate' => false]);

        $event = DB::table('intigo_webhook_events')->where('nid', 'TEST-NOT-A-PARCEL-1')->first();
        $this->assertNotNull($event);
        $this->assertSame(5000, (int) $event->new_status);
        $this->assertNull($event->processed_at, 'applied by the queue worker, not in the request');
        Queue::assertPushedOn(ProcessIntigoEvent::QUEUE, ProcessIntigoEvent::class, fn ($job) => $job->eventId === (int) $event->id);

        // Intigo retry of the same event: 200, stored once, not queued again
        $this->send($body, 'sha256=' . hash_hmac('sha256', $body, self::SECRET))
            ->assertOk()->assertJson(['duplicate' => true]);
        $this->assertSame(1, DB::table('intigo_webhook_events')->where('nid', 'TEST-NOT-A-PARCEL-1')->count());
        Queue::assertPushed(ProcessIntigoEvent::class, 1);
    }

    public function test_a_bad_or_missing_signature_is_refused_with_401(): void
    {
        Log::spy();
        $body = $this->body('TEST-NOT-A-PARCEL-2');

        $this->send($body, 'sha256=' . hash_hmac('sha256', $body, 'wrong-secret'))->assertStatus(401);
        $this->send($body, 'sha256=deadbeef')->assertStatus(401);
        $this->send($body, hash_hmac('sha256', $body, self::SECRET))->assertStatus(401);   // no "sha256=" prefix
        $this->send($body, null)->assertStatus(401);

        $this->assertSame(0, DB::table('intigo_webhook_events')->where('nid', 'TEST-NOT-A-PARCEL-2')->count());
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('warning')->with('[Intigo webhook] invalid signature: refused', \Mockery::on(fn ($ctx) => !array_key_exists('body', $ctx)))->times(3);
        Log::shouldHaveReceived('warning')->with('[Intigo webhook] missing signature: refused', \Mockery::any())->once();
    }

    public function test_the_route_needs_no_login_and_skips_the_api_group_limit(): void
    {
        $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/webhooks/intigo', 'POST'));
        $middleware = app('router')->gatherRouteMiddleware($route);

        $this->assertEmpty(array_filter($middleware, fn ($m) => is_string($m) && str_contains($m, 'Authenticate')));
        $this->assertContains('Illuminate\Routing\Middleware\ThrottleRequests:600,1', $middleware);
        $this->assertNotContains('Illuminate\Routing\Middleware\ThrottleRequests:api', $middleware);
    }
}
