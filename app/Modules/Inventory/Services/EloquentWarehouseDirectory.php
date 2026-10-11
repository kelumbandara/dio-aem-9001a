<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\Inventory\Models\Warehouse;

final class EloquentWarehouseDirectory implements WarehouseDirectory
{
    public function idByCode(string $code): ?int
    {
        return Warehouse::query()->where('is_active', true)->whereRaw('lower(code) = ?', [mb_strtolower(trim($code))])->value('id');
    }

    public function labels(array $ids): array
    {
        return Warehouse::query()->whereIn('id', $ids)->get(['id', 'code', 'name'])->mapWithKeys(fn (Warehouse $w) => [$w->id => "{$w->code} — {$w->name}"])->all();
    }

    public function active(): array
    {
        return Warehouse::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->mapWithKeys(fn (Warehouse $w) => [$w->id => "{$w->code} — {$w->name}"])->all();
    }

    public function isActive(int $id): bool
    {
        return Warehouse::query()->whereKey($id)->where('is_active', true)->exists();
    }
}
