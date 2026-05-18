<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REVIEW REPORTS
 *
 * Users (clients) or sellers can report a review as spam/inappropriate.
 * Admin reviews reports and can flag/reject the review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('review_id')
                  ->constrained('reviews')
                  ->onDelete('cascade');

            // Who filed the report
            $table->foreignId('reported_by')
                  ->constrained('users')
                  ->onDelete('cascade');

            $table->enum('reason', [
                'spam',
                'fake',
                'inappropriate',
                'offensive',
                'other',
            ]);

            $table->text('note')->nullable(); // optional extra context

            // Admin resolution
            $table->enum('status', ['pending', 'reviewed', 'dismissed'])
                  ->default('pending');

            $table->timestamps();

            // One report per (user, review)
            $table->unique(['review_id', 'reported_by']);
            $table->index(['review_id', 'status']);
            $table->index('status'); // for admin dashboard listing
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_reports');
    }
};