<?php

namespace App\Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Shared by the API and the web form. Suppliers, items, units, warehouses and taxes are given by code. */
class PurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('procurement.order.manage');
    }

    /** Form rows left completely empty are not lines. */
    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');
        if (is_array($lines)) {
            $this->merge(['lines' => array_values(array_filter($lines, fn ($row) => is_array($row) && collect($row)->except(['uom', 'tax_code', 'discount_pct'])->filter(fn ($v) => trim((string) $v) !== '')->isNotEmpty()))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $qty = 'regex:/^\d{1,12}(\.\d{1,6})?$/';

        return [
            'supplier' => ['required', 'string', 'max:20'],
            'warehouse' => ['required', 'string', 'max:20'],
            'order_date' => ['nullable', 'date_format:Y-m-d'], 'expected_date' => ['nullable', 'date_format:Y-m-d'],
            'supplier_ref' => ['nullable', 'string', 'max:100'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'version' => ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item' => ['required', 'string', 'max:40'],
            'lines.*.qty' => ['required', 'string', $qty],
            'lines.*.uom' => ['nullable', 'string', 'max:10'],
            'lines.*.unit_price' => ['nullable', 'string', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'lines.*.discount_pct' => ['nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'lines.*.tax_code' => ['nullable', 'string', 'max:20'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
