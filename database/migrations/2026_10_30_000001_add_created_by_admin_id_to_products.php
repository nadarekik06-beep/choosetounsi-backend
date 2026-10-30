<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products an admin created on behalf of a seller (admin panel "Add product",
 * or "Split into separate products"). Null for everything the seller created.
 */
class AddCreatedByAdminIdToProducts extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('products', 'created_by_admin_id')) return;

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_admin_id')->nullable()->after('admin_edited_by');
            $table->foreign('created_by_admin_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down()
    {
        if (!Schema::hasColumn('products', 'created_by_admin_id')) return;

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['created_by_admin_id']);
            $table->dropColumn('created_by_admin_id');
        });
    }
}
