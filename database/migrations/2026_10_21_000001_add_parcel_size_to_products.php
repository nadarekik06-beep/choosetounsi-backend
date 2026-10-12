<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parcel size of a product (Intigo package_size): S = 1, L = 4, XL = 5.
 * A parcel's size is the largest of its items (App\Support\ParcelSize).
 * Existing products start as S (what every parcel was priced as until now).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('parcel_size', ['S', 'L', 'XL'])->default('S')->after('delivery_fee');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('parcel_size'));
    }
};
