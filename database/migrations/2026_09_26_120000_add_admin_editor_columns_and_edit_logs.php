<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin product editor: internal note + "edited by admin" marker on products,
 * and a per-save audit trail of what the admin changed.
 */
class AddAdminEditorColumnsAndEditLogs extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'admin_note')) {
                $table->text('admin_note')->nullable();
            }
            if (!Schema::hasColumn('products', 'admin_edited_at')) {
                $table->timestamp('admin_edited_at')->nullable();
            }
            if (!Schema::hasColumn('products', 'admin_edited_by')) {
                $table->unsignedBigInteger('admin_edited_by')->nullable();
            }
        });

        if (!Schema::hasTable('product_edit_logs')) {
            Schema::create('product_edit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('admin_id')->nullable();
                $table->string('summary', 500)->nullable();
                $table->json('changes')->nullable();
                $table->timestamps();

                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->foreign('admin_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('product_edit_logs');

        Schema::table('products', function (Blueprint $table) {
            foreach (['admin_note', 'admin_edited_at', 'admin_edited_by'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
}
