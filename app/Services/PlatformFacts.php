<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\SellerSubscription;

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
}
