<?php

namespace Tests;

use App\Models\User;
use App\Models\UserAddress;
use App\Services\Chat\GroqClient;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        GroqClient::$strayCalls = 0;
        // Saving a product queues a Groq translation: offline by default (a test of the translator itself fakes the HTTP call)
        $this->app->instance(\App\Services\ProductTranslator::class, \Mockery::mock(\App\Services\ProductTranslator::class)->shouldIgnoreMissing());
    }

    /** The suite never calls the real Groq API: a test that did is a failed test. */
    protected function tearDown(): void
    {
        $stray = GroqClient::$strayCalls;
        GroqClient::$strayCalls = 0;
        parent::tearDown();

        $this->assertSame(0, $stray, 'This test made a real Groq request. Mock GroqClient or use Http::fake.');
    }

    /**
     * Shoppers must complete their profile before checkout
     * (App\Support\ProfileCompletion): give a throwaway client the required
     * name, phone and delivery address. Other roles are returned untouched.
     */
    protected function withCompleteProfile(User $user): User
    {
        if ($user->role !== 'client') {
            return $user;
        }
        $user->forceFill(['first_name' => 'Test', 'last_name' => 'Client', 'phone' => '22123456'])->save();
        UserAddress::create([
            'user_id' => $user->id, 'label' => 'Home', 'recipient_name' => 'Test Client',
            'wilaya' => 'Tunis', 'delegation' => 'La Marsa', 'address' => '12 rue de Carthage',
            'phone' => '22123456', 'is_default' => true,
        ]);
        return $user;
    }
}
