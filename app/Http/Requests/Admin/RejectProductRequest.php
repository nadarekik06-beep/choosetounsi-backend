<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductModerationLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/admin/products/{id}/reject
 *
 * At least one predefined reason is required; `note` is required when the
 * only reason is "other". The legacy `reason` string is still accepted and
 * treated as reasons=['other'] + note=reason.
 */
class RejectProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    protected function prepareForValidation(): void
    {
        if (!$this->filled('reasons') && $this->filled('reason')) {
            $this->merge([
                'reasons' => ['other'],
                'note'    => $this->input('note') ?: $this->input('reason'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'reasons'   => ['required', 'array', 'min:1'],
            'reasons.*' => ['string', 'distinct', Rule::in(array_keys(ProductModerationLog::REASONS))],
            'note'      => [
                'nullable', 'string', 'max:1000',
                Rule::requiredIf(fn() => (array) $this->input('reasons') === ['other']),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'reasons.required' => 'Select at least one rejection reason.',
            'note.required'    => 'Please describe the reason when selecting "Other".',
        ];
    }
}
