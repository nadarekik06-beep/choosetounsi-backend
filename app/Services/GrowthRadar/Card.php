<?php

namespace App\Services\GrowthRadar;

/**
 * One candidate action card, as a detector builds it. Persisted by GrowthRadar
 * into growth_cards; the dashboard renders headline / recommendation text from
 * `type` + `params` in its own i18n files.
 */
class Card
{
    public const TYPES = ['leaking_product', 'price_position', 'hidden_demand', 'warm_audience', 'seasonal', 'dead_stock', 'promo_timing'];

    public ?int $impactLow = null;
    public ?int $impactHigh = null;
    public float $multiplier = 1.0;

    /**
     * @param array $params    numbers the headline / recommendation text uses
     * @param array $evidence  {numbers: [{key, value, unit?, compare?}], chart?: {kind, …}}
     * @param array $action    {kind, …prefill}; kind: discount | flash_sale | coupon | boost | edit | listing | bundle
     * @param array $basis     where the numbers come from: own | category | platform | seasonal | none
     */
    public function __construct(
        public string $type,
        public string $fingerprint,
        public ?int $productId,
        public string $confidence,
        public array $params,
        public array $evidence,
        public array $action,
        public ?array $alt = null,
        public array $basis = [],
    ) {}

    /** Impact range in TND, rounded so it never looks more precise than it is. */
    public function impact(?float $low, ?float $high, float $multiplier = 1.0): self
    {
        $this->multiplier = $multiplier;
        if ($low === null || $high === null || $high <= 0) {
            $this->impactLow = $this->impactHigh = null;
            return $this;
        }
        $low *= $multiplier;
        $high *= $multiplier;
        $step = $high >= 500 ? 50 : ($high >= 100 ? 10 : 5);
        $lo = (int) (floor(max(0, $low) / $step) * $step);
        $hi = (int) (ceil($high / $step) * $step);
        if ($hi <= $lo) $hi = $lo + $step;
        $this->impactLow = $lo;
        $this->impactHigh = $hi;
        return $this;
    }

    /** Sort key: low-confidence cards always rank below medium / high ones, then by impact. */
    public function rank(): float
    {
        $mid = $this->impactHigh !== null ? ($this->impactLow + $this->impactHigh) / 2 : 0;
        return ($this->confidence === 'low' ? 0 : 1_000_000) + $mid;
    }

    public function payload(): array
    {
        return [
            'params'   => $this->params,
            'evidence' => $this->evidence,
            'action'   => $this->action,
            'alt'      => $this->alt,
            'basis'    => $this->basis,
            'learned'  => $this->multiplier !== 1.0 ? round($this->multiplier, 2) : null,
        ];
    }

    /** low / medium / high from how many views the estimate stands on. */
    public static function confidenceFromViews(int $views): string
    {
        $c = config('growth.confidence_views');
        return $views >= $c['high'] ? 'high' : ($views >= $c['medium'] ? 'medium' : 'low');
    }

    /** The weaker of two confidence levels. */
    public static function weaker(string $a, string $b): string
    {
        $order = ['low' => 0, 'medium' => 1, 'high' => 2];
        return $order[$a] <= $order[$b] ? $a : $b;
    }

    public static function lower(string $confidence): string
    {
        return ['high' => 'medium', 'medium' => 'low', 'low' => 'low'][$confidence];
    }
}
