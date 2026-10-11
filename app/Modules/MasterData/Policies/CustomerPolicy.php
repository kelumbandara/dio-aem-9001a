<?php

namespace App\Modules\MasterData\Policies;

final class CustomerPolicy extends PermissionPolicy
{
    protected function prefix(): string
    {
        return 'masterdata.customer';
    }
}
