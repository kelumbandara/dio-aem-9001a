<?php

namespace App\Modules\MasterData\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;

/**
 * Permission-name driven policy: `{prefix}.view` for reading, `{prefix}.manage` for writing.
 * Master records are never deleted, so there is no delete ability.
 */
abstract class PermissionPolicy
{
    abstract protected function prefix(): string;

    public function viewAny(Authorizable $user): bool
    {
        return $user->can($this->prefix().'.view');
    }

    public function view(Authorizable $user, Model $record): bool
    {
        return $user->can($this->prefix().'.view');
    }

    public function create(Authorizable $user): bool
    {
        return $user->can($this->prefix().'.manage');
    }

    public function update(Authorizable $user, Model $record): bool
    {
        return $user->can($this->prefix().'.manage');
    }
}
