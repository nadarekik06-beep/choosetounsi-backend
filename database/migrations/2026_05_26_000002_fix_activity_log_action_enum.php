<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE user_activity_logs 
            MODIFY COLUMN action 
            ENUM('view', 'favorite', 'cart', 'order', 'purchase') 
            NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE user_activity_logs 
            MODIFY COLUMN action 
            ENUM('view', 'favorite', 'cart', 'order') 
            NOT NULL");
    }
};