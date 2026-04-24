<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Extend the role ENUM to include delivery roles
        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN role
            ENUM('admin','seller','client','delivery_admin','delivery_guy')
            NOT NULL
            DEFAULT 'client'
        ");
    }

    public function down(): void
    {
        // Revert — make sure no delivery users exist first
        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN role
            ENUM('admin','seller','client')
            NOT NULL
            DEFAULT 'client'
        ");
    }
};