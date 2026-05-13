<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the chat_messages table used by AiChatController (Fix 6).
 *
 * Purpose:
 *   Persists every user ↔ assistant turn so that conversation history
 *   survives page refreshes, tab closes, and frontend state resets.
 *   The controller reads from this table when the frontend sends an
 *   empty history array, and writes both turns after every successful
 *   AI response.
 *
 * Design decisions:
 *   - session_id is the only grouping key; no user_id FK because the
 *     endpoint is public (unauthenticated users can chat too).
 *   - intent is nullable — only the assistant rows carry it.
 *   - No soft deletes; old rows are cheap and useful for analytics.
 *   - Index on (session_id, created_at) to make chronological fetches fast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();

            // Session identifier sent by the frontend (no FK — public endpoint)
            $table->string('session_id', 120);

            // 'user' or 'assistant'
            $table->enum('role', ['user', 'assistant']);

            // Message body — TEXT to accommodate long AI responses
            $table->text('content');

            // Intent classification stored on assistant rows; null on user rows
            $table->string('intent', 50)->nullable();

            $table->timestamps();

            // Primary access pattern: fetch last N messages for a session ordered by time
            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};