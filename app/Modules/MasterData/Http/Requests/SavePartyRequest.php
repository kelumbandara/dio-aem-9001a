<?php

namespace App\Modules\MasterData\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/** Common rules for customers and suppliers; subclasses name the model and route parameter. */
abstract class SavePartyRequest extends FormRequest
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    abstract protected function routeParameter(): string;

    public function authorize(): bool
    {
        $record = $this->route($this->routeParameter());

        return $record instanceof Model ? $this->user()->can('update', $record) : $this->user()->can('create', $this->model());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $create = ! $this->route($this->routeParameter());
        $required = $create ? 'required' : 'sometimes';

        return [
            'code' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._\\-\\/]+$/'],
            'name' => [$required, 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'kind' => ['sometimes', 'in:company,individual'],
            'tax_id' => ['nullable', 'string', 'max:40'],
            'business_reg_no' => ['nullable', 'string', 'max:40'],
            'nic' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_terms' => ['nullable', 'string', 'max:20'],
            'price_list' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            'contacts' => ['sometimes', 'array', 'max:50'],
            'contacts.*.id' => ['nullable', 'integer'],
            'contacts.*.name' => ['nullable', 'string', 'max:150'],
            'contacts.*.job_title' => ['nullable', 'string', 'max:100'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:30'],
            'contacts.*.is_primary' => ['sometimes', 'boolean'],
            'addresses' => ['sometimes', 'array', 'max:50'],
            'addresses.*.id' => ['nullable', 'integer'],
            'addresses.*.type' => ['sometimes', 'in:billing,shipping,other'],
            'addresses.*.line1' => ['nullable', 'string', 'max:255'],
            'addresses.*.line2' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['nullable', 'string', 'max:100'],
            'addresses.*.district' => ['nullable', 'string', 'max:100'],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:20'],
            'addresses.*.country' => ['sometimes', 'string', 'size:2'],
            'addresses.*.is_default' => ['sometimes', 'boolean'],
            'version' => $create ? ['prohibited'] : ['required', 'integer', 'min:1'],
            ...$this->extraRules(),
        ];
    }

    /** @return array<string, mixed> */
    protected function extraRules(): array
    {
        return [];
    }
}
