<?php

namespace App\Http\Requests\Admin\Subscriptions;

use Illuminate\Validation\Validator;

/** Platform default commission tiers (used when no plan rate / override applies). */
class CommissionSettingsRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'tiers'        => ['required', 'array', 'min:1', 'max:20'],
            'tiers.*.min'  => ['required', 'numeric', 'min:0'],
            'tiers.*.max'  => ['nullable', 'numeric'],
            'tiers.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'floor'        => ['required', 'numeric', 'min:0', 'max:100'],
            'reason'       => $this->reasonRule(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $tiers = collect($this->input('tiers', []))->sortBy('min')->values();
            if ($tiers->isEmpty()) return;

            if ((float) $tiers->first()['min'] > 0) {
                $v->errors()->add('tiers', 'The first tier must start at 0.');
            }
            if (($tiers->last()['max'] ?? null) !== null) {
                $v->errors()->add('tiers', 'The last tier must be open-ended (no maximum).');
            }
            foreach ($tiers as $i => $t) {
                $max = $t['max'] ?? null;
                if ($max !== null && (float) $max <= (float) $t['min']) {
                    $v->errors()->add('tiers', 'Each tier maximum must be greater than its minimum.');
                    break;
                }
                if ($i === 0) continue;
                $prevMax = $tiers[$i - 1]['max'] ?? null;
                if ($prevMax === null) {
                    $v->errors()->add('tiers', 'Only the last tier can be open-ended.');
                    break;
                }
                if ((float) $t['min'] <= (float) $prevMax) {
                    $v->errors()->add('tiers', 'Tiers must not overlap.');
                    break;
                }
            }
        });
    }
}
