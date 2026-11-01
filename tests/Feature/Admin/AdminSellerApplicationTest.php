<?php

namespace Tests\Feature\Admin;

use App\Mail\SellerApplicationApprovedMail;
use App\Mail\SellerApplicationSubmittedMail;
use App\Models\IntigoZone;
use App\Models\SellerApplication;
use App\Models\SellerPickupAddress;
use App\Models\SellerSubscription;
use App\Models\User;
use App\Notifications\NewSellerApplicationNotification;
use App\Notifications\SellerApplicationReviewedNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Intigo\IntigoTestHelpers;
use Tests\TestCase;

/**
 * Admin > Users "Create seller application": the storefront application filled
 * in by an admin, pending or approved on the spot (same approval as the
 * Applications page). The user-side flow keeps its behavior.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Admin/AdminSellerApplicationTest.php
 */
class AdminSellerApplicationTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    private IntigoZone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
        Storage::fake('public');
        $this->zone = $this->pickupZone(IntigoZone::SECTEUR, 'Admin App Testville', 'Admin App Deleg', 'Ariana',
            ['latitude' => 36.8736, 'longitude' => 10.2379]);
    }

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'full_name' => 'Sana Trabelsi', 'phone_number' => '22 123 456', 'business_name' => 'Atelier ' . Str::random(5),
            'business_category' => 'crafts', 'business_categories' => ['crafts'],
            'contact_name' => 'Sana Trabelsi', 'phone' => '22123456',
            'adresse_complete' => '12 Rue des Fleurs', 'intigo_zone_id' => $this->zone->id, 'code_postal' => '2035',
            'latitude' => 36.8741, 'longitude' => 10.2391,
        ];
    }

    private function url(User $user): string
    {
        return "/api/admin/users/{$user->id}/seller-application";
    }

    public function test_pending_application_without_images_appears_like_a_user_one(): void
    {
        Notification::fake();
        Mail::fake();
        $user = $this->makeUser('client');

        $this->as($this->admin)->postJson($this->url($user), $this->form(['approve_immediately' => false]))
            ->assertCreated()->assertJsonPath('data.status', 'pending');

        $app = SellerApplication::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('pending', $app->status);
        $this->assertSame($this->admin->id, $app->created_by_admin_id);
        $this->assertNull($app->profile_picture);
        $this->assertSame('client', $user->fresh()->role);

        // Pickup saved like a user submission: unverified, mirrored onto the application
        $pickup = SellerPickupAddress::where('user_id', $user->id)->firstOrFail();
        $this->assertTrue($pickup->isLocated());
        $this->assertFalse((bool) $pickup->address_verified_by_admin);
        $this->assertFalse((bool) $pickup->change_pending);
        $this->assertSame('Admin App Deleg', $app->fresh()->city);

        // No "new application" notice to the admins, no "submitted" e-mail
        Notification::assertNothingSent();
        Mail::assertNothingQueued();

        // Listed in Admin > Applications with who submitted it
        $row = collect($this->as($this->admin)->getJson('/api/admin/seller-applications?status=pending')->json('data.data'))
            ->firstWhere('id', $app->id);
        $this->assertSame($this->admin->id, $row['created_by_admin']['id']);
        $this->as($this->admin)->getJson("/api/admin/seller-applications/{$app->id}")
            ->assertJsonPath('data.created_by_admin.id', $this->admin->id);

        // Users list: the icon knows there's a pending application
        $listed = collect($this->as($this->admin)->getJson('/api/admin/users?search=' . urlencode($user->email))->json('data.data'))
            ->firstWhere('id', $user->id);
        $this->assertSame('pending', $listed['seller_application_status']);
    }

    public function test_approve_immediately_makes_the_user_a_seller_with_the_same_token(): void
    {
        Notification::fake();
        Mail::fake();
        $user  = $this->makeUser('client');
        $token = $user->createToken('api-token')->plainTextToken;

        $this->as($this->admin)->post($this->url($user), $this->form([
            'approve_immediately' => '1',
            'profile_picture'     => UploadedFile::fake()->image('logo.png'),
            'sample_images'       => [UploadedFile::fake()->image('a.jpg')],
        ]), ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'approved');

        $app = SellerApplication::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('approved', $app->status);
        $this->assertSame(\App\Models\SubscriptionPlan::defaultPlan()->slug, $app->plan);
        Storage::disk('public')->assertExists($app->profile_picture);
        $this->assertCount(1, $app->sample_images);
        $this->assertTrue(SellerSubscription::where('seller_application_id', $app->id)->exists());

        $fresh = $user->fresh();
        $this->assertSame('seller', $fresh->role);
        $this->assertTrue((bool) $fresh->is_approved);

        // The user is told (bell + e-mail); the admins get no "new application" notice
        Notification::assertSentTo($fresh, SellerApplicationReviewedNotification::class);
        Notification::assertNotSentTo(User::getAllAdmins(), NewSellerApplicationNotification::class);
        Mail::assertQueued(SellerApplicationApprovedMail::class);
        Mail::assertNotQueued(SellerApplicationSubmittedMail::class);

        // Token issued before: seller at once, nothing revoked
        $this->assertSame(1, $fresh->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/auth/user')
            ->assertOk()->assertJsonPath('user.role', 'seller')
            ->assertJsonPath('user.seller_application_id', $app->id)
            ->assertJsonPath('user.seller_application_status', 'approved');
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/seller/dashboard')->assertOk();
    }

    public function test_guards_and_validation(): void
    {
        $seller = $this->makeUser('seller');
        $this->as($this->admin)->postJson($this->url($seller), $this->form())
            ->assertStatus(422)->assertJsonPath('message', 'This user is already a seller.');

        $user = $this->makeUser('client');
        $this->as($this->admin)->postJson($this->url($user), $this->form(['approve_immediately' => false]))->assertCreated();
        $this->as($this->admin)->postJson($this->url($user), $this->form())
            ->assertStatus(422)->assertJsonPath('message', 'This user already has a pending seller application. Review it in Applications.');

        // Same validation as the storefront: zone, pin, phone, business name
        $other = $this->makeUser('client');
        $this->as($this->admin)->postJson($this->url($other), $this->form(['latitude' => null, 'longitude' => null, 'intigo_zone_id' => null, 'business_name' => '', 'phone_number' => '123']))
            ->assertStatus(422)->assertJsonValidationErrors(['latitude', 'longitude', 'intigo_zone_id', 'business_name', 'phone_number']);
        $this->assertFalse(SellerApplication::where('user_id', $other->id)->exists());

        // Only admins
        $this->as($other)->postJson($this->url($user), $this->form())->assertForbidden();
    }

    public function test_user_with_a_rejected_application_can_get_an_admin_one(): void
    {
        Mail::fake();
        $user = $this->makeUser('client');
        SellerApplication::create(['user_id' => $user->id, 'full_name' => 'Old', 'phone_number' => '22123456',
            'business_name' => 'Old shop', 'business_category' => 'crafts', 'wilaya' => 'Ariana', 'city' => 'Ariana', 'status' => 'rejected', 'plan' => 'free']);

        $this->as($this->admin)->postJson($this->url($user), $this->form(['approve_immediately' => true]))
            ->assertCreated()->assertJsonPath('data.status', 'approved');
        $this->assertSame('seller', $user->fresh()->role);
    }

    public function test_user_side_application_is_unchanged(): void
    {
        Notification::fake();
        Mail::fake();
        $user = $this->makeUser('client');

        $this->as($user)->postJson('/api/seller-applications', $this->form())->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.pickup.status', 'unverified');
        $app = SellerApplication::where('user_id', $user->id)->firstOrFail();
        $this->assertNull($app->created_by_admin_id);
        Notification::assertSentTo(User::getAllAdmins(), NewSellerApplicationNotification::class);
        Mail::assertQueued(SellerApplicationSubmittedMail::class);

        // The storefront polls while /auth/user says pending
        $this->as($user)->getJson('/api/auth/user')->assertJsonPath('user.seller_application_status', 'pending');

        // Second one refused while pending
        $this->as($user)->postJson('/api/seller-applications', $this->form())->assertStatus(422);

        // Approve from Admin > Applications: same result as before
        $this->as($this->admin)->postJson("/api/admin/seller-applications/{$app->id}/approve")->assertOk();
        $this->assertSame('seller', $user->fresh()->role);
        Notification::assertSentTo($user->fresh(), SellerApplicationReviewedNotification::class);
        Mail::assertQueued(SellerApplicationApprovedMail::class);
    }
}
