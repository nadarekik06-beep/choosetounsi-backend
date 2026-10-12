<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** After-sale returns stay manual in the Intigo dashboard; the admin pastes its reference here. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->string('intigo_return_ref', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('complaints', fn (Blueprint $table) => $table->dropColumn('intigo_return_ref'));
    }
};
