<?php

namespace App\Modules\MasterData\Reference;

use App\Modules\MasterData\Actions\Reference\SaveUnitOfMeasure;
use App\Modules\MasterData\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Model;

final class UnitsDefinition extends ReferenceDefinition
{
    public function __construct(private readonly SaveUnitOfMeasure $save) {}

    public function key(): string
    {
        return 'units-of-measure';
    }

    public function modelClass(): string
    {
        return UnitOfMeasure::class;
    }

    public function columns(): array
    {
        return ['code' => 'text', 'name' => 'text', 'decimals' => 'number', 'is_active' => 'bool'];
    }

    public function fields(): array
    {
        return [
            'code' => ['type' => 'text', 'required' => true, 'create_only' => true],
            'name' => ['type' => 'text', 'required' => true],
            'decimals' => ['type' => 'number', 'required' => true, 'min' => 0, 'max' => 6],
            'is_active' => ['type' => 'checkbox'],
        ];
    }

    public function rules(?Model $existing): array
    {
        return [
            ...$this->codeRule(10, $existing),
            'name' => ['required', 'string', 'max:100'],
            'decimals' => ['required', 'integer', 'between:0,6'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function save(array $data, ?Model $existing): Model
    {
        return ($this->save)($data, $this->narrow($existing, UnitOfMeasure::class));
    }

    public function present(Model $model): array
    {
        /** @var UnitOfMeasure $model */
        return ['id' => $model->public_id, 'code' => $model->code, 'name' => $model->name, 'decimals' => $model->decimals, 'is_active' => $model->is_active];
    }
}
