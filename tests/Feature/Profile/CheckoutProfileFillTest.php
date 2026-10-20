<?php

namespace Tests\Feature\Profile;

use App\Helpers\PlatformUser;
use App\Models\Cart;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shoppers may skip /complete-profile: checkout then asks for the address and
 * copies it into the empty parts of the profile (App\Support\ProfileFromCheckout).
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Profile/CheckoutProfileFillTest.php
 */
class CheckoutProfileFillTest extends TestCase
{
    use DatabaseTransactions;

    private const ADDRESS = [
        'recipient_name' => 'Sami Ben Salah',
        'phone'          => '22 123 456',
        'wilaya'         => 'Ben Arous',
        'delegation'     => 'El Mourouj',
        'address'        => '12 rue de la Liberté',
        'postal_code'    => '2074',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        if (!DB::table('users')->where('id', 1)->exists()) {
            DB::table('users')->insert([
                'id' => 1, 'name' => "CHOOSE'Tounsi", 'email' => 'platform-' . Str::random(6) . '@test.local',
                'password' => bcrypt('x'), 'role' => 'seller', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        PlatformUser::reset();
    }

    private function client(array $extra = []): User
    {
        return User::create($extra + [
            'name'      => 'Client ' . Str::random(5),
            'email'     => 'client_' . Str::random(10) . '@test.local',
            'password'  => bcrypt('secret-password'),
            'role'      => 'client',
            'is_active' => true,
            'locale'    => 'fr',
        ]);
    }

    private function product(): Product
    {
        $seller = $this->withCompleteProfile(User::create([
            'name' => 'Seller ' . Str::random(5), 'email' => 'seller_' . Str::random(10) . '@test.local',
            'password' => bcrypt('x'), 'role' => 'seller', 'is_active' => true,
        ]));
        SellerApplication::create([
            'user_id' => $seller->id, 'full_name' => 'Mohamed Trabelsi', 'phone_number' => '55111222',
            'business_name' => 'Atelier ' . Str::random(4), 'business_category' => 'crafts',
            'business_description' => 'Test shop', 'wilaya' => 'Sfax', 'city' => 'Sakiet Ezzit',
            'pickup_address' => 'Route de Tunis km 5', 'pickup_postal_code' => '3021', 'status' => 'approved',
        ]);
        $name = 'Fill Product ' . Str::random(6);
        return Product::create([
            'seller_id' => $seller->id, 'name' => $name, 'slug' => Str::slug($name),
            'price' => 30, 'stock' => 10, 'is_approved' => true, 'is_active' => true,
        ]);
    }

    private function checkout(User $user, array $address = self::ADDRESS): void
    {
        Cart::create(['user_id' => $user->id, 'product_id' => $this->product()->id, 'quantity' => 1]);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken])
            ->postJson('/api/checkout', $address + ['payment_method' => 'cod'])
            ->assertCreated();
    }

    public function test_skipped_profile_is_filled_from_checkout(): void
    {
        $user = $this->client();
        $this->checkout($user);

        $user->refresh();
        $this->assertSame('Sami', $user->first_name);
        $this->assertSame('Ben Salah', $user->last_name);
        $this->assertSame('22123456', $user->phone);
        $addresses = UserAddress::where('user_id', $user->id)->get();
        $this->assertCount(1, $addresses);
        $this->assertTrue($addresses[0]->is_default);
        $this->assertSame('El Mourouj', $addresses[0]->delegation);
        $this->assertTrue($user->profileCompletion()->isComplete());
    }

    public function test_existing_details_are_never_overwritten(): void
    {
        $user = $this->client(['first_name' => 'Amira', 'phone' => '55000111']);
        $old  = UserAddress::create([
            'user_id' => $user->id, 'label' => 'Work', 'recipient_name' => 'Amira',
            'wilaya' => 'Tunis', 'address' => 'Avenue Habib Bourguiba', 'phone' => '55000111', 'is_default' => true,
        ]);
        $this->checkout($user);

        $user->refresh();
        $this->assertSame('Amira', $user->first_name);
        $this->assertSame('Ben Salah', $user->last_name);   // was empty
        $this->assertSame('55000111', $user->phone);
        $this->assertSame($old->only(['wilaya', 'delegation', 'address', 'label', 'is_default']), $old->fresh()->only(['wilaya', 'delegation', 'address', 'label', 'is_default']));

        $added = UserAddress::where('user_id', $user->id)->where('id', '!=', $old->id)->get();
        $this->assertCount(1, $added);
        $this->assertFalse($added[0]->is_default);
        $this->assertSame('12 rue de la Liberté', $added[0]->address);
    }

    public function test_same_street_completes_the_saved_address_instead_of_duplicating(): void
    {
        $user = $this->client();
        $old  = UserAddress::create([
            'user_id' => $user->id, 'label' => 'Home', 'recipient_name' => 'Sami',
            'wilaya' => 'Ben Arous', 'address' => '12 rue de la Liberté', 'phone' => '22123456', 'is_default' => true,
        ]);
        $this->checkout($user);

        $this->assertSame(1, UserAddress::where('user_id', $user->id)->count());
        $this->assertSame('El Mourouj', $old->fresh()->delegation);
        $this->assertSame('Sami', $old->fresh()->recipient_name);
    }

    public function test_complete_profile_is_left_alone(): void
    {
        $user = $this->withCompleteProfile($this->client());
        $this->checkout($user, ['address' => '99 avenue de France'] + self::ADDRESS);

        $this->assertSame(1, UserAddress::where('user_id', $user->id)->count());
        $this->assertSame('Test', $user->fresh()->first_name);
    }
}
