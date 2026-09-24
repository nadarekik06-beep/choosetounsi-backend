<?php

namespace App\Http\Requests\Admin\Subscriptions;

use Illuminate\Validation\Rule;

class StartTrialRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'plan'   => ['required', 'string', Rule::exists('subscription_plans', 'slug')->whereNull('archived_at')],
            'days'   => ['required', 'integer', 'min:1', 'max:90'],
            'reason' => $this->reasonRule(),
        ];
    }
}
