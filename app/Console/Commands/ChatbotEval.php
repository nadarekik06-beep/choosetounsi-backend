<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\AiChatController;
use App\Services\Chat\IntentExtractor;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Chatbot evaluation.
 *
 *   php artisan chatbot:eval                    slot assertions of every fixture (no Groq call, no DB write)
 *   php artisan chatbot:eval --say="nheb sabat" run ONE message through the whole pipeline and print the transcript
 *                                               (repeat --say for a conversation: --say="n7eb sabat lel nsa" --say="rkhis akther")
 *   php artisan chatbot:eval --live             also run every fixture through the whole pipeline (uses Groq if a key is set)
 *
 * Fixtures: database/fixtures/chatbot_eval.php
 */
class ChatbotEval extends Command
{
    protected $signature = 'chatbot:eval {--say=* : message(s) to run through the whole pipeline, as one conversation} {--live : run every fixture through the whole pipeline} {--locale=fr}';
    protected $description = 'Evaluate the shopping chatbot (language, slots, hard filters) on the fixture prompts';

    public function handle(IntentExtractor $extractor): int
    {
        if ($say = array_filter((array) $this->option('say'))) {
            $this->conversation($say);
            return self::SUCCESS;
        }

        // Rules only: never spend Groq calls on the slot assertions
        config(['services.groq.key' => null]);

        $fixtures = require base_path('database/fixtures/chatbot_eval.php');
        $failed   = 0;

        foreach ($fixtures as $fx) {
            $problems = $this->check($extractor, $fx);
            $this->line(($problems ? '<fg=red>FAIL</>' : '<fg=green>ok  </>') . '  ' . $fx['say']);
            foreach ($problems as $p) {
                $this->line("        - {$p}");
            }
            $failed += $problems ? 1 : 0;
        }

        $this->info(count($fixtures) - $failed . '/' . count($fixtures) . ' fixtures pass');

        if ($this->option('live')) {
            foreach ($fixtures as $fx) {
                $this->conversation([$fx['say']]);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return string[] what differs from the fixture */
    public function check(IntentExtractor $extractor, array $fx): array
    {
        $detector = app(\App\Services\Chat\LanguageDetector::class);
        $intent   = $extractor->extract($fx['say'], ['filters' => null, 'turns' => [], 'shown' => [], 'language' => null, 'dialect' => null]);
        $dialect  = $detector->detect($fx['say']);
        $got = [
            'dialect' => $dialect, 'intent' => $intent['intent'], 'section' => $intent['section'] ?? null,
            'concept' => $intent['concept'], 'gender' => $intent['gender'], 'colors' => $intent['colors'],
            'max' => $intent['max_price'], 'min' => $intent['min_price'], 'sort' => $intent['sort'],
            'sizes' => $intent['sizes'], 'only_promo' => $intent['only_promo'], 'child' => $intent['child'],
        ];

        $problems = [];
        foreach ($fx as $key => $expected) {
            if ($key === 'say') {
                continue;
            }
            $actual = $got[$key] ?? null;
            if (in_array($key, ['max', 'min'], true)) {
                $same = $actual !== null && abs((float) $actual - (float) $expected) < 0.01;
            } else {
                $same = $actual === $expected;
            }
            if (!$same) {
                $problems[] = "{$key}: expected " . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE);
            }
        }

        return $problems;
    }

    private function conversation(array $messages): void
    {
        $session = 'eval_' . Str::random(10);

        foreach ($messages as $message) {
            $request = Request::create('/api/ai/chat', 'POST', ['message' => $message, 'session_id' => $session, 'locale' => $this->option('locale')]);
            $request->headers->set('Accept', 'application/json');
            $data = app(AiChatController::class)->handle($request)->getData(true);

            $this->newLine();
            $this->line("<fg=cyan>customer ›</> {$message}");
            $this->line("<fg=yellow>bot [{$data['dialect']} / {$data['intent']}] ›</> {$data['reply']}");
            foreach ($data['products'] as $p) {
                $this->line(sprintf('    #%d  %s  |  %s DT%s  |  gender=%s  |  %s', $p['id'], $p['name'], $p['price'],
                    $p['old_price'] ? " (was {$p['old_price']})" : '', $p['gender'] ?? 'none', $p['seller'] ?? ''));
            }
            foreach ($data['steps'] as $s) {
                $this->line("    • {$s['title']}: {$s['description']}");
            }
            $this->line('    chips: ' . implode('  |  ', array_map(
                fn ($a) => $a['label'] . ($a['type'] === 'link' ? ' → ' . $a['url'] : ''), $data['actions'])));
        }
    }
}
