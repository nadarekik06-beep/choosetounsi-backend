<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Session-scoped memory for the shopping chatbot, stored in the Laravel cache.
 *
 * Keeps what follow-up questions need ("cheaper ones", "in red?"):
 *   turns     last 6 user/assistant turns (trimmed)
 *   filters   the last search filters (keywords, category, price range, sort)
 *   shown     ids and prices of the products shown last
 *   language  the language of the last reply
 *   user_id   who the conversation belongs to (null = guest)
 *
 * Memory is bound to the logged-in user: if a different user (or a guest
 * after logout) shows up with the same session id, they start fresh and
 * never see the previous user's conversation. Personal data (orders) is
 * never written here — callers store a placeholder instead.
 *
 * Expires after 30 minutes of inactivity. Sessions never see each other.
 */
class ChatMemory
{
    private const MAX_TURNS         = 6;
    private const TTL_SECONDS       = 1800;
    private const MAX_CHAR_PER_TURN = 300;

    public function get(string $sessionId, ?int $userId = null): array
    {
        $memory = Cache::get($this->key($sessionId));

        if (!is_array($memory) || ($memory['user_id'] ?? null) !== $userId) {
            return $this->fresh($userId);
        }

        return $memory;
    }

    /**
     * @param array      $shown   [['id' => int, 'price' => float], ...] of the products just shown
     * @param array|null $filters null keeps the previous filters (e.g. after a greeting)
     */
    public function remember(
        string $sessionId, ?int $userId, string $userMessage, string $reply,
        string $language, ?array $filters, array $shown
    ): void {
        $memory = $this->get($sessionId, $userId);

        $memory['turns'][] = ['role' => 'user', 'content' => mb_substr($userMessage, 0, self::MAX_CHAR_PER_TURN)];
        $memory['turns'][] = ['role' => 'assistant', 'content' => mb_substr($reply, 0, self::MAX_CHAR_PER_TURN)];
        $memory['turns']    = array_slice($memory['turns'], -self::MAX_TURNS);
        $memory['language'] = $language;

        if ($filters !== null) {
            $memory['filters'] = $filters;
            $memory['shown']   = $shown;
        }

        Cache::put($this->key($sessionId), $memory, self::TTL_SECONDS);
    }

    public function clear(string $sessionId): void
    {
        Cache::forget($this->key($sessionId));
    }

    private function fresh(?int $userId): array
    {
        return [
            'turns'    => [],
            'filters'  => null,
            'shown'    => [],
            'language' => null,
            'user_id'  => $userId,
        ];
    }

    private function key(string $sessionId): string
    {
        return "chat:mem:{$sessionId}";
    }
}
