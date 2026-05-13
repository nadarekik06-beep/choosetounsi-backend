<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class ChatRateLimiter
{
    private const GEMINI_PER_SESSION_RPM   = 4;
    private const DEEPSEEK_PER_SESSION_RPM = 10;
    private const GROQ_PER_SESSION_RPM     = 15;
    private const WINDOW_SECONDS           = 60;

    public function canUseGemini(string $sessionId): bool
    {
        return $this->check("rl:gemini:{$sessionId}", self::GEMINI_PER_SESSION_RPM);
    }

    public function canUseDeepSeek(string $sessionId): bool
    {
        return $this->check("rl:deepseek:{$sessionId}", self::DEEPSEEK_PER_SESSION_RPM);
    }

    public function canUseGroq(string $sessionId): bool
    {
        return $this->check("rl:groq:{$sessionId}", self::GROQ_PER_SESSION_RPM);
    }

    public function incrementGemini(string $sessionId): void
    {
        $this->increment("rl:gemini:{$sessionId}");
    }

    public function incrementDeepSeek(string $sessionId): void
    {
        $this->increment("rl:deepseek:{$sessionId}");
    }

    public function incrementGroq(string $sessionId): void
    {
        $this->increment("rl:groq:{$sessionId}");
    }

    public function debugSession(string $sessionId): array
    {
        return [
            'gemini_used'   => (int) Cache::get("rl:gemini:{$sessionId}", 0),
            'gemini_limit'  => self::GEMINI_PER_SESSION_RPM,
            'deepseek_used' => (int) Cache::get("rl:deepseek:{$sessionId}", 0),
            'deepseek_limit'=> self::DEEPSEEK_PER_SESSION_RPM,
            'groq_used'     => (int) Cache::get("rl:groq:{$sessionId}", 0),
            'groq_limit'    => self::GROQ_PER_SESSION_RPM,
        ];
    }

    private function check(string $key, int $limit): bool
    {
        return (int) Cache::get($key, 0) < $limit;
    }

    private function increment(string $key): void
    {
        // File driver safe: put with TTL on first write, plain increment after
        if (!Cache::has($key)) {
            Cache::put($key, 1, self::WINDOW_SECONDS);
        } else {
            Cache::increment($key);
        }
    }
}