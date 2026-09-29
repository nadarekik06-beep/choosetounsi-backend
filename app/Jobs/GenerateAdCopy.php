<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\Sponsorship;
use App\Services\Chat\GroqClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Writes a campaign's ad line and keyword tags with Groq (the shared GroqClient,
 * so its model setting and free-tier budget apply). Campaigns start with the
 * template copy from fallback(), so an ad never waits on this job.
 */
class GenerateAdCopy implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(public int $sponsorshipId)
    {
        $this->afterCommit = true;
    }

    public function handle(GroqClient $groq): void
    {
        $campaign = Sponsorship::with('product.category:id,name')->find($this->sponsorshipId);
        if (!$campaign || !$campaign->product || !$groq->isConfigured()) {
            return;
        }

        $product = $campaign->product;
        $name    = (string) ($product->getRawOriginal('name') ?? $product->name);
        $desc    = mb_substr(trim(strip_tags((string) ($product->getRawOriginal('description') ?? ''))), 0, 600);

        $data = $groq->chatJson([
            ['role' => 'system', 'content' => 'You write short ads for ChooseTounsi, a Tunisian marketplace. Reply with JSON only.'],
            ['role' => 'user', 'content' => "Product: {$name}\nCategory: {$product->category?->name}\nDescription: {$desc}\n\n"
                . 'Return {"tags":["6 search keywords"],"ad_copy":"one punchy sentence, max 120 characters, in French, no emojis, no invented claims"}'],
        ], 'ad_copy', 250);

        $copy = trim((string) ($data['ad_copy'] ?? ''));
        $tags = array_values(array_filter(array_map(fn ($t) => is_string($t) ? trim($t) : null, (array) ($data['tags'] ?? []))));
        if ($copy === '') {
            return;   // keep the template copy
        }

        $campaign->update([
            'ai_ad_copy' => mb_substr($copy, 0, 160),
            'ai_tags'    => array_slice($tags, 0, 8) ?: $campaign->ai_tags,
        ]);
    }

    /** Template copy used until (or instead of) the AI version. */
    public static function fallback(Product $product): array
    {
        $name  = (string) ($product->getRawOriginal('name') ?? $product->name);
        $words = preg_split('/[\s\-_,.]+/u', mb_strtolower($name . ' ' . ($product->category?->name ?? '')));
        $tags  = array_slice(array_values(array_unique(array_filter($words, fn ($w) => mb_strlen($w) >= 3))), 0, 6);

        return [
            'tags'    => array_merge($tags, ['tunisien', 'choosetounsi']),
            'ad_copy' => "Découvrez {$name} sur ChooseTounsi — qualité tunisienne garantie !",
        ];
    }
}
