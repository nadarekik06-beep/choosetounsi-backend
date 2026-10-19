<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public const EMAIL = 'admin@choosetounsi.com';

    public function run()
    {
        $password = config('app.admin_initial_password');

        if (blank($password)) {
            if (app()->environment('production')) {
                throw new RuntimeException('ADMIN_INITIAL_PASSWORD is not set in .env: set it before seeding the admin account (then run php artisan config:clear if the config is cached).');
            }
            $password = 'Admin@1234!'; // local development only
        }

        $admin = User::firstOrNew(['email' => self::EMAIL]);

        // In production, re-running the seeder never resets a password the admin has changed.
        if (!$admin->exists || !app()->environment('production')) {
            $admin->password = Hash::make($password);
        }

        $admin->fill([
            'name'              => 'Super Admin',
            'role'              => 'admin',
            'is_active'         => true,
            'is_approved'       => true,
        ]);
        $admin->email_verified_at = $admin->email_verified_at ?? now();
        $admin->save();
    }
}
