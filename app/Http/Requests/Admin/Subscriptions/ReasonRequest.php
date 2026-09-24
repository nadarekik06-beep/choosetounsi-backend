<?php

namespace App\Http\Requests\Admin\Subscriptions;

/** suspend / reactivate / cancel / remove commission override */
class ReasonRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'reason'    => $this->reasonRule(),
            'immediate' => ['sometimes', 'boolean'],   // cancel only
        ];
    }
}
