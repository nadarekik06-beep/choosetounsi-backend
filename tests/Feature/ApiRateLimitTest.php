<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The "api" limiter: reads (GET) and writes have separate per-IP budgets,
 * so browsing the shop never uses up the budget for login / register.
 */
class ApiRateLimitTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.api_reads_per_minute' => 5, 'app.api_writes_per_minute' => 3]);
    }

    private function login(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/login', ['email' => 'nobody-ratelimit@example.com', 'password' => 'wrong-password']);
    }

    public function test_defaults_are_300_reads_and_60_writes_per_minute(): void
    {
        $this->refreshApplication();
        $this->assertSame(300, config('app.api_reads_per_minute'));
        $this->assertSame(60, config('app.api_writes_per_minute'));
    }

    public function test_reads_are_limited_by_the_read_budget(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/categories')->assertOk();
        }
        $this->getJson('/api/categories')->assertStatus(429);
    }

    public function test_writes_keep_their_own_strict_budget(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertNotSame(429, $this->login()->status());
        }
        $this->login()->assertStatus(429);
    }

    public function test_browsing_does_not_use_up_the_login_budget(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/categories')->assertOk();
        }
        $this->getJson('/api/categories')->assertStatus(429);

        // Reads are exhausted, login still answers normally
        $this->assertNotSame(429, $this->login()->status());
    }

    public function test_writes_do_not_use_up_the_read_budget(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->login();
        }
        $this->login()->assertStatus(429);

        $this->getJson('/api/categories')->assertOk();
    }
}
