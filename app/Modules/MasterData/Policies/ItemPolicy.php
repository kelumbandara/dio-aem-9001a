<?php

namespace App\Modules\MasterData\Policies;

final class ItemPolicy extends PermissionPolicy
{
    protected function prefix(): string
    {
        return 'masterdata.item';
    }
}
