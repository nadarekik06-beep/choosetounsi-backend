<?php

namespace App\Http\Requests\Ads;

use App\Models\Sponsorship;
use Illuminate\Validation\Rule;

/**
 * Shape validation shared by campaign create / update. Business rules (floors,
 * wallet, readiness, one campaign per product) live in SponsorshipService.
 */
trait CampaignRules
{
    protected function campaignRules(): array
    {
        return [
            'daily_budget'          => ['numeric', 'min:0', 'max:100000'],
            'max_cpc'               => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'total_budget'          => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'end_date'              => ['nullable', 'date_format:Y-m-d'],
            'goal'                  => ['nullable', Rule::in(['sales', 'visibility'])],
            'placements'            => ['nullable', 'array'],
            'placements.*'          => ['string', Rule::in(Sponsorship::PLACEMENTS)],
            'target_gender'         => ['nullable', Rule::in(['male', 'female', 'unisex'])],
            'target_wilaya_ids'     => ['nullable', 'array', 'max:24'],
            'target_wilaya_ids.*'   => ['string', 'max:100'],
            'target_category_ids'   => ['nullable', 'array', 'max:50'],
            'target_category_ids.*' => ['integer', 'exists:categories,id'],
            'target_price_min'      => ['nullable', 'numeric', 'min:0'],
            'target_price_max'      => ['nullable', 'numeric', 'min:0'],   // ≥ min checked in SponsorshipService
        ];
    }
}
