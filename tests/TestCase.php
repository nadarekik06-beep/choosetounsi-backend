<?php

namespace Tests;

use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

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
