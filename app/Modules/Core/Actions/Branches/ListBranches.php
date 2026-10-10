<?php

namespace App\Modules\Core\Actions\Branches;

use App\Modules\Core\Models\Branch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListBranches
{
    private const SORTABLE = ['code', 'name', 'created_at'];

    /**
     * @param  array{search?: string, is_active?: bool}  $filter
     * @return LengthAwarePaginator<int, Branch>
     */
    public function __invoke(array $filter, string $sort, int $perPage): LengthAwarePaginator
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        if (! in_array($column, self::SORTABLE, true)) {
            $column = 'code';
            $direction = 'asc';
        }

        return Branch::query()
            ->when(isset($filter['is_active']), fn ($q) => $q->where('is_active', $filter['is_active']))
            ->when(! empty($filter['search']), function ($q) use ($filter) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($filter['search'])).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(code) like ?', [$term])->orWhereRaw('lower(name) like ?', [$term]));
            })
            ->orderBy($column, $direction)
            ->orderBy('id')
            ->paginate(max(1, min($perPage, 100)))
            ->withQueryString();
    }
}
