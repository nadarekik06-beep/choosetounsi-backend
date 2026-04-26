<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Category;
use App\Services\UserPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * UserPreferenceController
 *
 * Handles the onboarding flow and user preference management.
 *
 * Routes (all auth:sanctum):
 *   GET  /api/preferences/onboarding-data  — data needed to render the form
 *   POST /api/preferences                  — save preferences + complete onboarding
 *   POST /api/preferences/skip             — skip onboarding without saving
 *   GET  /api/preferences                  — get current user's preferences
 *   PUT  /api/preferences                  — update preferences (post-onboarding)
 */
class UserPreferenceController extends Controller
{
    public function __construct(
        private UserPreferenceService $preferenceService
    ) {}

    /**
     * GET /api/preferences/onboarding-data
     *
     * Returns all data needed to render the onboarding form:
     *   - Active categories (for category multi-select)
     *   - Brand attribute options (for brand multi-select)
     *   - Gender options (static)
     *   - Price range suggestions (static)
     */
    public function onboardingData()
    {
        // Active categories
        $categories = Category::active()
            ->ordered()
            ->select('id', 'name', 'slug', 'icon')
            ->get();

        // Brand attribute options — find the 'brand' attribute and its options
        $brandAttribute = Attribute::where('slug', 'brand')
            ->with(['options' => fn($q) => $q->orderBy('order')->select('id', 'attribute_id', 'value')])
            ->first();

        $brands = $brandAttribute
            ? $brandAttribute->options->map(fn($o) => ['id' => $o->id, 'name' => $o->value])
            : collect();

        return response()->json([
            'success' => true,
            'data'    => [
                'categories' => $categories,
                'brands'     => $brands,
                'genders'    => [
                    ['value' => 'male',   'label' => 'Men'],
                    ['value' => 'female', 'label' => 'Women'],
                    ['value' => 'unisex', 'label' => 'Everyone'],
                ],
                'price_ranges' => [
                    ['label' => 'Under 50 DT',   'min' => 0,   'max' => 50],
                    ['label' => '50 – 100 DT',   'min' => 50,  'max' => 100],
                    ['label' => '100 – 200 DT',  'min' => 100, 'max' => 200],
                    ['label' => '200 – 500 DT',  'min' => 200, 'max' => 500],
                    ['label' => 'Over 500 DT',   'min' => 500, 'max' => null],
                ],
            ],
        ]);
    }

    /**
     * POST /api/preferences
     *
     * Save onboarding preferences.
     * All fields are optional — user may choose to fill only some.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'gender'       => 'nullable|in:male,female,unisex',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
            'brand_ids'    => 'nullable|array',
            'brand_ids.*'  => 'integer',
            'price_min'    => 'nullable|numeric|min:0',
            'price_max'    => 'nullable|numeric|min:0|gt:price_min',
        ]);

        $user  = $request->user();
        $prefs = $this->preferenceService->saveOnboardingPreferences($user->id, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Preferences saved successfully.',
            'data'    => [
                'preferences'          => $prefs,
                'onboarding_completed' => true,
            ],
        ]);
    }

    /**
     * POST /api/preferences/skip
     *
     * Mark onboarding as completed without saving preferences.
     */
    public function skip(Request $request)
    {
        $this->preferenceService->skipOnboarding($request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Onboarding skipped.',
            'data'    => ['onboarding_completed' => true],
        ]);
    }

    /**
     * GET /api/preferences
     *
     * Get current user's preferences (for settings page).
     */
    public function show(Request $request)
    {
        $prefs = $this->preferenceService->getOrCreate($request->user()->id);

        return response()->json([
            'success' => true,
            'data'    => $prefs,
        ]);
    }

    /**
     * PUT /api/preferences
     *
     * Update preferences after onboarding (from profile/settings).
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'gender'       => 'nullable|in:male,female,unisex',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
            'brand_ids'    => 'nullable|array',
            'brand_ids.*'  => 'integer',
            'price_min'    => 'nullable|numeric|min:0',
            'price_max'    => 'nullable|numeric|min:0',
        ]);

        $prefs = $this->preferenceService->updatePreferences($request->user()->id, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Preferences updated.',
            'data'    => $prefs,
        ]);
    }
}