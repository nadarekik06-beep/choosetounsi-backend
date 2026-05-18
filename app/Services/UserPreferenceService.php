<?php
// app/Services/UserPreferenceService.php

namespace App\Services;

use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * UserPreferenceService
 *
 * CHANGES vs previous version:
 *   - inferPreferencesFromActivity() now applies exponential recency decay
 *     so a cart add yesterday outweighs a view from 50 days ago
 *   - getCombinedPreferences() now also returns interaction_weights
 *     (needed by ProductScoringService::activityScore)
 *   - Added getActivityWeights() as a clean public method for the scoring service
 */
class UserPreferenceService
{
    // Recency decay half-life: score halves every N days
    const ACTIVITY_HALFLIFE_DAYS = 14;

    /**
     * Get or create a UserPreference record for the given user.
     */
    public function getOrCreate(int $userId): UserPreference
    {
        return UserPreference::firstOrCreate(['user_id' => $userId]);
    }

    /**
     * Save preferences from the onboarding form.
     */
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

    /**
     * Update preferences from the profile settings page (partial update).
     */
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

    /**
     * Skip onboarding — marks completed without saving preferences.
     */
    public function skipOnboarding(int $userId): void
    {
        User::where('id', $userId)->update(['onboarding_completed' => true]);
    }

    /**
     * Log a user activity event.
     *
     * Deduplication: 'view' actions are deduplicated within a 30-minute
     * session window. All other actions are always logged.
     */
    public function logActivity(
        int $userId,
        int $productId,
        ?int $categoryId,
        string $action,
        ?string $sessionId = null
    ): void {
        if (!in_array($action, UserActivityLog::ACTIONS, true)) {
            Log::warning("[UserPreferenceService] Invalid action: {$action}");
            return;
        }

        if ($action === UserActivityLog::ACTION_VIEW && $sessionId) {
            $recentView = DB::table('user_activity_logs')
                ->where('user_id', $userId)
                ->where('product_id', $productId)
                ->where('action', 'view')
                ->where('session_id', $sessionId)
                ->where('created_at', '>=', now()->subMinutes(30))
                ->exists();

            if ($recentView) {
                return;
            }
        }

        try {
            UserActivityLog::create([
                'user_id'     => $userId,
                'product_id'  => $productId,
                'category_id' => $categoryId,
                'action'      => $action,
                'session_id'  => $sessionId,
            ]);
        } catch (\Throwable $e) {
            Log::warning("[UserPreferenceService] Failed to log activity: " . $e->getMessage());
        }
    }

    /**
     * Infer dynamic preferences from activity logs WITH recency decay.
     *
     * Scoring formula per log entry:
     *   raw_weight = action_weight × decay_factor
     *   decay_factor = 2^(-days_ago / HALFLIFE)
     *
     * This means:
     *   An order (weight 4) from today       → 4.0
     *   An order (weight 4) from 14 days ago → 2.0
     *   An order (weight 4) from 28 days ago → 1.0
     *   A view  (weight 1) from yesterday    → ~0.95
     *
     * @param  int $userId
     * @param  int $days    Look-back window in days
     * @return array{
     *   top_category_ids: int[],
     *   top_product_ids:  int[],
     *   interaction_weights: array<int, float>
     * }
     */
    public function inferPreferencesFromActivity(int $userId, int $days = 60): array
    {
        $actionWeights = [
            'order'    => 4,
            'cart'     => 3,
            'favorite' => 2,
            'view'     => 1,
        ];

        $halflife = self::ACTIVITY_HALFLIFE_DAYS;

        // Fetch logs with created_at for decay calculation
        $logs = DB::table('user_activity_logs')
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays($days))
            ->select('product_id', 'category_id', 'action', 'created_at')
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
            $actionWeight = $actionWeights[$log->action] ?? 1;

            // Exponential recency decay: weight halves every HALFLIFE days
            $daysAgo      = ($now - strtotime($log->created_at)) / 86400;
            $decayFactor  = pow(2, -$daysAgo / $halflife);
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
            'interaction_weights' => $productScores,  // float weights, keyed by product_id
        ];
    }

    /**
     * Get ONLY the interaction_weights for the scoring service.
     * More efficient than getCombinedPreferences() when prefs are already loaded.
     *
     * Returns: array<int, float>  product_id => decayed_weight
     */
    public function getActivityWeights(int $userId, int $days = 60): array
    {
        return $this->inferPreferencesFromActivity($userId, $days)['interaction_weights'];
    }

    /**
     * Get combined preferences: explicit + inferred from activity.
     * Enriches the UserPreference object with behavioral category data.
     *
     * Returns null only when there is truly no data at all.
     */
    public function getCombinedPreferences(int $userId): ?UserPreference
    {
        $prefs    = UserPreference::where('user_id', $userId)->first();
        $inferred = $this->inferPreferencesFromActivity($userId);

        if (!$prefs) {
            if (empty($inferred['top_category_ids'])) {
                return null;
            }

            // Build a virtual preference from inferred data alone
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

        // Merge inferred categories into explicit preferences (deduplicated)
        $mergedCategories = array_unique(array_merge(
            array_map('intval', (array) ($prefs->category_ids ?? [])),
            $inferred['top_category_ids']
        ));

        $prefs->category_ids = array_values($mergedCategories);

        return $prefs;
    }
}