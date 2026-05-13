<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiRouter
{
    private const CACHE_TTL_SECONDS = 300;

    private const GEMINI_URL     = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';
    private const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';
    private const DEEPSEEK_MODEL = 'deepseek/deepseek-r1:free';
    private const GROQ_URL       = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct(
        private ChatRateLimiter $limiter,
        private ChatMemory      $memory,
    ) {}

    public function respond(
        string $sessionId,
        string $preferredProvider,
        string $systemPrompt,
        array  $geminiMessages,
        array  $openaiMessages,
    ): array {
        // 1. Cache hit
        $cacheKey = $this->cacheKey($sessionId, $geminiMessages);
        if ($cached = Cache::get($cacheKey)) {
            Log::info('[AiRouter] Cache hit', ['session' => $sessionId]);
            return ['text' => $cached, 'provider' => 'cache'];
        }

        Log::info('[AiRouter] Starting provider chain', [
            'session'   => $sessionId,
            'preferred' => $preferredProvider,
            'rl_debug'  => $this->limiter->debugSession($sessionId),
        ]);

        // 2. Provider chain
        $chain = $preferredProvider === 'deepseek'
            ? ['deepseek', 'gemini', 'groq']
            : ['gemini', 'deepseek', 'groq'];

        foreach ($chain as $provider) {
            $result = match ($provider) {
                'gemini'   => $this->tryGemini($sessionId, $systemPrompt, $geminiMessages),
                'deepseek' => $this->tryDeepSeek($sessionId, $systemPrompt, $openaiMessages),
                'groq'     => $this->tryGroq($sessionId, $systemPrompt, $openaiMessages),
                default    => null,
            };

            if ($result !== null) {
                $this->store($sessionId, $cacheKey, $geminiMessages, $result, $provider);
                return ['text' => $result, 'provider' => $provider];
            }

            Log::info("[AiRouter] {$provider} failed, trying next", ['session' => $sessionId]);
        }

        // 3. Memory degrade
        $lastTurn = $this->memory->lastAssistantTurn($sessionId);
        if ($lastTurn) {
            Log::warning('[AiRouter] All providers failed — memory degrade', ['session' => $sessionId]);
            return [
                'text'     => $lastTurn . "\n\n*(service temporarily busy — cached response)*",
                'provider' => 'degraded',
            ];
        }

        // 4. Static degrade
        Log::warning('[AiRouter] All providers failed — static degrade', ['session' => $sessionId]);
        return ['text' => $this->staticDegrade(), 'provider' => 'degraded'];
    }

    // ── Gemini ────────────────────────────────────────────────────────────

    private function tryGemini(string $sessionId, string $systemPrompt, array $messages): ?string
    {
        if (!$this->limiter->canUseGemini($sessionId)) {
            Log::info('[AiRouter] Gemini session limit reached', ['session' => $sessionId]);
            return null;
        }

        $apiKey = config('services.gemini.api_key');
        if (empty($apiKey)) {
            Log::warning('[AiRouter] Gemini key not configured');
            return null;
        }

        try {
            $response = Http::timeout(20)->post(
                self::GEMINI_URL . '?key=' . urlencode($apiKey),
                [
                    'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                    'contents'           => $messages,
                    'generationConfig'   => [
                        'maxOutputTokens' => 512,
                        'temperature'     => 0.4,
                        'topP'            => 0.9,
                    ],
                ]
            );

            Log::info('[AiRouter] Gemini HTTP status', [
                'status'  => $response->status(),
                'session' => $sessionId,
            ]);

            if ($response->status() === 429) {
                Log::warning('[AiRouter] Gemini global quota exhausted (429)', ['session' => $sessionId]);
                return null;
            }

            if ($response->failed()) {
                Log::error('[AiRouter] Gemini failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            $text = $response->json('candidates.0.content.parts.0.text');
            if (empty($text)) {
                Log::warning('[AiRouter] Gemini returned empty text', ['body' => $response->body()]);
                return null;
            }

            $this->limiter->incrementGemini($sessionId);
            return $text;

        } catch (\Throwable $e) {
            Log::error('[AiRouter] Gemini exception: ' . $e->getMessage(), ['session' => $sessionId]);
            return null;
        }
    }

    // ── DeepSeek via OpenRouter ───────────────────────────────────────────

    private function tryDeepSeek(string $sessionId, string $systemPrompt, array $messages): ?string
    {
        if (!$this->limiter->canUseDeepSeek($sessionId)) {
            Log::info('[AiRouter] DeepSeek session limit reached', ['session' => $sessionId]);
            return null;
        }

        $apiKey = config('services.openrouter.key');
        if (empty($apiKey)) {
            Log::info('[AiRouter] OpenRouter key not configured — skipping DeepSeek');
            return null;
        }

        try {
            $allMessages = array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $messages
            );

            $response = Http::timeout(25)
                ->withToken($apiKey)
                ->withHeaders([
                    'HTTP-Referer' => config('app.url', 'http://localhost:3000'),
                    'X-Title'      => 'ChooseTounsi',
                ])
                ->post(self::OPENROUTER_URL, [
                    'model'       => self::DEEPSEEK_MODEL,
                    'messages'    => $allMessages,
                    'max_tokens'  => 512,
                    'temperature' => 0.4,
                ]);

            Log::info('[AiRouter] DeepSeek HTTP status', [
                'status'  => $response->status(),
                'session' => $sessionId,
            ]);

            if ($response->status() === 429) {
                Log::warning('[AiRouter] DeepSeek 429', ['session' => $sessionId]);
                return null;
            }

            if ($response->failed()) {
                Log::error('[AiRouter] DeepSeek failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            $text = $response->json('choices.0.message.content');
            if (empty($text)) return null;

            $text = trim(preg_replace('/<think>.*?<\/think>/s', '', $text));
            if (empty($text)) return null;

            $this->limiter->incrementDeepSeek($sessionId);
            return $text;

        } catch (\Throwable $e) {
            Log::error('[AiRouter] DeepSeek exception: ' . $e->getMessage(), ['session' => $sessionId]);
            return null;
        }
    }

    // ── Groq (you already use this — reuse same key) ──────────────────────

    private function tryGroq(string $sessionId, string $systemPrompt, array $messages): ?string
    {
        if (!$this->limiter->canUseGroq($sessionId)) {
            Log::info('[AiRouter] Groq session limit reached', ['session' => $sessionId]);
            return null;
        }

        $apiKey = config('services.groq.key');
        if (empty($apiKey)) {
            Log::info('[AiRouter] Groq key not configured — skipping');
            return null;
        }

        try {
            $allMessages = array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $messages
            );

            $response = Http::timeout(20)
                ->withToken($apiKey)
                ->post(self::GROQ_URL, [
                    'model' => 'llama-3.1-8b-instant',
                    'messages'    => $allMessages,
                    'max_tokens'  => 512,
                    'temperature' => 0.4,
                ]);

            Log::info('[AiRouter] Groq HTTP status', [
                'status'  => $response->status(),
                'session' => $sessionId,
            ]);

            if ($response->status() === 429) {
                Log::warning('[AiRouter] Groq 429', ['session' => $sessionId]);
                return null;
            }

            if ($response->failed()) {
                Log::error('[AiRouter] Groq failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            $text = $response->json('choices.0.message.content');
            if (empty($text)) return null;

            $this->limiter->incrementGroq($sessionId);
            return $text;

        } catch (\Throwable $e) {
            Log::error('[AiRouter] Groq exception: ' . $e->getMessage(), ['session' => $sessionId]);
            return null;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function store(
        string $sessionId,
        string $cacheKey,
        array  $geminiMessages,
        string $text,
        string $provider,
    ): void {
        Cache::put($cacheKey, $text, self::CACHE_TTL_SECONDS);

        $lastUserMsg = '';
        foreach (array_reverse($geminiMessages) as $m) {
            if ($m['role'] === 'user') {
                $lastUserMsg = $m['parts'][0]['text'] ?? '';
                break;
            }
        }

        if (!empty($lastUserMsg)) {
            $this->memory->append($sessionId, 'user', $lastUserMsg);
        }
        $this->memory->append($sessionId, 'model', $text);

        Log::info('[AiRouter] Stored response', [
            'provider' => $provider,
            'session'  => $sessionId,
        ]);
    }

    private function cacheKey(string $sessionId, array $messages): string
    {
        $lastUser = '';
        foreach (array_reverse($messages) as $m) {
            if ($m['role'] === 'user') {
                $lastUser = $m['parts'][0]['text'] ?? '';
                break;
            }
        }
        return 'chat:resp:' . $sessionId . ':' . md5($lastUser);
    }

    private function staticDegrade(): string
    {
        $messages = [
            "Je suis temporairement surchargé. Veuillez réessayer dans quelques secondes. 🙏",
            "خدمة الذكاء الاصطناعي مشغولة حالياً. يرجى المحاولة مرة أخرى بعد لحظات.",
            "The AI assistant is temporarily busy. Please try again in a moment. 🙏",
        ];
        return $messages[array_rand($messages)];
    }
}