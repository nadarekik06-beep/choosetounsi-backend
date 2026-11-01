<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The admin who submitted the application on the user's behalf (Admin > Users). Null = the user applied. */
class AddCreatedByAdminIdToSellerApplicationsTable extends Migration
{
    public function up()
    {
        Schema::table('seller_applications', function (Blueprint $table) {
            $table->foreignId('created_by_admin_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('seller_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_admin_id');
        });
    }
}
