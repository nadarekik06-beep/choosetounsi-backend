<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The API's own authentication (/api/auth/*, Sanctum tokens). Replaces
 * Laravel's starter tests, which targeted server-rendered login / register
 * pages this API-only backend doesn't serve.
 */
class ApiAuthTest extends TestCase
{
    use DatabaseTransactions;

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'name'              => 'Auth ' . Str::random(5),
            'email'             => 'auth_' . Str::random(10) . '@test.local',
            'password'          => Hash::make('secret-pass1'),
            'role'              => 'client',
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);
    }

    public function test_login_returns_a_token_and_refuses_bad_credentials(): void
    {
        $user = $this->user();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-pass1'])
            ->assertOk()->assertJsonStructure(['token', 'user']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-pass'])->assertStatus(401);
        $this->postJson('/api/auth/login', ['email' => 'nobody@test.local', 'password' => 'secret-pass1'])->assertStatus(401);
    }

    public function test_unverified_or_deactivated_accounts_cannot_log_in(): void
    {
        $unverified = $this->user(['email_verified_at' => null]);
        $this->postJson('/api/auth/login', ['email' => $unverified->email, 'password' => 'secret-pass1'])
            ->assertStatus(403)->assertJsonPath('needs_verification', true);

        $inactive = $this->user(['is_active' => false]);
        $this->postJson('/api/auth/login', ['email' => $inactive->email, 'password' => 'secret-pass1'])->assertStatus(403);
    }

    public function test_password_can_be_reset_with_the_emailed_token(): void
    {
        Mail::fake();
        Notification::fake();
        $user = $this->user();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        Mail::assertSent(PasswordResetMail::class);

        $this->postJson('/api/auth/reset-password', [
            'token' => 'not-the-token', 'email' => $user->email,
            'password' => 'new-secret-9', 'password_confirmation' => 'new-secret-9',
        ])->assertStatus(422);

        $token = Password::broker()->createToken($user);
        $this->postJson('/api/auth/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'new-secret-9', 'password_confirmation' => 'new-secret-9',
        ])->assertOk();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'new-secret-9'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-pass1'])->assertStatus(401);
    }
}
