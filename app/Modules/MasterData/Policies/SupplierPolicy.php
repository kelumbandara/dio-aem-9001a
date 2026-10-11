<?php

namespace App\Modules\MasterData\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;

final class SupplierPolicy extends PermissionPolicy
{
    protected function prefix(): string
    {
        return 'masterdata.supplier';
    }

    /** Seeing full bank account numbers is separate from seeing the supplier. */
    public function viewBankDetails(Authorizable $user): bool
    {
        return $user->can('masterdata.supplier.bank.view');
    }

    public function manageBankAccounts(Authorizable $user): bool
    {
        return $user->can('masterdata.supplier.bank.manage');
    }
}
