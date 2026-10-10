<?php

namespace App\Modules\Core\Actions\Branches;

use App\Modules\Core\Models\Branch;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

final class UpdateBranch
{
    /**
     * Optimistic concurrency: the caller sends the version it read; a stale version is a 409.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Branch $branch, array $data, int $expectedVersion): Branch
    {
        return DB::transaction(function () use ($branch, $data, $expectedVersion): Branch {
            $locked = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->version !== $expectedVersion) {
                throw new BusinessException(
                    'concurrency.stale_version',
                    'The branch was changed by someone else. Reload and try again.',
                    409,
                    [['field' => 'version', 'message' => "Current version is {$locked->version}."]],
                );
            }

            $locked->fill($data);
            $locked->version = $locked->version + 1;
            $locked->save();

            return $locked->refresh();
        });
    }
}
