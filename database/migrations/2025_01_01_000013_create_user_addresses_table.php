<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_addresses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            // User-defined label: "Home", "Work", "Parents", etc.
            $table->string('label', 100)->default('Home');

            // Matches the existing wilaya list used in checkout
            $table->string('wilaya', 100);

            // Full street address
            $table->string('address', 500);

            // Phone number for this address (may differ from account phone)
            $table->string('phone', 30);

            // Optional default delivery instructions saved with address
            $table->string('notes', 1000)->nullable();

            // Only one address per user can be true at a time.
            // Enforced in AddressController via a transaction (flip all to false, set this to true).
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index('user_id');
            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_addresses');
    }
};