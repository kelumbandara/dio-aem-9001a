<?php

namespace App\Modules\Core\Actions\Branches;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\Employee;
use App\Modules\Core\Models\User;
use App\Support\Exceptions\BusinessException;

final class DeleteBranch
{
    public function __invoke(Branch $branch): void
    {
        $inUse = User::query()->where('branch_id', $branch->id)->exists()
            || Employee::query()->where('branch_id', $branch->id)->exists()
            || Department::query()->where('branch_id', $branch->id)->exists();

        if ($inUse) {
            throw new BusinessException('core.branch_in_use', 'This branch has users, employees or departments and cannot be deleted. Deactivate it instead.', 422);
        }

        $branch->delete();
    }
}
