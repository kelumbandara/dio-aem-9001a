<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\StockDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shared by the API and the web forms. Items, units, warehouses and locations are given by code. */
class PostStockDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('post', [StockDocument::class, (string) $this->input('type')]);
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
        $qty = 'regex:/^\d{1,12}(\.\d{1,6})?$/';

        return [
            'type' => ['required', Rule::in(StockDocument::TYPES)],
            'posting_date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:30'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item' => ['required', 'string', 'max:40'],
            'lines.*.qty' => ['required', 'string', $qty],
            'lines.*.uom' => ['nullable', 'string', 'max:10'],
            'lines.*.warehouse' => ['nullable', 'string', 'max:20'],
            'lines.*.location' => ['nullable', 'string', 'max:30'],
            'lines.*.to_warehouse' => ['nullable', 'string', 'max:20'],
            'lines.*.to_location' => ['nullable', 'string', 'max:30'],
            'lines.*.direction' => ['nullable', Rule::in(['in', 'out'])],
            'lines.*.lot' => ['nullable', 'string', 'max:60'],
            'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.unit_cost' => ['nullable', 'string', $qty],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
            'lines.*.reservation' => ['nullable', 'uuid'],
        ];
    }
}
