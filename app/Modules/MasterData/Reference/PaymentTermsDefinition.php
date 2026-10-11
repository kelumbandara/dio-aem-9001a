<?php

namespace App\Modules\MasterData\Reference;

use App\Modules\MasterData\Actions\Reference\SavePaymentTerm;
use App\Modules\MasterData\Models\PaymentTerm;
use Illuminate\Database\Eloquent\Model;

final class PaymentTermsDefinition extends ReferenceDefinition
{
    public function __construct(private readonly SavePaymentTerm $save) {}

    public function key(): string
    {
        return 'payment-terms';
    }

    public function modelClass(): string
    {
        return PaymentTerm::class;
    }

    public function columns(): array
    {
        return ['code' => 'text', 'name' => 'text', 'days' => 'number', 'is_active' => 'bool'];
    }

    public function fields(): array
    {
        return [
            'code' => ['type' => 'text', 'required' => true, 'create_only' => true],
            'name' => ['type' => 'text', 'required' => true],
            'days' => ['type' => 'number', 'required' => true, 'min' => 0, 'max' => 3650],
            'is_active' => ['type' => 'checkbox'],
        ];
    }

    public function rules(?Model $existing): array
    {
        return [
            ...$this->codeRule(20, $existing),
            'name' => ['required', 'string', 'max:100'],
            'days' => ['required', 'integer', 'between:0,3650'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function save(array $data, ?Model $existing): Model
    {
        return ($this->save)($data, $this->narrow($existing, PaymentTerm::class));
    }

    public function present(Model $model): array
    {
        /** @var PaymentTerm $model */
        return ['id' => $model->public_id, 'code' => $model->code, 'name' => $model->name, 'days' => $model->days, 'is_active' => $model->is_active];
    }
}
