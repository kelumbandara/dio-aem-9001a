<?php

namespace App\Modules\Approvals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // everyone may delegate their own approval rights
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'delegate_id' => ['required', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'action_key' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
