<?php

namespace App\Http\Requests\Admin\Subscriptions;

class ChangeEndDateRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'end_date' => ['required', 'date', 'after_or_equal:today'],
            'reason'   => $this->reasonRule(),
        ];
    }
}
