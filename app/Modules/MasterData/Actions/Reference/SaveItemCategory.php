<?php

namespace App\Modules\MasterData\Actions\Reference;

use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\ItemCategory;
use Illuminate\Support\Facades\DB;

final class SaveItemCategory
{
    /** @param  array{code?: string, name: string, parent_id?: ?int, is_active?: bool}  $data */
    public function __invoke(array $data, ?ItemCategory $category = null): ItemCategory
    {
        return DB::transaction(function () use ($data, $category): ItemCategory {
            $code = strtoupper(trim((string) ($data['code'] ?? $category->code ?? '')));
            if ($code === '') {
                throw MasterDataException::make('masterdata.code_required', 'A code is required.', 422, 'code');
            }

            $parentId = $data['parent_id'] ?? null;

            if ($category !== null) {
                $category = ItemCategory::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
                if ($code !== $category->code) {
                    throw MasterDataException::make('masterdata.code_locked', 'The code cannot be changed after creation.', 422, 'code');
                }
                if ($parentId !== null && $this->wouldCreateCycle($category->id, (int) $parentId)) {
                    throw MasterDataException::make('masterdata.category_cycle', 'A category cannot sit under itself or one of its own sub-categories.', 422, 'parent_id');
                }
                if (($data['is_active'] ?? true) === false
                    && (Item::query()->where('category_id', $category->id)->where('is_active', true)->exists()
                        || ItemCategory::query()->where('parent_id', $category->id)->where('is_active', true)->exists())) {
                    throw MasterDataException::make('masterdata.in_use', 'Active items or sub-categories still use this category.', 422, 'is_active');
                }
            } elseif (ItemCategory::query()->where('code', $code)->exists()) {
                throw MasterDataException::make('masterdata.code_taken', 'This code is already used.', 422, 'code');
            }

            if ($parentId !== null && ! ItemCategory::query()->whereKey($parentId)->where('is_active', true)->exists()) {
                throw MasterDataException::make('masterdata.parent_invalid', 'The parent category does not exist or is inactive.', 422, 'parent_id');
            }

            $category ??= new ItemCategory;
            $category->fill([
                'code' => $code,
                'name' => trim($data['name']),
                'parent_id' => $parentId,
                'is_active' => $data['is_active'] ?? true,
            ])->save();

            return $category;
        });
    }

    private function wouldCreateCycle(int $categoryId, int $newParentId): bool
    {
        for ($cursor = $newParentId, $hops = 0; $cursor !== null && $hops < 100; $hops++) {
            if ($cursor === $categoryId) {
                return true;
            }
            $cursor = ItemCategory::query()->whereKey($cursor)->value('parent_id');
        }

        return false;
    }
}
