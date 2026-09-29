<?php

namespace App\Http\Requests\Ads;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdCampaignRequest extends FormRequest
{
    use CampaignRules;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = $this->campaignRules();
        $rules['product_id']      = ['required', 'integer'];
        $rules['daily_budget'][]  = 'required';
        return $rules;
    }
}
