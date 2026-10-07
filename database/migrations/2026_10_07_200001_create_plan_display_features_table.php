<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing-page content for subscription plans, managed from the admin panel.
 *
 *  - plan_display_features: marketing bullets per plan (not enforced by code).
 *  - subscription_plans.tagline / is_recommended: card subtitle and "Populaire" badge.
 *  - subscription_plans.capability_display: per-capability public label /
 *    description overrides and visibility, { key: {label, description, visible} }.
 *  - subscription_plans.hidden_limits: limits not shown on the card, e.g. ["max_images_per_product"].
 *
 * Purely additive: `features` (what PlanGate enforces) is not touched. Existing
 * plans get the taglines and the "popular" plan the storefront hardcoded so far.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_display_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->string('label', 80);
            $table->string('description', 160)->nullable();
            $table->string('icon', 30)->nullable();
            $table->boolean('included')->default(true);
            $table->boolean('highlight')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['plan_id', 'sort_order']);
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('tagline', 120)->nullable()->after('description');
            $table->boolean('is_recommended')->default(false)->after('is_default');
            $table->json('capability_display')->nullable()->after('features');
            $table->json('hidden_limits')->nullable()->after('capability_display');
        });

        // What /become-a-vendor showed until now (messages/fr.json + PLAN_STYLES.popular)
        $taglines = ['free' => 'Idéal pour débuter', 'red' => 'Entreprises en croissance', 'black' => 'Vendeurs confirmés'];
        foreach ($taglines as $slug => $tagline) {
            DB::table('subscription_plans')->where('slug', $slug)->whereNull('tagline')->update(['tagline' => $tagline]);
        }
        DB::table('subscription_plans')->where('slug', 'red')->update(['is_recommended' => true]);
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['tagline', 'is_recommended', 'capability_display', 'hidden_limits']);
        });
        Schema::dropIfExists('plan_display_features');
    }
};
