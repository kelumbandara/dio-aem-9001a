<?php

namespace App\Modules\MasterData\Policies;

/** Units, categories, currencies, tax codes and payment terms. */
final class ReferencePolicy extends PermissionPolicy
{
    protected function prefix(): string
    {
        return 'masterdata.reference';
    }
}
