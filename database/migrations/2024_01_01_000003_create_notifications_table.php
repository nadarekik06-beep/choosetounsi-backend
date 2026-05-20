<?php
// database/migrations/xxxx_create_notifications_table.php
// Run:  php artisan migrate
// (If the table already exists, this will be skipped automatically.)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard: skip if the table already exists
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');   // notifiable_type + notifiable_id
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(
                ['notifiable_type', 'notifiable_id', 'read_at'],
                'notifiable_read_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};