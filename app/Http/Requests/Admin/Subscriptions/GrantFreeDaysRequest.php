<?php

namespace App\Http\Requests\Admin\Subscriptions;

class GrantFreeDaysRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'days'   => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => $this->reasonRule(),
        ];
    }
}
