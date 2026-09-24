<?php

namespace App\Http\Requests\Admin\Subscriptions;

use Illuminate\Validation\Rule;

class AssignPlanRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'plan'           => ['required', 'string', Rule::exists('subscription_plans', 'slug')->whereNull('archived_at')],
            'billing_period' => ['nullable', Rule::in(['monthly', 'yearly'])],
            'end_date'       => ['nullable', 'date', 'after_or_equal:today'],
            'reason'         => $this->reasonRule(),
        ];
    }
}
