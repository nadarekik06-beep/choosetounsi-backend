<?php
// app/Services/UserPreferenceService.php

namespace App\Services;

use App\Models\User;
use App\Models\UserPreference;
use App\Services\Recommendation\InteractionTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserPreferenceService
{
    // Recency decay half-life: score halves every N days
    const ACTIVITY_HALFLIFE_DAYS = 14;

    public function getOrCreate(int $userId): UserPreference
    {
        return UserPreference::firstOrCreate(['user_id' => $userId]);
    }

    public function saveOnboardingPreferences(int $userId, array $data): UserPreference
    {
        $prefs = UserPreference::updateOrCreate(
            ['user_id' => $userId],
            [
                'gender'       => $data['gender']       ?? null,
                'category_ids' => $data['category_ids'] ?? null,
                'brand_ids'    => $data['brand_ids']    ?? null,
                'price_min'    => $data['price_min']    ?? null,
                'price_max'    => $data['price_max']    ?? null,
            ]
        );

        User::where('id', $userId)->update(['onboarding_completed' => true]);

        return $prefs;
    }

    public function updatePreferences(int $userId, array $data): UserPreference
    {
        $prefs   = $this->getOrCreate($userId);
        $allowed = ['gender', 'category_ids', 'brand_ids', 'price_min', 'price_max'];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $prefs->$field = $data[$field];
            }
        }

        $prefs->save();

        return $prefs;
    }

    public function skipOnboarding(int $userId): void
    {
        User::where('id', $userId)->update(['onboarding_completed' => true]);
    }

    /**
     * Legacy entry point — forwards to InteractionTracker (user_interactions).
     * Kept so existing callers and the old /api/recommendations keep working.
     */
    public function logActivity(
        int $userId,
        int $productId,
        ?int $categoryId,
        string $action,
        ?string $sessionId = null,
        ?int $orderId = null
    ): void {
        $event = self::LEGACY_ACTION_MAP[$action] ?? null;
        if (!$event) {
            Log::warning("[UserPreferenceService] Invalid action '{$action}'.");
            return;
        }

        app(InteractionTracker::class)->record($event, $userId, $sessionId, $productId, array_filter([
            'category_id' => $categoryId,
            'order_id'    => $orderId,
        ]));
    }

    private const LEGACY_ACTION_MAP = [
        'view'     => 'view',
        'cart'     => 'cart_add',
        'favorite' => 'favorite_add',
        'order'    => 'purchase',
        'purchase' => 'purchase',
    ];

    /**
     * Infer dynamic preferences from activity WITH recency decay.
     * (Legacy — the homepage feed uses InterestProfileService instead.)
     */
    public function inferPreferencesFromActivity(int $userId, int $days = 60): array
    {
        $actionWeights = [
            'purchase'     => 10,
            'cart_add'     => 3,
            'favorite_add' => 2,
            'click'        => 1,
            'view'         => 1,
        ];

        $halflife = self::ACTIVITY_HALFLIFE_DAYS;

        $logs = DB::table('user_interactions')
            ->where('user_id', $userId)
            ->whereNotNull('product_id')
            ->whereIn('event_type', array_keys($actionWeights))
            ->where('created_at', '>=', now()->subDays($days))
            ->select('product_id', 'category_id', 'event_type as action', 'created_at')
            ->get();

        if ($logs->isEmpty()) {
            return [
                'top_category_ids'    => [],
                'top_product_ids'     => [],
                'interaction_weights' => [],
            ];
        }

        $now            = now()->timestamp;
        $categoryScores = [];
        $productScores  = [];

        foreach ($logs as $log) {
            $actionWeight  = $actionWeights[$log->action] ?? 1;
            $daysAgo       = ($now - strtotime($log->created_at)) / 86400;
            $decayFactor   = pow(2, -$daysAgo / $halflife);
            $decayedWeight = $actionWeight * $decayFactor;

            if ($log->category_id) {
                $categoryScores[$log->category_id] =
                    ($categoryScores[$log->category_id] ?? 0.0) + $decayedWeight;
            }
            if ($log->product_id) {
                $productScores[$log->product_id] =
                    ($productScores[$log->product_id] ?? 0.0) + $decayedWeight;
            }
        }

        arsort($categoryScores);
        arsort($productScores);

        return [
            'top_category_ids'    => array_map('intval', array_keys(array_slice($categoryScores, 0, 5, true))),
            'top_product_ids'     => array_map('intval', array_keys(array_slice($productScores, 0, 20, true))),
            'interaction_weights' => $productScores,
        ];
    }

    public function getActivityWeights(int $userId, int $days = 60): array
    {
        return $this->inferPreferencesFromActivity($userId, $days)['interaction_weights'];
    }

    public function getCombinedPreferences(int $userId): ?UserPreference
    {
        $prefs    = UserPreference::where('user_id', $userId)->first();
        $inferred = $this->inferPreferencesFromActivity($userId);

        if (!$prefs) {
            if (empty($inferred['top_category_ids'])) {
                return null;
            }

            $prefs = new UserPreference([
                'user_id'      => $userId,
                'category_ids' => $inferred['top_category_ids'],
                'brand_ids'    => [],
                'gender'       => null,
                'price_min'    => null,
                'price_max'    => null,
            ]);

            return $prefs;
        }

        $mergedCategories = array_unique(array_merge(
            array_map('intval', (array) ($prefs->category_ids ?? [])),
            $inferred['top_category_ids']
        ));

        $prefs->category_ids = array_values($mergedCategories);

        return $prefs;
    }
}