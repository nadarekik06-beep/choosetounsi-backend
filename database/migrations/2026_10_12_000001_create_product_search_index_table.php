<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront text search: one row per product with its searchable text already cut into
 * search tokens (App\Services\Search\SearchText), one column per weight. Kept in sync by
 * SearchIndexObserver; `php artisan search:build-index` rebuilds it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_search_index', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->primary();
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->text('names');          // product name in every language, one " | " per language
            $table->text('category');       // category + subcategory names (EN/FR/AR)
            $table->text('extra');          // brand / attributes / variant options (colors, sizes)
            $table->text('description');    // start of the description, template sentences removed
            $table->text('all_text');       // everything above: the candidate filter (LIKE '% word%')
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_search_index');
    }
};
