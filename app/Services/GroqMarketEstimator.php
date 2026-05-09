<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GroqMarketEstimator
 *
 * Uses Groq LLM as a Tunisian market price knowledge base.
 * Called when web scrapers fail (403 blocks, timeouts, etc.).
 *
 * Groq's llama3 model has strong knowledge of Tunisian e-commerce
 * pricing from its training data. We prompt it specifically to return
 * realistic price ranges for a given product/category on the Tunisian market.
 *
 * Returns results in the same format as web scrapers so the
 * PriceNormalizationService can process them identically.
 *
 * Reliability score: 0.65 (knowledge-based, not live data)
 * Cache TTL: 24 hours (market knowledge changes slowly)
 */
class GroqMarketEstimator
{
    private string $groqApiUrl = 'https://api.groq.com/openai/v1/chat/completions';
    private string $groqModel  = 'llama3-8b-8192';
    private const  CACHE_TTL   = 86400; // 24 hours

    public string $sourceName       = 'Tunisian Market Knowledge';
    public float  $reliabilityScore = 0.65;

    private function groqKey(): string
    {
        return config('services.groq.key', env('GROQ_API_KEY', ''));
    }

    /**
     * Estimate Tunisian market prices for a product using Groq knowledge.
     *
     * @param  string $productName
     * @param  string $categoryName
     * @param  float  $currentPrice  The seller's current price (anchor for estimation)
     * @return array  Same format as scraper results: [['price'=>float,'title'=>string,'source'=>string,'reliability'=>float]]
     */
    public function estimate(string $productName, string $categoryName, float $currentPrice): array
    {
        $key = $this->groqKey();
        if (empty($key)) return [];

        $system = "You are a Tunisian e-commerce market price expert. "
            . "You know the typical price ranges for products sold online in Tunisia (in TND - Tunisian Dinar). "
            . "You are aware of major Tunisian e-commerce platforms: Mytek, Tunisianet, Tayara, Jumia TN (closed), Scoop.tn. "
            . "Respond ONLY with a valid JSON object. No markdown. No explanation outside JSON.";

        $user = "What is the typical price range for '{$productName}' in the '{$categoryName}' category "
            . "on Tunisian e-commerce websites (in TND)? "
            . "The seller's current price is {$currentPrice} TND. "
            . "Consider: Tunisian purchasing power, import costs, local competition, and typical margins.\n\n"
            . "Return ONLY this JSON with realistic TND prices (not zeros, not null):\n"
            . "{\n"
            . "  \"market_min\": <lowest typical price in TND>,\n"
            . "  \"market_max\": <highest typical price in TND>,\n"
            . "  \"market_avg\": <average market price in TND>,\n"
            . "  \"price_samples\": [<price1>, <price2>, <price3>, <price4>, <price5>],\n"
            . "  \"confidence\": \"high\"|\"medium\"|\"low\",\n"
            . "  \"notes\": \"<one sentence about pricing in this category in Tunisia>\"\n"
            . "}\n"
            . "All prices must be realistic for the Tunisian market. "
            . "If this is a niche product with limited Tunisian market data, use your best estimate.";

        try {
            $res = Http::withHeaders([
                'Authorization' => "Bearer {$key}",
                'Content-Type'  => 'application/json',
            ])->timeout(20)->post($this->groqApiUrl, [
                'model'       => $this->groqModel,
                'messages'    => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
                'max_tokens'  => 300,
                'temperature' => 0.2,
            ]);

            if (!$res->successful()) {
                Log::warning("[GroqMarketEstimator] Groq error {$res->status()}");
                return [];
            }

            $raw   = $res->json('choices.0.message.content', '');
            $clean = preg_replace('/```json|```/i', '', $raw);
            $start = strpos($clean, '{');
            $end   = strrpos($clean, '}');

            if ($start === false || $end === false) return [];

            $data = json_decode(substr($clean, $start, $end - $start + 1), true);
            if (!$data) return [];

            return $this->convertToScraperFormat($data, $productName);

        } catch (\Throwable $e) {
            Log::warning("[GroqMarketEstimator] Failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Convert Groq estimation into the standard scraper result format
     * so PriceNormalizationService can process it identically.
     */
    private function convertToScraperFormat(array $data, string $productName): array
    {
        $results = [];

        // Use price_samples if available
        if (!empty($data['price_samples']) && is_array($data['price_samples'])) {
            foreach ($data['price_samples'] as $price) {
                $p = (float)$price;
                if ($p > 0 && $p < 100000) {
                    $results[] = [
                        'price'       => round($p, 3),
                        'title'       => $productName,
                        'source'      => $this->sourceName,
                        'reliability' => $this->reliabilityScore,
                        'url'         => '',
                    ];
                }
            }
        }

        // Also add min/avg/max as data points if samples were insufficient
        foreach (['market_min', 'market_avg', 'market_max'] as $field) {
            $p = (float)($data[$field] ?? 0);
            if ($p > 0 && $p < 100000) {
                // Avoid duplicating if already in samples
                $alreadyPresent = false;
                foreach ($results as $r) {
                    if (abs($r['price'] - $p) < 0.5) { $alreadyPresent = true; break; }
                }
                if (!$alreadyPresent) {
                    $results[] = [
                        'price'       => round($p, 3),
                        'title'       => $productName,
                        'source'      => $this->sourceName,
                        'reliability' => $this->reliabilityScore,
                        'url'         => '',
                    ];
                }
            }
        }

        Log::info("[GroqMarketEstimator] Generated " . count($results) . " price estimates for: {$productName}");
        return $results;
    }
}