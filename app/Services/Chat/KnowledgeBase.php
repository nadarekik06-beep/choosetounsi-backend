<?php

namespace App\Services\Chat;

use App\Services\PlatformFacts;

/**
 * Reads config/chatbot_kb.php and turns a topic + section into a localized
 * answer: intro text, numbered steps and buttons.
 *
 * {placeholders} in the KB are filled from PlatformFacts at runtime, so
 * plan prices, product limits, commission rates and the complaint window
 * always match the code that enforces them.
 */
class KnowledgeBase
{
    public const TOPICS = ['become_vendor', 'place_order', 'track_order', 'returns_complaints', 'account_help'];

    private ?array $values = [];

    public function __construct(
        private PlatformFacts $facts,
        private ActionFactory $actions,
    ) {}

    public function hasSection(string $topic, string $section): bool
    {
        return isset(config("chatbot_kb.{$topic}.sections")[$section]);
    }

    /**
     * @return array{intro: string, steps: array<array{title: string, description: string}>, actions: array, context: string, section: string}
     */
    public function answer(string $topic, ?string $section, string $lang): array
    {
        $kb      = config("chatbot_kb.{$topic}");
        $section = $section && isset($kb['sections'][$section]) ? $section : $kb['default'];
        $data    = $kb['sections'][$section];

        $intro = $this->text($data['intro'], $lang);
        $steps = array_map(fn (array $step) => [
            'title'       => $this->text($step['title'], $lang),
            'description' => $this->text($step['text'], $lang),
        ], $data['steps']);

        $actions = [];
        foreach ($data['links'] ?? [] as $link) {
            $actions[] = $this->actions->link($this->text($link['label'], $lang), $link['url']);
        }
        foreach ($data['quick_replies'] ?? [] as $key) {
            $actions[] = $this->quickReply($key, $lang);
        }

        $context = $intro . "\n" . implode("\n", array_map(
            fn ($i, $s) => ($i + 1) . '. ' . $s['title'] . ': ' . $s['description'],
            array_keys($steps), $steps
        ));

        return [
            'intro'   => $intro,
            'steps'   => $steps,
            'actions' => $this->actions->finalize($actions),
            'context' => $context,
            'section' => $section,
        ];
    }

    /** A quick-reply button from the shared `quick_replies` map. */
    public function quickReply(string $key, string $lang): ?array
    {
        $qr = config("chatbot_kb.quick_replies.{$key}");
        if (!$qr) {
            return null;
        }
        return $this->actions->quickReply($this->text($qr['label'], $lang), $this->text($qr['message'], $lang));
    }

    /** The 4 starter buttons (greeting, "what can you do"). */
    public function starterActions(string $lang): array
    {
        return $this->actions->finalize(array_map(
            fn ($key) => $this->quickReply($key, $lang),
            ['find_product', 'order_how', 'vendor_apply', 'track_order']
        ));
    }

    // ── Text + placeholders ──────────────────────────────────────────────

    private function text(array $variants, string $lang): string
    {
        $raw = $variants[$lang] ?? $variants['en'] ?? '';
        return strtr($raw, $this->values($lang));
    }

    private function values(string $lang): array
    {
        if (isset($this->values[$lang])) {
            return $this->values[$lang];
        }

        $plans = $this->facts->plans();
        $table = $this->facts->commissionTable();

        $values = [
            '{green_max}'       => (string) $plans['free']['max_products'],
            '{red_max}'         => (string) $plans['red']['max_products'],
            '{red_price}'       => $this->money($plans['red']['price'], $lang),
            '{black_price}'     => $this->money($plans['black']['price'], $lang),
            '{red_reduction}'   => $this->num($table['reductions']['red'] ?? 0),
            '{black_reduction}' => $this->num($table['reductions']['black'] ?? 0),
            '{min_rate}'        => $this->num($table['floor']),
            '{complaint_hours}' => (string) $this->facts->complaintWindowHours(),
            '{tier_list}'       => $this->tierList($table['tiers'], $lang),
        ];
        foreach (['free' => 'green', 'red' => 'red', 'black' => 'black'] as $key => $name) {
            $values["{{$name}_commission}"] = $this->percentRange($plans[$key]['commission_min'], $plans[$key]['commission_max'], $lang);
        }

        return $this->values[$lang] = $values;
    }

    private function tierList(array $tiers, string $lang): string
    {
        $parts = [];
        $low   = null;
        foreach ($tiers as $tier) {
            $rate = $this->percent($tier['rate'], $lang);
            if ($low === null) {
                $range = ['en' => 'up to ', 'fr' => 'jusqu\'à ', 'ar' => 'حتى '][$lang] . $this->money($tier['max'], $lang);
            } elseif ($tier['max'] === null) {
                $range = ['en' => 'above ', 'fr' => 'au-delà de ', 'ar' => 'أكثر من '][$lang] . $this->money($low, $lang);
            } else {
                $range = $lang === 'ar'
                    ? 'من ' . $this->num($low) . ' إلى ' . $this->money($tier['max'], $lang)
                    : $this->num($low) . '–' . $this->money($tier['max'], $lang);
            }
            $parts[] = "{$range}: {$rate}";
            $low = $tier['max'];
        }
        return ucfirst(implode(' · ', $parts)) . '.';
    }

    private function percentRange(float $min, float $max, string $lang): string
    {
        return $min === $max
            ? $this->percent($min, $lang)
            : $this->num($min) . '–' . $this->percent($max, $lang);
    }

    private function percent(float $value, string $lang): string
    {
        return $this->num($value) . ($lang === 'fr' ? ' %' : '%');
    }

    private function money(?float $value, string $lang): string
    {
        return $this->num((float) $value) . ($lang === 'ar' ? ' د.ت' : ' DT');
    }

    private function num(float $value): string
    {
        return abs($value - round($value)) < 0.0005 ? (string) (int) round($value) : rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
