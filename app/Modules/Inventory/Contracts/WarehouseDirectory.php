<?php

namespace App\Modules\Inventory\Contracts;

/** Read access to warehouses for modules above Inventory. */
interface WarehouseDirectory
{
    /** Id of the active warehouse with this code (case-insensitive), or null. */
    public function idByCode(string $code): ?int;

    /**
     * @param  list<int>  $ids
     * @return array<int, string> warehouse id => "CODE — name"
     */
    public function labels(array $ids): array;

    /** @return array<int, string> active warehouses, id => "CODE — name" */
    public function active(): array;

    public function isActive(int $id): bool;
}
