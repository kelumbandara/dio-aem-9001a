<?php

namespace App\Modules\MasterData\Reference;

use App\Modules\MasterData\Actions\Reference\SaveTaxCode;
use App\Modules\MasterData\Models\TaxCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

final class TaxCodesDefinition extends ReferenceDefinition
{
    public function __construct(private readonly SaveTaxCode $save) {}

    public function key(): string
    {
        return 'tax-codes';
    }

    public function modelClass(): string
    {
        return TaxCode::class;
    }

    public function columns(): array
    {
        return ['code' => 'text', 'name' => 'text', 'kind' => 'badge', 'current_rate' => 'text', 'is_active' => 'bool'];
    }

    public function fields(): array
    {
        return [
            'code' => ['type' => 'text', 'required' => true, 'create_only' => true],
            'name' => ['type' => 'text', 'required' => true],
            'kind' => ['type' => 'select', 'required' => true, 'options' => fn () => array_combine(TaxCode::KINDS, TaxCode::KINDS)],
            'notes' => ['type' => 'text'],
            'is_active' => ['type' => 'checkbox'],
        ];
    }

    public function rules(?Model $existing): array
    {
        return [
            ...$this->codeRule(20, $existing),
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(TaxCode::KINDS)],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function save(array $data, ?Model $existing): Model
    {
        return ($this->save)($data, $this->narrow($existing, TaxCode::class));
    }

    public function present(Model $model): array
    {
        /** @var TaxCode $model */
        $today = now(config('core.display_timezone'))->toDateString();
        $current = $model->rates->first(fn ($r) => $r->effective_from->toDateString() <= $today && ($r->effective_to === null || $r->effective_to->toDateString() >= $today));

        return [
            'id' => $model->public_id, 'code' => $model->code, 'name' => $model->name, 'kind' => $model->kind,
            'notes' => $model->notes, 'is_active' => $model->is_active,
            'current_rate' => $current?->rate,
            'rates' => $model->rates->map(fn ($r) => [
                'id' => $r->id, 'rate' => $r->rate, 'effective_from' => $r->effective_from->toDateString(), 'effective_to' => $r->effective_to?->toDateString(),
            ])->values()->all(),
        ];
    }

    /** @return Builder<TaxCode> */
    public function query(): Builder
    {
        return TaxCode::query()->with('rates')->orderBy('code');
    }
}
