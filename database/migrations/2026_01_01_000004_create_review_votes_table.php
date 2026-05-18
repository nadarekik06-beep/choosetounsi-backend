<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REVIEW VOTES
 *
 * Tracks "Was this review helpful?" votes.
 * One vote per (user, review) — type is 'helpful' or 'not_helpful'.
 * The denormalized counts (helpful_count, not_helpful_count) on the reviews
 * table are updated via the ReviewVoteObserver after each insert/delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_votes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('review_id')
                  ->constrained('reviews')
                  ->onDelete('cascade');

            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            $table->enum('type', ['helpful', 'not_helpful']);

            $table->timestamps();

            // One vote per user per review
            $table->unique(['review_id', 'user_id']);
            $table->index(['review_id', 'type']); // for counting
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_votes');
    }
};