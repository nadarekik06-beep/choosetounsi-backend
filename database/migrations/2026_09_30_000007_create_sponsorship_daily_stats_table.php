<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per campaign, Africa/Tunis day and placement roll-up of sponsorship_events (dashboards, forecasts). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsorship_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sponsorship_id')->constrained('sponsorships')->cascadeOnDelete();
            $table->date('date');
            $table->string('placement', 30);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->decimal('cost', 12, 3)->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->decimal('revenue', 12, 3)->default(0);
            $table->timestamps();

            $table->unique(['sponsorship_id', 'date', 'placement'], 'uq_sp_daily');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorship_daily_stats');
    }
};
