<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('core.branch.view');
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->can('core.branch.view');
    }

    public function create(User $user): bool
    {
        return $user->can('core.branch.create');
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->can('core.branch.update');
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $user->can('core.branch.delete');
    }
}
