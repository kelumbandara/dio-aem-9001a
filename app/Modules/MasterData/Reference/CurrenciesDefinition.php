<?php

namespace App\Modules\MasterData\Reference;

use App\Modules\MasterData\Actions\Reference\SaveCurrency;
use App\Modules\MasterData\Models\Currency;
use Illuminate\Database\Eloquent\Model;

final class CurrenciesDefinition extends ReferenceDefinition
{
    public function __construct(private readonly SaveCurrency $save) {}

    public function key(): string
    {
        return 'currencies';
    }

    public function modelClass(): string
    {
        return Currency::class;
    }

    protected function hasPublicId(): bool
    {
        return false; // identified by its ISO code
    }

    public function columns(): array
    {
        return ['code' => 'text', 'name' => 'text', 'symbol' => 'text', 'decimals' => 'number', 'is_base' => 'bool', 'is_active' => 'bool'];
    }

    public function fields(): array
    {
        return [
            'code' => ['type' => 'text', 'required' => true, 'create_only' => true],
            'name' => ['type' => 'text', 'required' => true],
            'symbol' => ['type' => 'text'],
            'decimals' => ['type' => 'number', 'required' => true, 'min' => 0, 'max' => 4],
            'is_active' => ['type' => 'checkbox'],
        ];
    }

    public function rules(?Model $existing): array
    {
        return [
            'code' => $existing === null ? ['required', 'string', 'size:3', 'alpha'] : ['sometimes', 'string', 'size:3'],
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:8'],
            'decimals' => ['required', 'integer', 'between:0,4'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function save(array $data, ?Model $existing): Model
    {
        return ($this->save)($data, $this->narrow($existing, Currency::class));
    }

    public function present(Model $model): array
    {
        /** @var Currency $model */
        $latest = $model->rates()->first();

        return [
            'code' => $model->code, 'name' => $model->name, 'symbol' => $model->symbol, 'decimals' => $model->decimals,
            'is_base' => $model->is_base, 'is_active' => $model->is_active,
            'latest_rate' => $latest ? ['rate' => $latest->rate, 'effective_on' => $latest->effective_on->toDateString()] : null,
        ];
    }
}
