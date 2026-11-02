<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One key per checkout confirmation (UUID sent by the storefront). The unique
 * index makes the database refuse a second order for the same key, even when
 * two requests arrive at the same moment. NULL = older clients (allowed, many).
 */
class AddIdempotencyKeyToOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->unique()->after('order_number');
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
}
