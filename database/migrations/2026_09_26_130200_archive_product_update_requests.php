<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The product update-request flow is gone: sellers now edit approved products
 * directly (logged in product_change_sets). Old requests are kept as history.
 *
 * Pending requests are closed rather than applied — their proposed values were
 * captured against an older version of the product and could overwrite newer
 * edits. The table is renamed, not dropped.
 */
class ArchiveProductUpdateRequests extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('product_update_requests')) return;

        DB::table('product_update_requests')->where('status', 'pending')->update([
            'status'        => 'rejected',
            'admin_comment' => 'Closed automatically: sellers now edit approved products directly. Please make this change from the product form.',
            'updated_at'    => now(),
        ]);

        Schema::rename('product_update_requests', 'product_update_requests_archive');
    }

    public function down()
    {
        if (Schema::hasTable('product_update_requests_archive') && !Schema::hasTable('product_update_requests')) {
            Schema::rename('product_update_requests_archive', 'product_update_requests');
        }
    }
}
