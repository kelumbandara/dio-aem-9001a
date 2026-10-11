<?php

namespace App\Modules\MasterData\Reference;

use App\Modules\MasterData\Actions\Reference\SaveItemCategory;
use App\Modules\MasterData\Models\ItemCategory;
use App\Modules\MasterData\Support\CodeLookup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class CategoriesDefinition extends ReferenceDefinition
{
    public function __construct(private readonly SaveItemCategory $save, private readonly CodeLookup $codes) {}

    public function key(): string
    {
        return 'item-categories';
    }

    public function modelClass(): string
    {
        return ItemCategory::class;
    }

    public function columns(): array
    {
        return ['code' => 'text', 'name' => 'text', 'parent' => 'text', 'is_active' => 'bool'];
    }

    public function fields(): array
    {
        return [
            'code' => ['type' => 'text', 'required' => true, 'create_only' => true],
            'name' => ['type' => 'text', 'required' => true],
            'parent' => ['type' => 'select', 'options' => fn () => ItemCategory::query()->where('is_active', true)->orderBy('code')->pluck('name', 'code')->all(), 'blank' => true],
            'is_active' => ['type' => 'checkbox'],
        ];
    }

    public function rules(?Model $existing): array
    {
        return [
            ...$this->codeRule(20, $existing),
            'name' => ['required', 'string', 'max:150'],
            'parent' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function save(array $data, ?Model $existing): Model
    {
        $existing = $this->narrow($existing, ItemCategory::class);
        $data['parent_id'] = array_key_exists('parent', $data)
            ? $this->codes->category($data['parent'], 'parent')
            : $existing?->parent_id;
        unset($data['parent']);

        return ($this->save)($data, $existing);
    }

    public function present(Model $model): array
    {
        /** @var ItemCategory $model */
        return [
            'id' => $model->public_id, 'code' => $model->code, 'name' => $model->name,
            'parent' => $model->parent_id ? $model->parent?->code : null, 'is_active' => $model->is_active,
        ];
    }

    /** @return Builder<ItemCategory> */
    public function query(): Builder
    {
        return ItemCategory::query()->with('parent')->orderBy('code');
    }
}
