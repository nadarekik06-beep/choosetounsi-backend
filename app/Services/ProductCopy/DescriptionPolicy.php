<?php

namespace App\Services\ProductCopy;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Who may use which part of the AI description generator, and how often.
 *
 * Every seller gets the generator; the plan decides the level (free | red | black)
 * and config('ai_descriptions.levels.<level>') decides tones, languages, variants,
 * extras and the daily limit. All checks run here, on the backend — the dashboard
 * only mirrors them to show locks.
 */
class DescriptionPolicy
{
    public function __construct(private PlanGate $gate) {}

    /**
     * @return array{level: string, plan: SubscriptionPlan, caps: array}|JsonResponse
     *         a ready 403 when the user is not an active seller
     */
    public function resolve(User $user): array|JsonResponse
    {
        [$plan, $sub, $app] = $this->gate->context($user->id);

        if (!$app) {
            return $this->deny(__('seller.gate.not_seller'), 'NOT_SELLER');
        }
        if ($sub && $sub->isSuspended()) {
            return $this->deny(__('seller.subscription.suspended'), 'SUBSCRIPTION_SUSPENDED');
        }

        $level = $this->levelFor($plan);
        return ['level' => $level, 'plan' => $plan, 'caps' => config("ai_descriptions.levels.$level")];
    }

    public function levelFor(SubscriptionPlan $plan): string
    {
        if (!$plan->hasFeature('ai_tools')) return 'free';
        return $plan->tierKey() === 'black' ? 'black' : 'red';
    }

    /** The one language a free seller may generate in: the dashboard language. */
    public function ownLanguage(): string
    {
        $locale = app()->getLocale();
        return in_array($locale, config('ai_descriptions.languages'), true) ? $locale : 'fr';
    }

    /** Lowest level that unlocks a capability check, for upgrade prompts. */
    public function requiredLevel(callable $allows): ?string
    {
        foreach (['free', 'red', 'black'] as $level) {
            if ($allows(config("ai_descriptions.levels.$level"))) return $level;
        }
        return null;
    }

    /** Offered plan names per level, for "Upgrade to …" prompts. */
    public function upgradePlans(): array
    {
        $out = ['red' => null, 'black' => null];
        foreach (SubscriptionPlan::offered()->ordered()->get() as $plan) {
            $level = $this->levelFor($plan);
            if ($level !== 'free' && !$out[$level]) {
                $out[$level] = ['slug' => $plan->slug, 'name' => $plan->name];
            }
        }
        return $out;
    }

    // ── Daily usage ───────────────────────────────────────────────────────

    /** @return array{used: int, limit: ?int, remaining: ?int, resets_at: string} */
    public function usage(int $sellerId, array $caps): array
    {
        $used  = (int) Cache::get($this->usageKey($sellerId), 0);
        $limit = $caps['daily_limit'] ?? null;
        $limit = $limit === null || $limit <= 0 ? null : (int) $limit;

        return [
            'used'      => $used,
            'limit'     => $limit,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'resets_at' => now(config('ai_descriptions.timezone'))->addDay()->startOfDay()->toIso8601String(),
        ];
    }

    public function hasQuota(int $sellerId, array $caps): bool
    {
        $usage = $this->usage($sellerId, $caps);
        return $usage['remaining'] === null || $usage['remaining'] > 0;
    }

    /** Count one successful generation (failed calls are never counted). */
    public function consume(int $sellerId): void
    {
        $key = $this->usageKey($sellerId);
        Cache::add($key, 0, now()->addHours(48));
        Cache::increment($key);
    }

    private function usageKey(int $sellerId): string
    {
        return 'ai_desc:usage:' . $sellerId . ':' . now(config('ai_descriptions.timezone'))->format('Ymd');
    }

    public function deny(string $message, string $code, array $extra = [], int $status = 403): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => $code] + $extra, $status);
    }
}
