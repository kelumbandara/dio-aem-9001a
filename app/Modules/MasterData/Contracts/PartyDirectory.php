<?php

namespace App\Modules\MasterData\Contracts;

use App\Modules\MasterData\DTOs\PartyInfo;
use App\Support\Exceptions\BusinessException;

/** Read access to customers and suppliers for modules above MasterData. */
interface PartyDirectory
{
    public function customer(int $customerId): ?PartyInfo;

    public function supplier(int $supplierId): ?PartyInfo;

    /** @throws BusinessException masterdata.customer_not_found | masterdata.customer_inactive */
    public function requireActiveCustomer(int $customerId): PartyInfo;

    /** @throws BusinessException masterdata.supplier_not_found | masterdata.supplier_inactive */
    public function requireActiveSupplier(int $supplierId): PartyInfo;
}
