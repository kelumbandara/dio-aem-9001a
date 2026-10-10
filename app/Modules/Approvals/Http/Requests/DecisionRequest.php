<?php

namespace App\Modules\Approvals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Comment for approve (optional) / reject (the engine insists on a reason). Authority is checked by the engine. */
class DecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['comment' => ['nullable', 'string', 'max:2000']];
    }
}
