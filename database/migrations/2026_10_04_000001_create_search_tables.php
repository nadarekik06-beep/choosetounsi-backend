<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CLIP vectors of product photos, keyed by file content: computed once (queued job),
        // reused by every reindex, shared by identical files.
        Schema::create('search_image_embeddings', function (Blueprint $table) {
            $table->id();
            $table->char('content_hash', 40);
            $table->string('model', 64);
            $table->mediumText('vector');            // JSON float array
            $table->timestamp('created_at')->nullable();
            $table->unique(['content_hash', 'model']);
        });

        // Searches that found nothing or almost nothing, one row per query per day
        // (admin panel → grow resources/search/synonyms.txt from it).
        Schema::create('search_missed_queries', function (Blueprint $table) {
            $table->id();
            $table->string('query', 191);              // normalized
            $table->date('day');
            $table->unsignedInteger('searches')->default(1);
            $table->unsignedSmallInteger('results')->default(0);   // results the last time it was searched
            $table->string('example', 191)->nullable();  // as typed, for display
            $table->timestamps();
            $table->unique(['query', 'day']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_missed_queries');
        Schema::dropIfExists('search_image_embeddings');
    }
};
