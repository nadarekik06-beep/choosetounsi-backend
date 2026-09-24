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
        foreach (SellerSubscription::PLAN_PRICES as $key => $price) {
            $range = $this->commission->rateRangeForPlan($key);
            $plans[$key] = [
                'key'            => $key,
                'name'           => SellerSubscription::PLAN_NAMES[$key],
                'price'          => (float) $price,
                'max_products'   => SellerSubscription::PLAN_MAX_PRODUCTS[$key],
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
