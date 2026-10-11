<?php

namespace App\Modules\Inventory\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;

/** Warehouses and their locations: `inventory.warehouse.view` to read, `.manage` to change. Never deleted. */
final class WarehousePolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $user->can('inventory.warehouse.view');
    }

    public function view(Authorizable $user, Model $warehouse): bool
    {
        return $user->can('inventory.warehouse.view');
    }

    public function create(Authorizable $user): bool
    {
        return $user->can('inventory.warehouse.manage');
    }

    public function update(Authorizable $user, Model $warehouse): bool
    {
        return $user->can('inventory.warehouse.manage');
    }
}
