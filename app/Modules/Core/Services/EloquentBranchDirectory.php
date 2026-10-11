<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\BranchDirectory;
use App\Modules\Core\Models\Branch;

final class EloquentBranchDirectory implements BranchDirectory
{
    public function idByCode(string $code): ?int
    {
        $id = Branch::query()->where('is_active', true)->whereRaw('upper(code) = ?', [strtoupper(trim($code))])->value('id');

        return $id === null ? null : (int) $id;
    }

    public function labels(array $branchIds): array
    {
        return Branch::query()->withTrashed()->whereIn('id', $branchIds)->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Branch $b) => [$b->id => "{$b->code} — {$b->name}"])->all();
    }

    public function active(): array
    {
        return Branch::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Branch $b) => [$b->id => "{$b->code} — {$b->name}"])->all();
    }
}
