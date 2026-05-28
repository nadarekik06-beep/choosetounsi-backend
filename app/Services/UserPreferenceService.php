<?php
// app/Services/UserPreferenceService.php

namespace App\Services;

use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserPreference;
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
     * Log a user activity event.
     *
     * FIX 1: Added 'purchase' to valid actions check via ACTIONS constant (now includes it).
     * FIX 2: Session ID is now passed as nullable — no crash if API route has no session.
     * FIX 3: try/catch now logs the FULL exception message so silent failures are visible.
     */
    public function logActivity(
        int $userId,
        int $productId,
        ?int $categoryId,
        string $action,
        ?string $sessionId = null
    ): void {
        // Validate action against the model constant
        if (!in_array($action, UserActivityLog::ACTIONS, true)) {
            Log::warning("[UserPreferenceService] Invalid action '{$action}' — not in ACTIONS constant.");
            return;
        }

        // Deduplicate view actions within the same session window (30 min)
        if ($action === UserActivityLog::ACTION_VIEW && $sessionId) {
            $recentView = DB::table('user_activity_logs')
                ->where('user_id', $userId)
                ->where('product_id', $productId)
                ->where('action', 'view')
                ->where('session_id', $sessionId)
                ->where('created_at', '>=', now()->subMinutes(30))
                ->exists();

            if ($recentView) {
                return; // Already logged this view in this session
            }
        }

        try {
            UserActivityLog::create([
                'user_id'     => $userId,
                'product_id'  => $productId,
                'category_id' => $categoryId,
                'action'      => $action,
                'session_id'  => $sessionId, // nullable — safe for API routes
            ]);
        } catch (\Throwable $e) {
            // FIX 3: Log full details so we can actually debug failures
            Log::warning("[UserPreferenceService] Failed to log activity: " . $e->getMessage(), [
                'user_id'    => $userId,
                'product_id' => $productId,
                'action'     => $action,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Infer dynamic preferences from activity logs WITH recency decay.
     * FIX: Added 'purchase' weight (highest = 10).
     */
    public function inferPreferencesFromActivity(int $userId, int $days = 60): array
    {
        $actionWeights = [
            'purchase' => 10, // ← ADDED: highest signal
            'order'    => 4,
            'cart'     => 3,
            'favorite' => 2,
            'view'     => 1,
        ];

        $halflife = self::ACTIVITY_HALFLIFE_DAYS;

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