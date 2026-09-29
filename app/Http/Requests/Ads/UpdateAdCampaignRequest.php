<?php

namespace App\Http\Requests\Ads;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdCampaignRequest extends FormRequest
{
    use CampaignRules;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return array_map(fn ($r) => array_merge(['sometimes'], $r), $this->campaignRules());
    }
}
