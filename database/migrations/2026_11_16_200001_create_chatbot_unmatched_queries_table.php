<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chatbot queries that ended with no product, relaxed words or words the bot did not understand.
 * The admin reads them (Chatbot → Unmatched queries) to decide which words to add to config/chatbot_darija.php.
 * Only the message text and what the bot understood: no user id, no session id, no personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chatbot_unmatched_queries')) {
            return;
        }

        Schema::create('chatbot_unmatched_queries', function (Blueprint $table) {
            $table->id();
            $table->string('message', 500);
            $table->string('dialect', 16)->nullable();   // fr | en | tn_arabizi | tn_arabic | msa
            $table->json('slots')->nullable();           // concept, categories, gender, keywords, colors, relaxed steps
            $table->unsignedSmallInteger('result_count')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('message');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_unmatched_queries');
    }
};
