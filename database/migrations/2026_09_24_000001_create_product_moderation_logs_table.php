<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_moderation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            // Admin who acted; null for seller-originated events (submitted / resubmitted)
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            // submitted | resubmitted | approved | rejected | changes_requested
            // | featured | unfeatured | disabled | restored
            $table->string('action', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->json('reasons')->nullable();   // predefined reason codes
            $table->text('note')->nullable();      // free-text comment
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_moderation_logs');
    }
};
