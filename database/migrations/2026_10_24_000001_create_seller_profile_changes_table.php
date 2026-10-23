<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History of a seller's store profile changes made from "Paramètres de la
 * boutique" (logo, description, activity categories) and restores by an admin.
 *
 *   field      logo | description | categories
 *   old_value  logo: {"path"}   description: {"text"}   categories: {"list"}
 *   new_value  same shape; categories also carry {"added", "removed"}
 *
 * Old logo files are never deleted, so the admin can restore any of them.
 * Pickup address changes stay in seller_address_changes; the admin history
 * merges both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_profile_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();      // the seller
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_role', 20);     // seller | admin
            $table->string('field', 20);               // logo | description | categories
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->unsignedBigInteger('restored_from_id')->nullable();   // admin restore of this change
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_profile_changes');
    }
};
