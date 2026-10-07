<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the buyer saw when they paid, frozen on the order line (see
 * App\Services\Orders\OrderItemSnapshot). product_name, variant_label and
 * unit_price already existed; image_url was in OrderItem::$fillable but the
 * column was never created, so every screen fell back to the live product.
 *
 *   image_url          — "/storage/order-snapshots/…": a copy of the image, immune to seller edits
 *   variant_attributes — [{option_id, slug, label, value, color_hex}] of the bought variant
 *   image_source       — how image_url was chosen: variant | product | label | fallback | none
 *                        (NULL = not snapshotted yet; `php artisan orders:snapshot-items` fills it)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->json('variant_attributes')->nullable()->after('variant_label');
            if (!Schema::hasColumn('order_items', 'image_url')) {
                $table->string('image_url')->nullable()->after('product_name');
            }
            $table->string('image_source', 16)->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['variant_attributes', 'image_url', 'image_source']);
        });
    }
};
