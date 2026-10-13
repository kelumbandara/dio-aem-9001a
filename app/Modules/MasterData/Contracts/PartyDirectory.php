<?php

namespace App\Modules\MasterData\Contracts;

use App\Modules\MasterData\DTOs\PartyInfo;
use App\Support\Exceptions\BusinessException;

/** Read access to customers and suppliers for modules above MasterData. */
interface PartyDirectory
{
    public function customer(int $customerId): ?PartyInfo;

    public function supplier(int $supplierId): ?PartyInfo;

    /**
     * Many customers / suppliers in one go (for lists: one lookup per row would make a page slower with every row it shows).
     *
     * @param  list<int>  $ids
     * @return array<int, PartyInfo> by id; ids that do not exist are left out
     */
    public function customers(array $ids): array;

    /**
     * @param  list<int>  $ids
     * @return array<int, PartyInfo>
     */
    public function suppliers(array $ids): array;

    /** By code (CUS-00001), case-insensitive; for screens and APIs that refer to parties by code. */
    public function customerByCode(string $code): ?PartyInfo;

    public function supplierByCode(string $code): ?PartyInfo;

    /**
     * Customers or suppliers whose code or name contains the term, for pickers.
     *
     * @return list<PartyInfo>
     */
    public function search(string $side, string $term, int $limit = 50): array;

    /**
     * The main e-mail address and phone number on file for a customer or supplier (as typed; null when there is none), so other
     * modules can contact them without reading the party tables.
     *
     * @param  'customer'|'supplier'  $side
     * @return array{email: string|null, phone: string|null}
     */
    public function contact(string $side, int $partyId): array;

    /**
     * The address lines for a document header: the default billing address, else the first address on file.
     *
     * @param  'customer'|'supplier'  $side
     * @return list<string>
     */
    public function billingAddress(string $side, int $partyId): array;

    /** @throws BusinessException masterdata.customer_not_found | masterdata.customer_inactive */
    public function requireActiveCustomer(int $customerId): PartyInfo;

    /** @throws BusinessException masterdata.supplier_not_found | masterdata.supplier_inactive */
    public function requireActiveSupplier(int $supplierId): PartyInfo;
}
