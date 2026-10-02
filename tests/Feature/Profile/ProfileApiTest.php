<?php

namespace Tests\Feature\Profile;

use App\Mail\VerificationCodeMail;
use App\Models\Order;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Customer profile page: overview stats, editing, avatar, e-mail change,
 * password, and the "complete your profile before checkout" rule.
 *
 * Run only this folder:  php vendor/bin/phpunit tests/Feature/Profile
 */
class ProfileApiTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $role = 'client', array $extra = []): User
    {
        return User::create($extra + [
            'name'              => 'Profile Test ' . Str::random(4),
            'email'             => 'profile_' . Str::random(10) . '@test.local',
            'password'          => Hash::make('secret-pass1'),
            'role'              => $role,
            'is_active'         => true,
            'is_approved'       => true,
            'email_verified_at' => now(),
        ]);
    }

    private function addAddress(User $user): UserAddress
    {
        return UserAddress::create([
            'user_id' => $user->id, 'label' => 'Home', 'recipient_name' => 'Test Person',
            'wilaya' => 'Tunis', 'delegation' => 'La Marsa', 'address' => '12 rue de Carthage',
            'phone' => '22123456', 'is_default' => true,
        ]);
    }

    private function makeOrder(User $user, string $status, float $total): Order
    {
        return Order::create([
            'user_id' => $user->id, 'order_number' => 'TST-' . Str::random(10),
            'total_amount' => $total, 'status' => $status, 'payment_status' => 'unpaid',
        ]);
    }

    public function test_overview_requires_auth(): void
    {
        $this->getJson('/api/profile/overview')->assertStatus(401);
    }

    public function test_overview_returns_real_counts(): void
    {
        $user = $this->makeUser();
        $this->makeOrder($user, 'pending', 10);
        $this->makeOrder($user, 'delivered', 50.5);
        $this->makeOrder($user, 'completed', 20);
        $this->makeOrder($user, 'cancelled', 99);
        $this->makeOrder($this->makeUser(), 'delivered', 1000); // another customer

        Sanctum::actingAs($user);
        $this->getJson('/api/profile/overview')->assertOk()
            ->assertJsonPath('data.stats.orders.total', 4)
            ->assertJsonPath('data.stats.orders.pending', 1)
            ->assertJsonPath('data.stats.orders.delivered', 2)
            ->assertJsonPath('data.stats.orders.cancelled', 1)
            ->assertJsonPath('data.stats.total_spent', 70.5)
            ->assertJsonPath('data.stats.favorites', 0)
            ->assertJsonCount(4, 'data.recent_orders')
            ->assertJsonPath('data.recent_orders.0.status_group', 'cancelled')
            ->assertJsonPath('data.profile.completion.complete', false);
    }

    public function test_zero_data_user_gets_zeros_not_errors(): void
    {
        Sanctum::actingAs($this->makeUser());
        $this->getJson('/api/profile/overview')->assertOk()
            ->assertJsonPath('data.stats.orders.total', 0)
            ->assertJsonPath('data.stats.total_spent', 0)
            ->assertJsonCount(0, 'data.recent_orders');
        $this->getJson('/api/profile/reviews')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/profile/followed-sellers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_update_profile_validates_and_rebuilds_name(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['first_name' => 'A1', 'phone' => '12345678'])
            ->assertStatus(422)->assertJsonValidationErrors(['first_name', 'phone']);

        $this->putJson('/api/profile', [
            'first_name' => ' Amira ', 'last_name' => 'Ben Salah', 'phone' => '+216 22 333 444',
            'date_of_birth' => '1995-04-12', 'gender' => 'female',
        ])->assertOk()->assertJsonPath('data.phone', '22333444');

        $user->refresh();
        $this->assertSame('Amira Ben Salah', $user->name);
        $this->assertSame('1995-04-12', $user->date_of_birth->format('Y-m-d'));

        $this->putJson('/api/profile', ['date_of_birth' => now()->subYears(5)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('date_of_birth');
    }

    public function test_profile_completion_and_checkout_gate(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/checkout', [])->assertStatus(403)->assertJsonPath('code', 'profile_incomplete');

        $user->update(['first_name' => 'Sami', 'last_name' => 'Trabelsi', 'phone' => '55123456']);
        $this->addAddress($user);
        $this->assertTrue($user->fresh()->profileCompletion()->isComplete());
        $this->assertSame(80, $user->fresh()->profileCompletion()->percent());

        // Past the gate: now it is ordinary checkout validation.
        $this->postJson('/api/checkout', [])->assertStatus(422);
    }

    public function test_sellers_and_admins_are_not_gated(): void
    {
        foreach (['seller', 'admin', 'delivery_guy'] as $role) {
            $user = $this->makeUser($role);
            $this->assertTrue($user->profileCompletion()->isComplete(), $role);
            Sanctum::actingAs($user);
            $this->assertNotSame(403, $this->postJson('/api/checkout', [])->status(), $role);
        }
    }

    public function test_login_payload_carries_profile_completed(): void
    {
        $user = $this->makeUser();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-pass1'])
            ->assertOk()->assertJsonPath('user.profile_completed', false);
    }

    public function test_password_change_requires_current_password(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/password', [
            'current_password' => 'wrong', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->putJson('/api/profile/password', [
            'current_password' => 'secret-pass1', 'password' => 'onlyletters', 'password_confirmation' => 'onlyletters',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->putJson('/api/profile/password', [
            'current_password' => 'secret-pass1', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertOk();
        $this->assertTrue(Hash::check('newpass123', $user->fresh()->password));
    }

    public function test_google_account_can_set_a_first_password(): void
    {
        $user = $this->makeUser('client', ['google_id' => 'g-' . Str::random(8), 'has_password' => false]);
        Sanctum::actingAs($user);

        $this->getJson('/api/profile')->assertJsonPath('data.has_password', false)->assertJsonPath('data.is_google', true);
        $this->putJson('/api/profile/password', ['password' => 'firstpass1', 'password_confirmation' => 'firstpass1'])->assertOk();
        $this->assertTrue($user->fresh()->has_password);
    }

    public function test_avatar_upload_is_reencoded_and_replaceable(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->post('/api/profile/avatar', ['avatar' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->post('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 800, 600)], ['Accept' => 'application/json'])
            ->assertOk();
        $first = $user->fresh()->avatar;
        $this->assertStringContainsString('/storage/avatars/' . $user->id . '/', $first);
        $path = 'avatars/' . $user->id . '/' . basename($first);
        Storage::disk('public')->assertExists($path);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([400, 400], [$w, $h]);

        $this->deleteJson('/api/profile/avatar')->assertOk();
        Storage::disk('public')->assertMissing($path);
        $this->assertNull($user->fresh()->avatar);
    }

    public function test_email_change_needs_the_code_from_the_new_inbox(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $new = 'new_' . strtolower(Str::random(8)) . '@test.local';

        $this->postJson('/api/profile/email', ['email' => $new, 'current_password' => 'bad'])->assertStatus(422);
        $this->postJson('/api/profile/email', ['email' => $new, 'current_password' => 'secret-pass1'])->assertOk();

        $code = null;
        Mail::assertSent(VerificationCodeMail::class, function ($m) use ($new, &$code) {
            $code = $m->code;
            return $m->hasTo($new);
        });

        $this->postJson('/api/profile/email/verify', ['code' => $code === '000000' ? '111111' : '000000'])->assertStatus(422);
        $this->assertNotSame($new, $user->fresh()->email);

        $this->postJson('/api/profile/email/verify', ['code' => $code])->assertOk()->assertJsonPath('data.email', $new);
    }

    public function test_notification_preferences_are_honoured(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $this->putJson('/api/profile/notifications', ['email_updates' => false])->assertOk()
            ->assertJsonPath('data.notification_preferences.email_updates', false)
            ->assertJsonPath('data.notification_preferences.in_app_updates', true);

        $this->assertFalse($user->fresh()->wantsNotification('email_updates'));
        $this->assertTrue($user->fresh()->wantsNotification('in_app_updates'));
    }

    public function test_cannot_touch_another_customers_address(): void
    {
        $address = $this->addAddress($this->makeUser());
        Sanctum::actingAs($this->makeUser());
        $this->deleteJson('/api/addresses/' . $address->id)->assertStatus(404);
    }
}
