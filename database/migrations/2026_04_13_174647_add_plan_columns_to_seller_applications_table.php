<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPlanColumnsToSellerApplicationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
   public function up()
{
    Schema::table('seller_applications', function (Blueprint $table) {
        // Only add columns that are missing — check first
        if (!Schema::hasColumn('seller_applications', 'plan')) {
            $table->enum('plan', ['free', 'red', 'black'])->default('free')->after('preferred_plan');
        }
        if (!Schema::hasColumn('seller_applications', 'preferred_plan')) {
            $table->enum('preferred_plan', ['green', 'red', 'black'])->default('green')->after('status');
        }
    });
}

public function down()
{
    Schema::table('seller_applications', function (Blueprint $table) {
        $table->dropColumn(['plan', 'preferred_plan']);
    });
}
}
