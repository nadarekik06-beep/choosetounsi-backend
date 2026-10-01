<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\Order;
use App\Models\Review;
use App\Models\SellerApplication;
use App\Models\SellerSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Live platform facts read from the code that enforces them.
 *
 * Anything shown to users about plans, commissions, complaint windows or
 * payment details must come from here, never be hardcoded in copy — so the
 * become-a-vendor page, the checkout page and the chatbot can't drift from
 * what the backend actually charges and allows.
 */
class PlatformFacts
{
    public function __construct(private CommissionService $commission) {}

    /**
     * @return array<string, array{key: string, name: string, price: float, max_products: ?int, commission_min: float, commission_max: float}>
     */
    public function plans(): array
    {
        $plans = [];
        foreach (\App\Models\SubscriptionPlan::offered()->ordered()->get() as $plan) {
            $range = $this->commission->rateRangeForPlan($plan->slug);
            $plans[$plan->slug] = [
                'key'            => $plan->slug,
                'name'           => $plan->name,
                'description'    => $plan->description,
                'badge_color'    => $plan->badge_color,
                'tier'           => $plan->tier,
                'price'          => (float) $plan->price_monthly,
                'price_yearly'   => $plan->price_yearly,
                'trial_days'     => $plan->trial_days,
                'max_products'   => $plan->max_products,
                'features'       => $plan->features,
                'commission_min' => $range['min'],
                'commission_max' => $range['max'],
            ];
        }
        return $plans;
    }

    /** @see CommissionService::rateTable() */
    public function commissionTable(): array
    {
        return $this->commission->rateTable();
    }

    public function complaintWindowHours(): int
    {
        return (int) Complaint::COMPLAINT_WINDOW_HOURS;
    }

    /** Null until the real number is set in .env (D17_ACCOUNT_NUMBER). */
    public function d17AccountNumber(): ?string
    {
        $number = trim((string) config('services.d17.account_number'));
        return $number === '' ? null : $number;
    }

    /**
     * Real numbers for the become-a-vendor page, never marketing guesses.
     * Cached briefly because the endpoint is public.
     *
     * @return array{sellers: int, orders_delivered: int, wilayas_served: int, average_rating: ?float, reviews: int}
     */
    public function sellerLandingStats(): array
    {
        return Cache::remember('platform:seller-landing-stats', now()->addMinutes(30), function () {
            $delivered = fn () => Order::whereIn('status', ['delivered', 'completed']);
            $avg       = Review::approved()->avg('rating');

            return [
                'sellers'          => User::approvedSellers()->where('is_active', true)->count(),
                'orders_delivered' => $delivered()->count(),
                'wilayas_served'   => $delivered()->whereNotNull('wilaya')->distinct()->count('wilaya'),
                'average_rating'   => $avg !== null ? round((float) $avg, 1) : null,
                'reviews'          => Review::approved()->count(),
            ];
        });
    }

    /**
     * Approved shops to showcase, in their own words. Same public fields that
     * GET /api/sellers/{id} already exposes (name, wilaya, avatar, description).
     *
     * @return list<array{id: int, business_name: string, wilaya: ?string, category: ?string, avatar: ?string, quote: ?string, plan: string}>
     */
    public function sellerShowcase(int $limit = 12): array
    {
        return Cache::remember("platform:seller-showcase:{$limit}", now()->addMinutes(30), function () use ($limit) {
            $sellerIds = User::approvedSellers()->where('is_active', true)->pluck('id');

            return SellerApplication::approved()
                ->whereIn('user_id', $sellerIds)
                ->latest('reviewed_at')
                ->get()
                ->unique('user_id')
                ->take($limit)
                ->map(fn (SellerApplication $a) => [
                    'id'            => $a->user_id,
                    'business_name' => $a->business_name,
                    // wilaya only: `city` is free text and often holds a street address
                    'wilaya'        => $a->wilaya,
                    'category'      => $a->business_categories[0] ?? $a->business_category,
                    'avatar'        => $a->profile_picture ? Storage::url($a->profile_picture) : null,
                    'quote'         => self::firstSentence($a->business_description),
                    'plan'          => $a->plan ?? 'free',
                ])
                ->values()
                ->all();
        });
    }

    private static function firstSentence(?string $text): ?string
    {
        $text = trim((string) $text);
        if ($text === '') return null;
        $first = preg_split('/(?<=[.!?])\s+/u', $text, 2)[0];
        return Str::limit($first, 110);
    }
}
