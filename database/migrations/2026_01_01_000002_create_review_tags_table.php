<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * REVIEW TAGS
 *
 * Pre-defined clickable tags (like SHEIN's "Like a Princess", "Fast Logistics").
 * Tags are global but can be filtered by category in the future.
 *
 * review_tags         → master tag list (seeded)
 * review_tag_pivot    → many-to-many between reviews and tags
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Master tag list ────────────────────────────────────────────
        Schema::create('review_tags', function (Blueprint $table) {
            $table->id();
            $table->string('label');           // e.g. "Good Quality"
            $table->string('label_ar')->nullable(); // Arabic translation (future)
            $table->string('label_fr')->nullable(); // French translation
            $table->enum('sentiment', ['positive', 'negative', 'neutral'])->default('positive');
            $table->string('icon')->nullable(); // emoji or icon name e.g. "⭐"
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sentiment']);
        });

        // ── 2. Pivot: which tags a review has ─────────────────────────────
        Schema::create('review_tag_pivot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')
                  ->constrained('reviews')
                  ->onDelete('cascade');
            $table->foreignId('review_tag_id')
                  ->constrained('review_tags')
                  ->onDelete('cascade');
            $table->timestamps();

            $table->unique(['review_id', 'review_tag_id']);
            $table->index('review_tag_id'); // for "which reviews have this tag" analytics
        });

        // ── 3. Seed default tags ──────────────────────────────────────────
        $now  = now();
        $tags = [
            // Positive
            ['label' => 'Good Quality',     'label_fr' => 'Bonne qualité',     'sentiment' => 'positive', 'icon' => '⭐', 'sort_order' => 1],
            ['label' => 'Same as Pictures', 'label_fr' => 'Conforme aux photos','sentiment' => 'positive', 'icon' => '📸', 'sort_order' => 2],
            ['label' => 'Fast Delivery',    'label_fr' => 'Livraison rapide',   'sentiment' => 'positive', 'icon' => '🚀', 'sort_order' => 3],
            ['label' => 'Worth the Price',  'label_fr' => 'Bon rapport qualité','sentiment' => 'positive', 'icon' => '💰', 'sort_order' => 4],
            ['label' => 'Elegant',          'label_fr' => 'Élégant',            'sentiment' => 'positive', 'icon' => '✨', 'sort_order' => 5],
            ['label' => 'Well Packaged',    'label_fr' => 'Bien emballé',       'sentiment' => 'positive', 'icon' => '📦', 'sort_order' => 6],
            ['label' => 'Great Service',    'label_fr' => 'Super service',      'sentiment' => 'positive', 'icon' => '🤝', 'sort_order' => 7],
            ['label' => 'Comfortable',      'label_fr' => 'Confortable',        'sentiment' => 'positive', 'icon' => '😊', 'sort_order' => 8],
            ['label' => 'Will Repurchase',  'label_fr' => 'Je rachèterai',      'sentiment' => 'positive', 'icon' => '🔁', 'sort_order' => 9],
            // Negative
            ['label' => 'Bad Packaging',    'label_fr' => 'Mauvais emballage',  'sentiment' => 'negative', 'icon' => '😕', 'sort_order' => 10],
            ['label' => 'Wrong Item',       'label_fr' => 'Mauvais article',    'sentiment' => 'negative', 'icon' => '❌', 'sort_order' => 11],
            ['label' => 'Sizing Issue',     'label_fr' => 'Problème de taille', 'sentiment' => 'negative', 'icon' => '📏', 'sort_order' => 12],
            ['label' => 'Poor Quality',     'label_fr' => 'Mauvaise qualité',   'sentiment' => 'negative', 'icon' => '👎', 'sort_order' => 13],
            ['label' => 'Late Delivery',    'label_fr' => 'Livraison tardive',  'sentiment' => 'negative', 'icon' => '🐢', 'sort_order' => 14],
        ];

        foreach ($tags as $tag) {
            DB::table('review_tags')->insert(array_merge($tag, [
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('review_tag_pivot');
        Schema::dropIfExists('review_tags');
    }
};