<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where the courier collects a seller's parcels, 1:1 with Intigo's "Nouvelle
 * adresse" form (Adresse complète, Zone, map pin) plus contact, phone, postal
 * code and driver notes. One row per seller (user_id unique).
 *
 * Status:
 *   needs_review              legacy seller: the old free-text address was not
 *                             converted (kept in legacy_snapshot for the admin)
 *   change_pending            the seller changed it: Intigo must be updated
 *   address_verified_by_admin the admin checked it
 *   intigo_pickup_created     it exists at Intigo (users.intigo_pickup_index)
 * Parcels are only sent to Intigo once verified AND created.
 *
 * Every change is logged in seller_address_changes (old vs new values).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_pickup_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('contact_name', 191)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('adresse_complete', 255)->nullable();   // Intigo street_address: 255 max
            $table->foreignId('intigo_zone_id')->nullable()->constrained('intigo_zones')->nullOnDelete();
            $table->string('gouvernorat', 120)->nullable();       // derived from the zone, never typed
            $table->char('code_postal', 4)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('indications', 500)->nullable();

            $table->boolean('needs_review')->default(false);
            $table->boolean('change_pending')->default(false);
            $table->boolean('address_verified_by_admin')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('intigo_pickup_created')->default(false);
            $table->timestamp('intigo_pickup_created_at')->nullable();
            $table->string('intigo_pickup_created_via', 10)->nullable();   // api | manual
            $table->timestamp('last_address_change_at')->nullable();
            $table->json('legacy_snapshot')->nullable();
            $table->timestamps();

            $table->index(['change_pending', 'address_verified_by_admin', 'intigo_pickup_created'], 'spa_status_index');
        });

        Schema::create('seller_address_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();      // the seller
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_role', 20);     // seller | admin
            $table->string('source', 30);              // application | settings | admin
            $table->json('old_values')->nullable();
            $table->json('new_values');
            $table->json('changed_fields');
            $table->unsignedInteger('pending_pickups')->default(0);   // parcels not collected yet at that time
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'id']);
        });

        // Every seller (demo users included) starts as "needs review": the old
        // free-text address is kept for reference, never converted.
        $now  = now();
        $apps = DB::table('seller_applications')->orderBy('id')->get()->keyBy('user_id');   // latest per user wins
        $rows = [];
        foreach ($apps as $userId => $app) {
            $rows[] = [
                'user_id'         => $userId,
                'contact_name'    => $app->full_name ?: null,
                'phone'           => $app->phone_number ?: null,
                'needs_review'    => true,
                'legacy_snapshot' => json_encode(array_filter([
                    'wilaya'      => $app->wilaya,
                    'city'        => $app->city,
                    'address'     => $app->pickup_address,
                    'postal_code' => $app->pickup_postal_code,
                    'notes'       => $app->pickup_notes,
                ], fn ($v) => $v !== null && $v !== ''), JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('seller_pickup_addresses')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_address_changes');
        Schema::dropIfExists('seller_pickup_addresses');
    }
};
