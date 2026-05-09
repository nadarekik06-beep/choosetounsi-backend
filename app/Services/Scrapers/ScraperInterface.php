<?php

namespace App\Services\Scrapers;

/**
 * ScraperInterface
 *
 * Contract that every Tunisian market scraper must implement.
 * This ensures all scrapers are modular, swappable, and independently
 * testable. To disable a scraper, set $enabled = false or implement
 * isEnabled() to return false based on config.
 */
interface ScraperInterface
{
    /**
     * Execute a product search and return normalized price results.
     *
     * @param  string $query  Product search query (e.g. "hoodie homme tunisie")
     * @return array  Each item: ['price' => float, 'title' => string, 'url' => string, 'source' => string]
     */
    public function search(string $query): array;

    /**
     * Whether this scraper is currently active.
     */
    public function isEnabled(): bool;

    /**
     * Human-readable source name (e.g. "Mytek").
     */
    public function getSourceName(): string;

    /**
     * Reliability score between 0.0 and 1.0.
     * Used by PriceNormalizationService to weight prices.
     */
    public function getReliabilityScore(): float;
}