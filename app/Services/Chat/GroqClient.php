<?php

namespace App\Services\Chat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only class that talks to Groq (chatbot, translations, ad copy, seller AI tools).
 *
 * Free-tier guard rails:
 *   - 429 is never retried; the caller falls back to a template reply.
 *   - A local per-minute / per-day counter stops calling Groq before the
 *     free quota is exhausted (config services.groq.daily_budget / minute_budget).
 *     A caller can pass `reserve` to stop even earlier and leave calls for others.
 *   - Every call and every 429 is logged with Groq's remaining-quota headers.
 *
 * Returns null on any failure — callers must always have a non-AI fallback.
 */
class GroqClient
{
    private const URL = 'https://api.groq.com/openai/v1/chat/completions';

    /** Set after each call so callers can tell a 429 from other failures. */
    public ?string $lastError = null;

    /** Seconds Groq asked us to wait after a 429 (null when unknown). */
    public ?int $retryAfter = null;

    /** Model that produced the last successful answer. */
    public ?string $lastModel = null;

    public function isConfigured(): bool
    {
        return !empty(config('services.groq.key'));
    }

    /**
     * @param array  $messages OpenAI-style [['role' => ..., 'content' => ...]]
     * @param string $purpose  short label for logs ("intent", "reply")
     * @param array  $options  optional overrides: model, temperature, top_p, timeout,
     *                         reasoning_effort, reserve (calls to leave in the daily budget),
     *                         schema + schema_name (JSON schema for strict structured output)
     */
    public function chat(array $messages, string $purpose, bool $json = false, int $maxTokens = 400, array $options = []): ?string
    {
        $this->lastError  = null;
        $this->retryAfter = null;
        $this->lastModel  = null;

        if (!$this->isConfigured()) {
            $this->lastError = 'not_configured';
            Log::warning('[Groq] GROQ_API_KEY is not set');
            return null;
        }

        if (!$this->withinBudget((int) ($options['reserve'] ?? 0))) {
            $this->lastError = 'budget';
            Log::warning('[Groq] Local free-tier budget reached, skipping call', ['purpose' => $purpose]);
            return null;
        }

        $model   = $options['model'] ?? config('services.groq.model');
        $payload = [
            'model'                 => $model,
            'messages'              => $messages,
            'temperature'           => $options['temperature'] ?? ($json ? 0 : 0.4),
            'max_completion_tokens' => $maxTokens,
            'reasoning_effort'      => $options['reasoning_effort'] ?? 'low',
            'include_reasoning'     => false,
        ];
        if (isset($options['top_p'])) {
            $payload['top_p'] = $options['top_p'];
        }
        if ($json && isset($options['schema'])) {
            // Structured outputs: decoding is constrained to the schema (gpt-oss models)
            $payload['response_format'] = ['type' => 'json_schema', 'json_schema' => [
                'name'   => $options['schema_name'] ?? 'result',
                'strict' => true,
                'schema' => $options['schema'],
            ]];
        } elseif ($json) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        $timeout = (int) ($options['timeout'] ?? config('services.groq.timeout', 15));

        // One retry, only for network errors and 5xx. Never for 429 / 4xx.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $started = microtime(true);
            try {
                $response = Http::timeout($timeout)
                    ->withToken(config('services.groq.key'))
                    ->acceptJson()
                    ->post(self::URL, $payload);
            } catch (ConnectionException $e) {
                Log::warning('[Groq] Connection error', ['purpose' => $purpose, 'attempt' => $attempt, 'error' => $e->getMessage()]);
                $this->lastError = 'connection';
                continue;
            }

            $ms        = (int) round((microtime(true) - $started) * 1000);
            $dailyUsed = $this->countCall();
            $quota     = [
                'remaining_requests_day' => $response->header('x-ratelimit-remaining-requests'),
                'remaining_tokens_min'   => $response->header('x-ratelimit-remaining-tokens'),
            ];

            if ($response->status() === 429) {
                $this->lastError  = 'rate_limited';
                $retry            = $response->header('retry-after');
                $this->retryAfter = is_numeric($retry) ? (int) ceil((float) $retry) : null;
                Log::warning('[Groq] 429 rate limited', [
                    'purpose'     => $purpose,
                    'model'       => $model,
                    'retry_after' => $retry,
                    'local_daily' => $dailyUsed,
                ] + $quota);
                return null;
            }

            if ($response->serverError()) {
                $this->lastError = 'server_' . $response->status();
                Log::warning('[Groq] Server error', ['purpose' => $purpose, 'attempt' => $attempt, 'status' => $response->status()]);
                continue;
            }

            if ($response->failed()) {
                $this->lastError = 'http_' . $response->status();
                Log::error('[Groq] Request failed', [
                    'purpose' => $purpose,
                    'model'   => $model,
                    'status'  => $response->status(),
                    'body'    => mb_substr($response->body(), 0, 500),
                ]);
                return null;
            }

            $content = trim((string) $response->json('choices.0.message.content'));

            Log::info('[Groq] call', [
                'purpose'       => $purpose,
                'model'         => $model,
                'ms'            => $ms,
                'total_tokens'  => $response->json('usage.total_tokens'),
                'finish_reason' => $response->json('choices.0.finish_reason'),
                'local_daily'   => $dailyUsed,
            ] + $quota);

            if ($content === '') {
                $this->lastError = 'empty';
                return null;
            }

            $this->lastModel = $model;
            return $content;
        }

        return null;
    }

    /**
     * chat() in JSON mode, decoded. Strips code fences in case the model adds them.
     */
    public function chatJson(array $messages, string $purpose, int $maxTokens = 300, array $options = []): ?array
    {
        $raw = $this->chat($messages, $purpose, true, $maxTokens, $options);
        if ($raw === null) {
            return null;
        }

        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($raw));
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }

        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('[Groq] Invalid JSON from model', ['purpose' => $purpose, 'raw' => mb_substr($raw, 0, 300)]);
            $this->lastError = 'bad_json';
            return null;
        }

        return is_array($data) ? $data : null;
    }

    // ── Local free-tier counters ─────────────────────────────────────────

    private function withinBudget(int $reserve = 0): bool
    {
        $minute = (int) Cache::get($this->minuteKey(), 0);
        $day    = (int) Cache::get($this->dayKey(), 0);

        return $minute < (int) config('services.groq.minute_budget', 25)
            && $day < (int) config('services.groq.daily_budget', 950) - max(0, $reserve);
    }

    private function countCall(): int
    {
        $this->bump($this->minuteKey(), 70);
        return $this->bump($this->dayKey(), 90000);
    }

    private function bump(string $key, int $ttl): int
    {
        if (!Cache::has($key)) {
            Cache::put($key, 1, $ttl);
            return 1;
        }
        return (int) Cache::increment($key);
    }

    private function minuteKey(): string
    {
        return 'groq:usage:min:' . now()->format('YmdHi');
    }

    private function dayKey(): string
    {
        return 'groq:usage:day:' . now()->format('Ymd');
    }
}
