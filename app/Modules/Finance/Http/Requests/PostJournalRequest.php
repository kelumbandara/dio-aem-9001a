<?php

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Shared by the API and the web form. Accounts are given by code. */
class PostJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('finance.journal.post');
    }

    /** Form rows left completely empty are not lines. */
    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');
        if (is_array($lines)) {
            $this->merge(['lines' => array_values(array_filter($lines, fn ($row) => is_array($row) && collect($row)->filter(fn ($v) => trim((string) $v) !== '')->isNotEmpty()))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $amount = ['nullable', 'string', 'regex:/^\d{1,14}(\.\d{1,4})?$/'];

        return [
            'entry_date' => ['nullable', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:2', 'max:500'],
            'lines.*.account' => ['required', 'string', 'max:12'],
            'lines.*.debit' => $amount,
            'lines.*.credit' => $amount,
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
