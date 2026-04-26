<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * UserPreferenceService
 *
 * Handles:
 *   1. Reading and writing user preferences (onboarding data)
 *   2. Logging user activity (view, favorite, cart, order)
 *   3. Extracting dynamic preferences from activity logs
 */
class UserPreferenceService
{
    /**
     * Get or create a UserPreference record for the given user.
     */
    public function getOrCreate(int $userId): UserPreference
    {
        return UserPreference::firstOrCreate(['user_id' => $userId]);
    }

    /**
     * Save preferences from the onboarding form.
     * Also marks onboarding_completed = true on the user.
     *
     * @param  int   $userId
     * @param  array $data   Validated input from OnboardingController
     * @return UserPreference
     */
    public function saveOnboardingPreferences(int $userId, array $data): UserPreference
    {
        $prefs = UserPreference::updateOrCreate(
            ['user_id' => $userId],
            [
                'gender'       => $data['gender']      ?? null,
                'category_ids' => $data['category_ids'] ?? null,
                'brand_ids'    => $data['brand_ids']    ?? null,
                'price_min'    => $data['price_min']    ?? null,
                'price_max'    => $data['price_max']    ?? null,
            ]
        );

        // Mark user as having completed onboarding
        User::where('id', $userId)->update(['onboarding_completed' => true]);

        return $prefs;
    }

    /**
     * Update preferences from the profile settings page (partial update).
     */
    public function updatePreferences(int $userId, array $data): UserPreference
    {
        $prefs = $this->getOrCreate($userId);

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
     * User can always set preferences later from their profile.
     */
    public function skipOnboarding(int $userId): void
    {
        User::where('id', $userId)->update(['onboarding_completed' => true]);
    }

    /**
     * Log a user activity event.
     *
     * Deduplication rule for 'view' actions:
     *   If the user already logged a 'view' for this product in the last 30 minutes
     *   with the same session, skip the insert to avoid rapid-fire duplicates.
     *   All other actions (favorite, cart, order) are always logged.
     *
     * @param  int         $userId
     * @param  int         $productId
     * @param  int|null    $categoryId   Denormalized from product
     * @param  string      $action       One of: view, favorite, cart, order
     * @param  string|null $sessionId    Laravel session ID
     */
    public function logActivity(
        int $userId,
        int $productId,
        ?int $categoryId,
        string $action,
        ?string $sessionId = null
    ): void {
        // Validate action
        if (!in_array($action, UserActivityLog::ACTIONS, true)) {
            Log::warning("[UserPreferenceService] Invalid action: {$action}");
            return;
        }

        // Deduplication for 'view' — prevent rapid re-log within 30 minutes
        if ($action === UserActivityLog::ACTION_VIEW && $sessionId) {
            $recentView = DB::table('user_activity_logs')
                ->where('user_id', $userId)
                ->where('product_id', $productId)
                ->where('action', 'view')
                ->where('session_id', $sessionId)
                ->where('created_at', '>=', now()->subMinutes(30))
                ->exists();

            if ($recentView) {
                return; // Already logged recently for this session
            }
        }

        try {
            UserActivityLog::create([
                'user_id'    => $userId,
                'product_id' => $productId,
                'category_id'=> $categoryId,
                'action'     => $action,
                'session_id' => $sessionId,
            ]);
        } catch (\Throwable $e) {
            // Never throw — activity logging must not break the main request
            Log::warning("[UserPreferenceService] Failed to log activity: " . $e->getMessage());
        }
    }

    /**
     * Infer dynamic preferences from a user's activity logs.
     *
     * Used by the recommendation engine when the user has no explicit preferences,
     * or to supplement explicit preferences with behavioral data.
     *
     * Returns the top N most-interacted categories and product IDs.
     *
     * Scoring weights:
     *   order    = 4 points
     *   cart     = 3 points
     *   favorite = 2 points
     *   view     = 1 point
     *
     * @param  int $userId
     * @param  int $days   Look-back window in days
     * @return array{
     *   top_category_ids: int[],
     *   top_product_ids:  int[],
     *   interaction_weights: array
     * }
     */
    public function inferPreferencesFromActivity(int $userId, int $days = 60): array
    {
        $weights = [
            'order'    => 4,
            'cart'     => 3,
            'favorite' => 2,
            'view'     => 1,
        ];

        $logs = DB::table('user_activity_logs')
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays($days))
            ->select('product_id', 'category_id', 'action')
            ->get();

        if ($logs->isEmpty()) {
            return [
                'top_category_ids'    => [],
                'top_product_ids'     => [],
                'interaction_weights' => [],
            ];
        }

        // Weight categories
        $categoryScores = [];
        $productScores  = [];

        foreach ($logs as $log) {
            $w = $weights[$log->action] ?? 1;

            if ($log->category_id) {
                $categoryScores[$log->category_id] = ($categoryScores[$log->category_id] ?? 0) + $w;
            }
            if ($log->product_id) {
                $productScores[$log->product_id] = ($productScores[$log->product_id] ?? 0) + $w;
            }
        }

        arsort($categoryScores);
        arsort($productScores);

        return [
            'top_category_ids'    => array_keys(array_slice($categoryScores, 0, 5, true)),
            'top_product_ids'     => array_keys(array_slice($productScores, 0, 20, true)),
            'interaction_weights' => $productScores,
        ];
    }

    /**
     * Get combined preferences: explicit + inferred from activity.
     * Returns a UserPreference-like object enriched with behavioral data.
     *
     * The scoring service uses this to compute UserInterestScore.
     */
    public function getCombinedPreferences(int $userId): ?UserPreference
    {
        $prefs    = UserPreference::where('user_id', $userId)->first();
        $inferred = $this->inferPreferencesFromActivity($userId);

        if (!$prefs) {
            if (empty($inferred['top_category_ids'])) {
                return null; // No data at all
            }

            // Build a virtual preference from inferred data
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

        // Merge inferred categories with explicit preferences (deduplicated)
        $mergedCategories = array_unique(array_merge(
            (array) ($prefs->category_ids ?? []),
            $inferred['top_category_ids']
        ));

        $prefs->category_ids = array_values(array_map('intval', $mergedCategories));

        return $prefs;
    }
}