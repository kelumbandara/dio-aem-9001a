<?php

namespace App\Modules\MasterData\Http\Requests;

use App\Modules\MasterData\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shared by the API (POST/PATCH) and the web form. Related records are given by code. */
class SaveItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = $this->route('item');

        return $item instanceof Item ? $this->user()->can('update', $item) : $this->user()->can('create', Item::class);
    }

    /** Form rows left completely empty are not unit lines. */
    protected function prepareForValidation(): void
    {
        $uoms = $this->input('uoms');

        if (is_array($uoms)) {
            $this->merge(['uoms' => array_values(array_filter($uoms, fn ($row) => ! is_array($row) || ($row['uom'] ?? '') !== '' || ($row['factor'] ?? '') !== ''))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $create = ! $this->route('item');
        $required = $create ? 'required' : 'sometimes';

        return [
            'code' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._\\-\\/]+$/'],
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => [$required, 'string', 'max:20'],
            'item_type' => ['sometimes', Rule::in(Item::TYPES)],
            'base_uom' => [$required, 'string', 'max:10'],
            'is_sellable' => ['sometimes', 'boolean'],
            'is_purchasable' => ['sometimes', 'boolean'],
            'tracking' => ['sometimes', Rule::in(['none', 'lot'])],
            'shelf_life_days' => ['nullable', 'integer', 'between:1,36500'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'default_tax_code' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
            'uoms' => ['sometimes', 'array', 'max:30'],
            'uoms.*.uom' => ['required', 'string', 'max:10'],
            'uoms.*.factor' => ['required', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'uoms.*.for_purchase' => ['sometimes', 'boolean'],
            'uoms.*.for_sale' => ['sometimes', 'boolean'],
            'version' => $create ? ['prohibited'] : ['required', 'integer', 'min:1'],
        ];
    }
}
