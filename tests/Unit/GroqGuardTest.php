<?php

namespace Tests\Unit;

use App\Services\Chat\GroqClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The test suite never calls the real Groq API (it would spend the free quota):
 * an un-faked request is refused, and tests/TestCase fails the test that made it.
 */
class GroqGuardTest extends TestCase
{
    public function test_a_real_groq_request_is_refused_during_tests(): void
    {
        $this->assertSame('gsk_test_fake_key_never_calls_the_real_api', config('services.groq.key'), 'the tests use a fake key');

        try {
            app(GroqClient::class)->chat([['role' => 'user', 'content' => 'hi']], 'guard_test');
            $this->fail('A real request was not refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('real Groq request', $e->getMessage());
        }

        $this->assertSame(1, GroqClient::$strayCalls);
        GroqClient::$strayCalls = 0; // this test provoked it on purpose: do not fail in tearDown
    }

    public function test_a_faked_request_is_allowed(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200)]);

        $this->assertSame('ok', app(GroqClient::class)->chat([['role' => 'user', 'content' => 'hi']], 'guard_test'));
        $this->assertSame(0, GroqClient::$strayCalls);
    }
}
