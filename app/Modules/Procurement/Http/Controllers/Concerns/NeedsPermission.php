<?php

namespace App\Modules\Procurement\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait NeedsPermission
{
    protected function need(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403);
    }

    /**
     * Case-insensitive "contains" filter on the document number, used by every list.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    protected function searchNo($query, string $term, string $column = 'document_no')
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($term)).'%';

        return $query->whereRaw("lower({$column}) like ?", [$like]);
    }
}
