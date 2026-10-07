<?php

namespace App\Http\Requests\Admin\Subscriptions;

use App\Models\PlanDisplayFeature;
use Illuminate\Validation\Rule;

/** Create (POST) or update (PUT) one pricing-page feature of a plan. */
class DisplayFeatureRequest extends AdminRequest
{
    /** Shared with PlanRequest, which accepts the whole list at once. */
    public static function itemRules(string $prefix = '', bool $partial = false): array
    {
        $label = $partial ? 'sometimes' : 'required';
        return [
            "{$prefix}id"          => ['sometimes', 'nullable', 'integer'],
            "{$prefix}label"       => [$label, 'string', 'max:80'],
            "{$prefix}description" => ['nullable', 'string', 'max:160'],
            "{$prefix}icon"        => ['nullable', 'string', Rule::in(PlanDisplayFeature::ICONS)],
            "{$prefix}included"    => ['sometimes', 'boolean'],
            "{$prefix}highlight"   => ['sometimes', 'boolean'],
        ];
    }

    public function rules(): array
    {
        return self::itemRules('', $this->isMethod('put') || $this->isMethod('patch')) + [
            'reason' => $this->reasonRule(false),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->label)) $this->merge(['label' => trim($this->label)]);
    }
}
