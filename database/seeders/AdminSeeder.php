<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminSeeder extends Seeder
{
    public function run()
    {
        User::updateOrCreate(
            ['email' => 'admin@choosetounsi.com'],
            [
                'name'        => 'Super Admin',
                'email'       => 'admin@choosetounsi.com',
                'password'    => Hash::make('Admin@1234!'),
                'role'        => 'admin',
                'is_active'   => true,
                'is_approved' => true,
            ]
        );
    }
}