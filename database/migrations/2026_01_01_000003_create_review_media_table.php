<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REVIEW MEDIA
 *
 * Customer photos (and future video) attached to reviews.
 * Stored in Laravel storage (public disk), same pattern as ProductImage.
 *
 * Admin can soft-delete individual images without removing the review.
 * is_approved = false means the image is hidden (moderated by admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_media', function (Blueprint $table) {
            $table->id();

            $table->foreignId('review_id')
                  ->constrained('reviews')
                  ->onDelete('cascade');

            // Storage path relative to /storage/app/public/ — same as product_images
            $table->string('path');

            // 'image' for now; 'video' reserved for future use
            $table->enum('type', ['image', 'video'])->default('image');

            // Sort order within the review's gallery
            $table->unsignedTinyInteger('sort_order')->default(0);

            // Admin moderation — hide specific images without deleting the review
            $table->boolean('is_approved')->default(true);

            // Soft delete — preserves DB reference even after admin removes the file
            $table->softDeletes();

            $table->timestamps();

            $table->index(['review_id', 'is_approved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_media');
    }
};