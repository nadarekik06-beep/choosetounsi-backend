<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Session-scoped conversation memory.
 *
 * Stores the last N turns per session_id in Laravel cache.
 * Completely isolated: session A's history is invisible to session B.
 *
 * Used by AiRouter to:
 *   1. Prepend history to every AI call (context window)
 *   2. Retrieve the last assistant turn as a graceful-degrade response
 *      when both Gemini and DeepSeek are unavailable
 */
class ChatMemory
{
    private const MAX_TURNS      = 6;   // 3 user + 3 assistant turns kept
    private const TTL_SECONDS    = 1800; // 30 minutes of inactivity clears memory
    private const MAX_CHAR_PER_TURN = 500; // cap token cost per stored turn

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Returns the full conversation history for a session.
     * Each entry: ['role' => 'user'|'model'|'assistant', 'content' => string]
     */
    public function get(string $sessionId): array
    {
        return Cache::get($this->key($sessionId), []);
    }

    /**
     * Appends one turn and trims to MAX_TURNS pairs.
     * $role: 'user' | 'model' (Gemini) | 'assistant' (DeepSeek/OpenAI-compat)
     */
    public function append(string $sessionId, string $role, string $content): void
    {
        $history   = $this->get($sessionId);
        $history[] = [
            'role'    => $role,
            'content' => mb_substr($content, 0, self::MAX_CHAR_PER_TURN),
        ];

        // Keep only the last MAX_TURNS * 2 entries (pairs of user+assistant)
        if (count($history) > self::MAX_TURNS * 2) {
            $history = array_slice($history, -(self::MAX_TURNS * 2));
        }

        Cache::put($this->key($sessionId), $history, self::TTL_SECONDS);
    }

    /**
     * Returns the most recent assistant/model response stored in memory.
     * Used as a graceful-degrade fallback when all AI providers are unavailable.
     */
    public function lastAssistantTurn(string $sessionId): ?string
    {
        $history = $this->get($sessionId);

        foreach (array_reverse($history) as $turn) {
            if (in_array($turn['role'], ['model', 'assistant'], true)) {
                return $turn['content'];
            }
        }

        return null;
    }

    /**
     * Clears a session's memory (e.g. on explicit reset from frontend).
     */
    public function clear(string $sessionId): void
    {
        Cache::forget($this->key($sessionId));
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function key(string $sessionId): string
    {
        return "chat:mem:{$sessionId}";
    }
}