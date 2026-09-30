<?php

namespace App\Services\Ads;

use App\Services\Recommendation\InteractionTracker;
use Illuminate\Http\Request;

/** What the ad server is asked for: who is looking, where, and at what. */
class AdRequest
{
    public function __construct(
        public string $placement,
        public ?int $userId = null,
        public ?string $sessionId = null,
        public int $limit = 1,
        public ?int $contextProductId = null,
        public ?int $contextCategoryId = null,
        public ?string $query = null,
        /** @var int[] */
        public array $cartProductIds = [],
        /** @var int[] products already on the page */
        public array $excludeProductIds = [],
        public bool $explain = false,
        /** longer-lived tokens (e-mail links) */
        public bool $forEmail = false,
        /** @var int[]|null restrict the auction to these products (e.g. deals in one subcategory) */
        public ?array $onlyProductIds = null,
    ) {}

    /** Viewer from an HTTP request: Bearer user (sanctum) and/or the storefront's X-Session-Id. */
    public static function fromHttp(Request $request, string $placement, array $overrides = []): self
    {
        [$userId, $sessionId] = InteractionTracker::actorFromRequest($request);
        $req = new self($placement, $userId, $sessionId);
        foreach ($overrides as $k => $v) {
            $req->{$k} = $v;
        }
        return $req;
    }

    /** Stable key for per-viewer caps: user, else guest session, else nobody (no caps). */
    public function actorKey(): ?string
    {
        return $this->userId ? "u{$this->userId}" : ($this->sessionId ? "s{$this->sessionId}" : null);
    }
}
