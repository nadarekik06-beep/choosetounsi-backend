<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Search by photo on MySQL (no Meilisearch):
 *
 *  image_fingerprints  one CLIP vector per product / color photo: 512 float32, L2-normalized at
 *                      insert (similarity = dot product), with the model that made it so a model
 *                      change is visible (php artisan image-search:rebuild re-embeds those rows).
 *  image_search_logs   one row per photo search (predicted category, top scores, clicked result)
 *                      to tune the thresholds later.
 *
 * Replaces search_image_embeddings (JSON vectors keyed by file content, used to feed Meilisearch).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_image_id')->unique()->constrained('product_images')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->char('content_hash', 40)->index();     // sha1 of the file: unchanged file = no re-embedding
            $table->string('model', 64);                   // e.g. clip-vision-int8.onnx@3fcb18e2
            $table->binary('vector');                      // 512 × float32 little-endian = 2048 bytes
            $table->char('color', 7)->nullable();          // dominant color of the item, #rrggbb
            $table->timestamps();
        });

        Schema::create('image_search_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('image_hash', 40);
            $table->unsignedBigInteger('predicted_category_id')->nullable();      // categories.id
            $table->unsignedBigInteger('predicted_subcategory_id')->nullable();   // subcategories.id
            $table->decimal('category_confidence', 5, 4)->nullable();             // centroid margin top1 − top2
            $table->boolean('category_confident')->default(false);
            $table->char('query_color', 7)->nullable();
            $table->json('top_scores')->nullable();      // [[product_id, similarity, score], …] best 5
            $table->unsignedSmallInteger('exact_count')->default(0);
            $table->unsignedSmallInteger('similar_count')->default(0);
            $table->boolean('fallback')->default(false);  // nothing passed the threshold: predicted category shown
            $table->boolean('cached')->default(false);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedBigInteger('clicked_product_id')->nullable();
            $table->unsignedSmallInteger('clicked_rank')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::dropIfExists('search_image_embeddings');
    }

    public function down(): void
    {
        Schema::dropIfExists('image_search_logs');
        Schema::dropIfExists('image_fingerprints');

        Schema::create('search_image_embeddings', function (Blueprint $table) {
            $table->id();
            $table->char('content_hash', 40);
            $table->string('model', 64);
            $table->mediumText('vector');
            $table->timestamp('created_at')->nullable();
            $table->unique(['content_hash', 'model']);
        });
    }
};
