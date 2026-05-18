<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REVIEW REPLIES
 *
 * Sellers can post one official reply per review.
 * Only the seller who owns the reviewed product can reply.
 * Admin can edit/delete seller replies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_replies', function (Blueprint $table) {
            $table->id();

            // The review being replied to
            $table->foreignId('review_id')
                  ->constrained('reviews')
                  ->onDelete('cascade');

            // The seller replying (must be the product's seller)
            $table->unsignedBigInteger('seller_id');
            $table->foreign('seller_id')->references('id')->on('users')->onDelete('cascade');

            $table->text('body');

            // Admin can hide inappropriate seller replies
            $table->boolean('is_visible')->default(true);

            $table->timestamps();

            // One reply per seller per review
            $table->unique(['review_id', 'seller_id']);
            $table->index('seller_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_replies');
    }
};