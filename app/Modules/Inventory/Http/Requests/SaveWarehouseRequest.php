<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;

/** Shared by the API and the web form. The branch (site) is given by its code. */
class SaveWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $warehouse = $this->route('warehouse');

        return $warehouse instanceof Warehouse ? $this->user()->can('update', $warehouse) : $this->user()->can('create', Warehouse::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $create = ! $this->route('warehouse');
        $required = $create ? 'required' : 'sometimes';

        return [
            'code' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9._\\-]+$/'],
            'name' => [$required, 'string', 'max:150'],
            'branch' => ['nullable', 'string', 'max:20'],
            'uses_locations' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'version' => $create ? ['prohibited'] : ['required', 'integer', 'min:1'],
        ];
    }
}
