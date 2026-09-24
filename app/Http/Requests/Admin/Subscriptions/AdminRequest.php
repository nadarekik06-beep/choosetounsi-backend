<?php

namespace App\Http\Requests\Admin\Subscriptions;

use Illuminate\Foundation\Http\FormRequest;

/** Base for admin subscription requests: admin-only, shared reason rule. */
abstract class AdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    protected function reasonRule(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'min:5', 'max:500'];
    }

    public function messages(): array
    {
        return ['reason.required' => 'A reason is required for this action.'];
    }
}
