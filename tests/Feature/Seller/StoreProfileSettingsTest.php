<?php

namespace Tests\Feature\Seller;

use App\Models\Category;
use App\Models\SellerApplication;
use App\Models\SellerProfileChange;
use App\Models\User;
use App\Notifications\Sellers\SellerStoreProfileChangedNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Paramètres de la boutique" → Profil de la boutique: logo, description and
 * activity categories. Live at once, logged, admins notified, admin restore.
 * Local test data only (fake storage, fake notifications).
 */
class StoreProfileSettingsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $seller;
    private SellerApplication $application;
    private Category $beauty;
    private Category $home;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Notification::fake();
        config(['mail.default' => 'array']);

        $this->admin  = $this->makeUser('admin');
        [$this->seller, $this->application] = $this->makeSeller();

        $suffix = Str::random(6);
        $this->beauty = Category::create(['name_ar' => 'اختبار', 'name' => "Beauty Test {$suffix}", 'name_fr' => "Beauté & Soins {$suffix}", 'slug' => "beauty-test-{$suffix}", 'is_active' => true]);
        $this->home   = Category::create(['name_ar' => 'اختبار', 'name' => "Home Test {$suffix}", 'name_fr' => "Maison {$suffix}", 'slug' => "home-test-{$suffix}", 'is_active' => true]);
        $this->application->forceFill(['business_category' => $this->home->getRawOriginal('name'), 'business_categories' => [$this->home->getRawOriginal('name')]])->save();
    }

    protected function tearDown(): void
    {
        foreach (['logo', 'description', 'categories'] as $f) {
            RateLimiter::clear("store-profile:{$f}:{$this->seller->id}");
        }
        parent::tearDown();
    }

    private function makeUser(string $role, array $extra = []): User
    {
        return $this->withCompleteProfile(User::create($extra + [
            'name'      => ucfirst($role) . ' ' . Str::random(5),
            'email'     => $role . '_' . Str::random(10) . '@test.local',
            'password'  => bcrypt('secret-password'),
            'role'      => $role,
            'is_active' => true,
            'is_approved' => $role === 'seller',
        ]));
    }

    /** @return array{0: User, 1: SellerApplication} */
    private function makeSeller(array $userExtra = [], string $status = 'approved'): array
    {
        $user = $this->makeUser('seller', $userExtra);
        $app  = SellerApplication::create([
            'user_id' => $user->id, 'full_name' => 'Test Seller', 'phone_number' => '22123456',
            'business_name' => 'Boutique ' . Str::random(5), 'business_category' => 'Other',
            'business_description' => "Ancienne description\nligne 2", 'wilaya' => 'Tunis', 'city' => 'Tunis',
            'status' => $status, 'plan' => 'free',
        ]);
        return [$user, $app];
    }

    private function as(User $user)
    {
        $this->app["auth"]->forgetGuards();
        return $this->withHeaders(['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken]);
    }

    private function png(int $w = 300, int $h = 200): UploadedFile
    {
        return UploadedFile::fake()->image('logo.png', $w, $h);
    }

    // ── Logo ────────────────────────────────────────────────────────────────

    public function test_seller_updates_logo_square_resized_old_kept_and_admin_notified(): void
    {
        $this->application->forceFill(['profile_picture' => 'seller-applications/profiles/old.png'])->save();
        Storage::disk('public')->put('seller-applications/profiles/old.png', 'old');

        $res = $this->as($this->seller)->post('/api/seller/store-profile/logo', ['logo' => $this->png(900, 600)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('success', true);

        $path = $this->application->fresh()->profile_picture;
        $this->assertStringStartsWith("seller-logos/{$this->seller->id}/", $path);
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertExists('seller-applications/profiles/old.png');   // kept for restore
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([512, 512], [$w, $h], 'cropped square and scaled down');
        $this->assertStringContainsString($path, $res->json('data.logo'));

        // Live everywhere the logo is read from
        $this->assertStringContainsString($path, $this->seller->fresh()->storefrontBranding()['avatar']);
        $this->getJson("/api/sellers/{$this->seller->id}")->assertOk()
            ->assertJsonPath('data.avatar', $this->seller->fresh()->storefrontBranding()['avatar']);

        $change = SellerProfileChange::where('user_id', $this->seller->id)->latest('id')->first();
        $this->assertSame('logo', $change->field);
        $this->assertSame('seller-applications/profiles/old.png', $change->old_value['path']);
        $this->assertSame($path, $change->new_value['path']);
        $this->assertSame('seller', $change->changed_by_role);

        Notification::assertSentTo($this->admin, SellerStoreProfileChangedNotification::class,
            fn ($n) => $n->toDatabase($this->admin)['data']['field'] === 'logo'
                && in_array('mail', $n->via($this->admin), true));
    }

    public function test_logo_type_and_size_are_checked_server_side(): void
    {
        $this->as($this->seller)->post('/api/seller/store-profile/logo',
            ['logo' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('logo');

        // A text file renamed .png is not an image
        $fake = UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1; ?>');
        $this->as($this->seller)->post('/api/seller/store-profile/logo', ['logo' => $fake], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('logo');

        $this->as($this->seller)->post('/api/seller/store-profile/logo',
            ['logo' => UploadedFile::fake()->image('big.jpg', 400, 400)->size(5000)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('logo');

        $this->assertNull($this->application->fresh()->profile_picture);
        $this->assertSame(0, SellerProfileChange::where('user_id', $this->seller->id)->count());
    }

    public function test_validation_errors_are_translated(): void
    {
        $this->as($this->seller)->withHeaders(['Accept-Language' => 'ar'])
            ->putJson('/api/seller/store-profile/categories', ['categories' => ['Does Not Exist']])
            ->assertStatus(422)->assertJsonPath('errors.categories.0', 'الفئة « Does Not Exist » غير موجودة على المنصة.');
        $this->as($this->seller)->withHeaders(['Accept-Language' => 'fr'])
            ->putJson('/api/seller/store-profile/categories', ['categories' => []])
            ->assertStatus(422)->assertJsonPath('errors.categories.0', 'Choisissez au moins une catégorie.');
    }

    // ── Description ─────────────────────────────────────────────────────────

    public function test_seller_updates_description_plain_text_with_line_breaks(): void
    {
        $text = "Bienvenue <b>chez nous</b>\r\nProduits artisanaux\n\n\n\nLivraison partout <script>alert(1)</script>";
        $this->as($this->seller)->putJson('/api/seller/store-profile/description', ['business_description' => $text])
            ->assertOk()->assertJsonPath('changed', true);

        $saved = $this->application->fresh()->business_description;
        $this->assertSame("Bienvenue chez nous\nProduits artisanaux\n\nLivraison partout alert(1)", $saved);
        $this->getJson("/api/sellers/{$this->seller->id}")->assertJsonPath('data.business_description', $saved);

        $change = SellerProfileChange::where('user_id', $this->seller->id)->where('field', 'description')->first();
        $this->assertSame("Ancienne description\nligne 2", $change->old_value['text']);
        $this->assertSame($saved, $change->new_value['text']);
        Notification::assertSentTo($this->admin, SellerStoreProfileChangedNotification::class);

        // Same text again: no change, no history row
        $this->as($this->seller)->putJson('/api/seller/store-profile/description', ['business_description' => $saved])
            ->assertOk()->assertJsonPath('changed', false);
        $this->assertSame(1, SellerProfileChange::where('user_id', $this->seller->id)->count());

        $this->as($this->seller)->putJson('/api/seller/store-profile/description', ['business_description' => str_repeat('a', 2001)])
            ->assertStatus(422)->assertJsonValidationErrors('business_description');
    }

    // ── Categories ──────────────────────────────────────────────────────────

    public function test_seller_adds_and_removes_platform_categories_only(): void
    {
        $platformBefore = Category::count();

        $this->as($this->seller)->putJson('/api/seller/store-profile/categories', ['categories' => [$this->home->getRawOriginal('name'), $this->beauty->getRawOriginal('name')]])
            ->assertOk()->assertJsonPath('data.business_categories', [$this->home->getRawOriginal('name'), $this->beauty->getRawOriginal('name')]);

        $app = $this->application->fresh();
        $this->assertSame([$this->home->getRawOriginal('name'), $this->beauty->getRawOriginal('name')], $app->business_categories);
        $this->assertSame($this->home->getRawOriginal('name'), $app->business_category);
        $this->assertSame($platformBefore, Category::count(), 'platform categories untouched');

        $change = SellerProfileChange::where('user_id', $this->seller->id)->where('field', 'categories')->first();
        $this->assertSame([$this->beauty->getRawOriginal('name')], $change->new_value['added']);
        Notification::assertSentTo($this->admin, SellerStoreProfileChangedNotification::class,
            fn ($n) => str_contains($n->toDatabase($this->admin)['body'], "a ajouté la catégorie {$this->beauty->name_fr}"));

        // Unknown category: refused, nothing created
        $this->as($this->seller)->putJson('/api/seller/store-profile/categories', ['categories' => [$this->home->getRawOriginal('name'), 'Brand New Category']])
            ->assertStatus(422)->assertJsonValidationErrors('categories');
        $this->assertSame($platformBefore, Category::count());
        $this->assertDatabaseMissing('categories', ['name' => 'Brand New Category']);

        // Inactive category: refused
        $inactive = Category::create(['name_ar' => 'اختبار', 'name' => 'Inactive ' . Str::random(5), 'slug' => 'inactive-' . Str::random(5), 'is_active' => false]);
        $this->as($this->seller)->putJson('/api/seller/store-profile/categories', ['categories' => [$inactive->getRawOriginal('name')]])
            ->assertStatus(422);

        // Max 5
        $six = collect(range(1, 6))->map(fn ($i) => Category::create(['name_ar' => 'اختبار', 'name' => "Cat{$i} " . Str::random(5), 'slug' => "cat{$i}-" . Str::random(6), 'is_active' => true])->getRawOriginal('name'))->all();
        $this->as($this->seller)->putJson('/api/seller/store-profile/categories', ['categories' => $six])
            ->assertStatus(422)->assertJsonValidationErrors('categories');

        // Remove one
        $this->as($this->seller)->putJson('/api/seller/store-profile/categories', ['categories' => [$this->beauty->getRawOriginal('name')]])->assertOk();
        $this->assertSame([$this->beauty->getRawOriginal('name')], $this->application->fresh()->business_categories);
    }

    public function test_activity_categories_do_not_limit_product_categories(): void
    {
        $this->as($this->seller)->putJson('/api/seller/store-profile/categories', ['categories' => [$this->beauty->getRawOriginal('name')]])->assertOk();
        // No seller product code reads the activity categories: any platform category stays usable
        foreach (array_merge(glob(app_path('Http/Controllers/Api/Seller/*Product*.php')), glob(app_path('Services/Product*.php'))) as $file) {
            $this->assertStringNotContainsString('business_categories', file_get_contents($file), basename($file));
        }
    }

    // ── Security ────────────────────────────────────────────────────────────

    public function test_only_the_owner_active_approved_seller_can_edit(): void
    {
        [$other, $otherApp] = $this->makeSeller();
        $otherBefore = $otherApp->business_description;

        // Seller A's request only ever changes seller A's store
        $this->as($this->seller)->putJson('/api/seller/store-profile/description', ['business_description' => 'Mine'])->assertOk();
        $this->assertSame($otherBefore, $otherApp->fresh()->business_description);
        // No endpoint takes another seller's id; the admin endpoints are refused
        $this->as($this->seller)->postJson("/api/admin/sellers/{$other->id}/store-profile/restore/1")->assertStatus(403);
        $this->as($this->seller)->getJson("/api/admin/sellers/{$other->id}/store-profile")->assertStatus(403);

        // Buyer
        $buyer = $this->makeUser('client');
        $this->as($buyer)->putJson('/api/seller/store-profile/description', ['business_description' => 'x'])->assertStatus(403);

        // Suspended seller
        [$suspended] = $this->makeSeller(['is_active' => false]);
        $this->as($suspended)->putJson('/api/seller/store-profile/description', ['business_description' => 'x'])->assertStatus(403);

        // Seller role without approval
        [$unapproved, $unApp] = $this->makeSeller(['is_approved' => false]);
        $this->as($unapproved)->putJson('/api/seller/store-profile/description', ['business_description' => 'x'])->assertStatus(403);
        $this->assertSame("Ancienne description\nligne 2", $unApp->fresh()->business_description);

        // Name is not editable from here
        $name = $this->application->business_name;
        $this->as($this->seller)->putJson('/api/seller/store-profile/description', ['business_description' => 'Mine 2', 'business_name' => 'Hacked'])->assertOk();
        $this->assertSame($name, $this->application->fresh()->business_name);
        $this->assertSame('approved', $this->application->fresh()->status);
    }

    public function test_changes_are_rate_limited(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->as($this->seller)->putJson('/api/seller/store-profile/description', ['business_description' => "Version {$i}"])->assertOk();
        }
        $this->as($this->seller)->withHeaders(['Accept-Language' => 'en'])
            ->putJson('/api/seller/store-profile/description', ['business_description' => 'Version 6'])
            ->assertStatus(429)->assertJsonValidationErrors('description');
        $this->assertSame('Version 5', $this->application->fresh()->business_description);

        // Other sections have their own budget
        $this->as($this->seller)->putJson('/api/seller/store-profile/categories', ['categories' => [$this->beauty->getRawOriginal('name')]])->assertOk();
    }

    // ── Admin view + restore ────────────────────────────────────────────────

    public function test_admin_sees_history_and_restores_logo_and_description(): void
    {
        $this->application->forceFill(['profile_picture' => 'seller-applications/profiles/old.png'])->save();
        Storage::disk('public')->put('seller-applications/profiles/old.png', 'old');

        $this->as($this->seller)->post('/api/seller/store-profile/logo', ['logo' => $this->png()], ['Accept' => 'application/json'])->assertOk();
        $this->as($this->seller)->putJson('/api/seller/store-profile/description', ['business_description' => 'Nouvelle'])->assertOk();
        $this->as($this->seller)->putJson('/api/seller/store-profile/categories', ['categories' => [$this->home->getRawOriginal('name'), $this->beauty->getRawOriginal('name')]])->assertOk();

        $card = $this->as($this->admin)->getJson("/api/admin/sellers/{$this->seller->id}/store-profile")->assertOk();
        $card->assertJsonPath('data.business_description', 'Nouvelle')
            ->assertJsonPath('data.categories.1.name_fr', $this->beauty->name_fr);
        $history = collect($card->json('data.history'));
        $this->assertSame(['categories', 'description', 'logo'], $history->where('kind', 'profile')->pluck('field')->all());
        $logoChange = $history->firstWhere('field', 'logo');
        $this->assertTrue($logoChange['restorable']);
        $this->assertStringContainsString('old.png', $logoChange['old']['url']);
        $this->assertFalse($history->firstWhere('field', 'categories')['restorable']);

        // Restore the old logo
        $this->as($this->admin)->postJson("/api/admin/sellers/{$this->seller->id}/store-profile/restore/{$logoChange['id']}")->assertOk();
        $this->assertSame('seller-applications/profiles/old.png', $this->application->fresh()->profile_picture);

        // Restore the old description
        $descChange = $history->firstWhere('field', 'description');
        $this->as($this->admin)->postJson("/api/admin/sellers/{$this->seller->id}/store-profile/restore/{$descChange['id']}")->assertOk();
        $this->assertSame("Ancienne description\nligne 2", $this->application->fresh()->business_description);

        $restores = SellerProfileChange::where('user_id', $this->seller->id)->whereNotNull('restored_from_id')->get();
        $this->assertCount(2, $restores);
        $this->assertTrue($restores->every(fn ($r) => $r->changed_by_role === 'admin' && $r->changed_by === $this->admin->id));

        // Categories can't be "restored"; a change of another seller is not found
        // A first logo (nothing before) has nothing to restore
        $this->application->forceFill(['profile_picture' => null])->save();
        RateLimiter::clear("store-profile:logo:{$this->seller->id}");
        $this->as($this->seller)->post('/api/seller/store-profile/logo', ['logo' => $this->png()], ['Accept' => 'application/json'])->assertOk();
        $first = SellerProfileChange::where('user_id', $this->seller->id)->where('field', 'logo')->latest('id')->first();
        $this->assertFalse(collect($this->as($this->admin)->getJson("/api/admin/sellers/{$this->seller->id}/store-profile")->json('data.history'))->firstWhere('id', $first->id)['restorable']);
        $this->as($this->admin)->postJson("/api/admin/sellers/{$this->seller->id}/store-profile/restore/{$first->id}")->assertStatus(422);

        $catChange = $history->firstWhere('field', 'categories');
        $this->as($this->admin)->postJson("/api/admin/sellers/{$this->seller->id}/store-profile/restore/{$catChange['id']}")->assertStatus(422);
        [$other] = $this->makeSeller();
        $this->as($this->admin)->postJson("/api/admin/sellers/{$other->id}/store-profile/restore/{$logoChange['id']}")->assertNotFound();
    }
}
