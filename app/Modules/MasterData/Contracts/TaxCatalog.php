<?php

namespace App\Modules\MasterData\Contracts;

use Carbon\CarbonInterface;

/** What kind of tax a tax code is, and which levies are in force; documents use this to post each tax to its own account. */
interface TaxCatalog
{
    /** vat|sscl|nbt|stamp|withholding|exempt|other, or null for an unknown id. */
    public function kind(int $taxCodeId): ?string;

    /**
     * Active levy codes (SSCL, NBT) that have a rate on the date, with that rate in percent.
     *
     * @return list<array{tax_code_id: int, code: string, kind: string, rate: string}>
     */
    public function activeLevies(?CarbonInterface $on = null): array;

    /** @return array{id: int, code: string, name: string, kind: string}|null */
    public function find(int $taxCodeId): ?array;

    /**
     * Active codes of the given kinds, for pickers.
     *
     * @param  list<string>  $kinds
     * @return list<array{id: int, code: string, name: string, kind: string}>
     */
    public function codes(array $kinds = ['vat', 'exempt']): array;
}
