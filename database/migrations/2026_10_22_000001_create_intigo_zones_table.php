<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Zone (Délégation/Secteur)" list of Intigo's "Nouvelle adresse" form,
 * one row per item, rebuilt from intigo_cities / intigo_districts /
 * intigo_neighborhoods by php artisan intigo:sync-zones (or the snapshot
 * seeder). intigo_id = Intigo's district id (DELEGATION) or neighborhood id
 * (SECTEUR): unique per type only. Rows Intigo stopped listing are kept
 * (active = false) because seller addresses point at them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intigo_zones', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['DELEGATION', 'SECTEUR']);
            $table->unsignedInteger('intigo_id');
            $table->string('name', 120);
            $table->string('name_ar', 120)->nullable();
            $table->unsignedInteger('delegation_id');          // Intigo district id
            $table->string('delegation', 120);
            $table->unsignedInteger('gouvernorat_id');         // Intigo city id
            $table->string('gouvernorat', 120);                // Intigo spelling ("Mannouba")
            $table->string('wilaya', 100)->nullable();         // ours (App\Support\Wilayas)
            $table->string('display_label', 400);              // exactly as the Intigo dropdown shows it
            $table->string('search_text', 600);                // lowercase, accents folded, + Arabic names
            $table->decimal('latitude', 10, 7)->nullable();    // approximate centre (Nominatim, cached)
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('geocode_precision', 12)->nullable(); // secteur | delegation | gouvernorat | none
            $table->timestamp('geocoded_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'intigo_id']);
            $table->index(['active', 'type']);
            $table->index('delegation_id');
        });

        Schema::table('intigo_districts', function (Blueprint $table) {
            // intigo:sync-zones resumes from here (one neighborhoods call per district)
            $table->timestamp('neighborhoods_synced_at')->nullable()->after('name_ar');
        });
    }

    public function down(): void
    {
        Schema::table('intigo_districts', fn (Blueprint $t) => $t->dropColumn('neighborhoods_synced_at'));
        Schema::dropIfExists('intigo_zones');
    }
};
