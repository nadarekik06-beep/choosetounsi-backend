<?php

namespace App\Http\Requests\Admin\Subscriptions;

class CommissionOverrideRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'rate'       => ['required', 'numeric', 'min:0', 'max:100'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
            'reason'     => $this->reasonRule(),
        ];
    }
}
