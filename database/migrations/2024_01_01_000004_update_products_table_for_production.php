<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class UpdateProductsTableForProduction extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            // Add missing production fields if they don't exist
            if (!Schema::hasColumn('products', 'slug')) {
                $table->string('slug')->unique()->after('name');
            }
            
            if (!Schema::hasColumn('products', 'short_description')) {
                $table->string('short_description', 200)->nullable()->after('description');
            }
            
            if (!Schema::hasColumn('products', 'views')) {
                $table->integer('views')->default(0)->after('stock');
            }
            
            if (!Schema::hasColumn('products', 'featured')) {
                $table->boolean('featured')->default(false)->after('is_active');
            }
        });

        // Create indexes separately to avoid duplicate index errors
        // Check if index exists first
        if (!DB::select("SHOW INDEX FROM products WHERE Key_name = 'products_seller_id_is_approved_is_active_index'")) {
            Schema::table('products', function (Blueprint $table) {
                $table->index(['seller_id', 'is_approved', 'is_active']);
            });
        }

        if (!DB::select("SHOW INDEX FROM products WHERE Key_name = 'products_featured_index'")) {
            Schema::table('products', function (Blueprint $table) {
                $table->index('featured');
            });
        }

        if (!DB::select("SHOW INDEX FROM products WHERE Key_name = 'products_slug_index'")) {
            Schema::table('products', function (Blueprint $table) {
                $table->index('slug');
            });
        }
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            // Drop indexes if they exist
            if (DB::select("SHOW INDEX FROM products WHERE Key_name = 'products_seller_id_is_approved_is_active_index'")) {
                $table->dropIndex('products_seller_id_is_approved_is_active_index');
            }
            if (DB::select("SHOW INDEX FROM products WHERE Key_name = 'products_featured_index'")) {
                $table->dropIndex('products_featured_index');
            }
            if (DB::select("SHOW INDEX FROM products WHERE Key_name = 'products_slug_index'")) {
                $table->dropIndex('products_slug_index');
            }

            // Drop columns safely
            if (Schema::hasColumn('products', 'slug')) {
                $table->dropColumn('slug');
            }
            if (Schema::hasColumn('products', 'short_description')) {
                $table->dropColumn('short_description');
            }
            if (Schema::hasColumn('products', 'views')) {
                $table->dropColumn('views');
            }
            if (Schema::hasColumn('products', 'featured')) {
                $table->dropColumn('featured');
            }
        });
    }
}