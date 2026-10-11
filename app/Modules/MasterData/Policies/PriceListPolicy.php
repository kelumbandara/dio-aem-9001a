<?php

namespace App\Modules\MasterData\Policies;

final class PriceListPolicy extends PermissionPolicy
{
    protected function prefix(): string
    {
        return 'masterdata.price_list';
    }
}
