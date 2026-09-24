<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductModerationLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/admin/products/{id}/request-changes
 *
 * Notes to the seller are required — they must know what to fix.
 */
class RequestProductChangesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'reasons'   => ['nullable', 'array'],
            'reasons.*' => ['string', 'distinct', Rule::in(array_keys(ProductModerationLog::REASONS))],
            'note'      => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => 'Tell the seller what needs to change.',
        ];
    }
}
