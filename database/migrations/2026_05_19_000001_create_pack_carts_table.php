<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pack_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')->onDelete('cascade');
            $table->foreignId('pack_id')
                ->constrained('packs')->onDelete('cascade');

            // JSON: [{ pack_item_id, variant_id (nullable), quantity }]
            $table->json('selected_variants');

            $table->timestamps();

            // One pack per user in cart at a time
            $table->unique(['user_id', 'pack_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pack_carts');
    }
};