<?php

namespace App\Modules\MasterData\Contracts;

use App\Modules\MasterData\DTOs\PartyInfo;
use App\Support\Exceptions\BusinessException;

/** Read access to customers and suppliers for modules above MasterData. */
interface PartyDirectory
{
    public function customer(int $customerId): ?PartyInfo;

    public function supplier(int $supplierId): ?PartyInfo;

    /** By code (CUS-00001), case-insensitive; for screens and APIs that refer to parties by code. */
    public function customerByCode(string $code): ?PartyInfo;

    public function supplierByCode(string $code): ?PartyInfo;

    /**
     * Customers or suppliers whose code or name contains the term, for pickers.
     *
     * @return list<PartyInfo>
     */
    public function search(string $side, string $term, int $limit = 50): array;

    /** @throws BusinessException masterdata.customer_not_found | masterdata.customer_inactive */
    public function requireActiveCustomer(int $customerId): PartyInfo;

    /** @throws BusinessException masterdata.supplier_not_found | masterdata.supplier_inactive */
    public function requireActiveSupplier(int $supplierId): PartyInfo;
}
