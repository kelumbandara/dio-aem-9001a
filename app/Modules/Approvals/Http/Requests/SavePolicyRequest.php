<?php

namespace App\Modules\Approvals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePolicyRequest extends FormRequest
{
    private const AMOUNT = ['nullable', 'regex:/^\d{1,14}(\.\d{1,4})?$/'];

    public function authorize(): bool
    {
        return $this->user()->can('approvals.policy.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'action_key' => ['required', 'string', 'max:100'],
            'risk_levels' => ['nullable', 'array'],
            'risk_levels.*' => [Rule::in(['R1', 'R2', 'R3'])],
            'min_amount' => self::AMOUNT,
            'max_amount' => self::AMOUNT,
            'priority' => ['required', 'integer', 'between:1,9999'],
            'expiry_hours' => ['required', 'integer', 'between:1,2160'],
            'reminder_after_hours' => ['required', 'integer', 'between:0,720'],
            'allow_self_approval' => ['sometimes', 'boolean'],
            'distinct_approvers' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:10'],
            'steps.*.order' => ['required', 'integer', 'between:1,20'],
            'steps.*.type' => ['required', Rule::in(['role', 'user'])],
            'steps.*.role' => ['nullable', 'string', 'max:100'],
            'steps.*.user_id' => ['nullable', 'integer'],
            'steps.*.label' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, mixed> validated data with checkbox defaults filled in */
    public function payload(): array
    {
        $data = $this->validated();
        $data['allow_self_approval'] = (bool) ($data['allow_self_approval'] ?? false);
        $data['distinct_approvers'] = (bool) ($data['distinct_approvers'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['risk_levels'] = ($data['risk_levels'] ?? []) === [] ? null : array_values($data['risk_levels']);
        $data['min_amount'] = ($data['min_amount'] ?? '') === '' ? null : $data['min_amount'];
        $data['max_amount'] = ($data['max_amount'] ?? '') === '' ? null : $data['max_amount'];

        return $data;
    }
}
