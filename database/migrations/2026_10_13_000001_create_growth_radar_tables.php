<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Growth Radar (seller dashboard): weekly score, action cards, applied actions
 * and their measured results — plus the two tracking gaps it needs filled:
 * a category on missed searches and targeted coupons.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->date('week_start');                                 // Monday (Africa/Tunis)
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedTinyInteger('pricing')->nullable();         // null = not enough data
            $table->unsignedTinyInteger('visibility')->nullable();
            $table->unsignedTinyInteger('conversion')->nullable();
            $table->unsignedTinyInteger('stock')->nullable();
            $table->json('inputs')->nullable();                         // numbers behind the sub-scores + unlock hints
            $table->timestamps();
            $table->unique(['seller_id', 'week_start']);
        });

        Schema::create('growth_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->date('week_start');
            $table->string('type', 24);                                 // leaking_product | price_position | hidden_demand | warm_audience | seasonal | dead_stock | promo_timing
            $table->string('fingerprint', 120);                         // type + subject, stable across days
            $table->unsignedBigInteger('product_id')->nullable();
            $table->enum('status', ['new', 'dismissed', 'snoozed', 'applied', 'expired'])->default('new');
            $table->timestamp('snoozed_until')->nullable();
            $table->enum('confidence', ['low', 'medium', 'high']);
            $table->unsignedInteger('impact_low')->nullable();          // TND over the card's horizon
            $table->unsignedInteger('impact_high')->nullable();
            $table->double('rank')->default(0);
            $table->json('payload');                                    // evidence, recommendation, action prefill
            $table->json('headlines')->nullable();                      // locale => AI rewrite of the headline
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
            $table->unique(['seller_id', 'week_start', 'fingerprint']);
            $table->index(['seller_id', 'status']);
            $table->index(['seller_id', 'fingerprint']);
        });

        Schema::create('growth_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('card_id')->nullable();
            $table->string('card_type', 24)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('kind', 16);                                 // discount | flash_sale | coupon | boost | edit | listing | bundle
            $table->unsignedBigInteger('ref_id')->nullable();           // promotion / coupon / sponsorship id
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->enum('status', ['running', 'measured'])->default('running');
            $table->enum('verdict', ['win', 'loss', 'neutral', 'unclear'])->nullable();
            $table->json('result')->nullable();
            $table->timestamp('measured_at')->nullable();
            $table->timestamps();
            $table->index(['seller_id', 'status']);
            $table->index(['status', 'ends_at']);
        });

        // A coupon with audience rows is only valid for those buyers. The seller only ever sees the count.
        Schema::create('coupon_audiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['coupon_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('is_active');
        });

        Schema::table('search_missed_queries', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->after('example');
            $table->index(['category_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::table('search_missed_queries', function (Blueprint $table) {
            $table->dropIndex(['category_id', 'day']);
            $table->dropColumn('category_id');
        });
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
        Schema::dropIfExists('coupon_audiences');
        Schema::dropIfExists('growth_actions');
        Schema::dropIfExists('growth_cards');
        Schema::dropIfExists('growth_snapshots');
    }
};
