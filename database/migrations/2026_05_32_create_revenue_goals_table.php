<?php
// database/migrations/2014_01_01_000000_create_revenue_goals_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('month', 7);          // e.g. "2026-05"
            $table->decimal('goal_amount', 12, 3)->default(0);
            $table->timestamps();

            $table->unique(['seller_id', 'month']);
            $table->foreign('seller_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_goals');
    }
};