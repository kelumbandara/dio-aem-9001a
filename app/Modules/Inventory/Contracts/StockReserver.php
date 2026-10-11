<?php

namespace App\Modules\Inventory\Contracts;

use App\Support\Exceptions\BusinessException;

/** Promise stock to a document (sales order, production order) so nobody else issues it. */
interface StockReserver
{
    /**
     * @return int reservation id
     *
     * @throws BusinessException inventory.insufficient_available when less than $qty (base units) is available
     */
    public function reserve(int $itemId, int $warehouseId, string $qty, string $sourceType, string $sourceId, ?int $by = null): int;

    /** Give back what is still reserved; idempotent. */
    public function release(int $reservationId): void;

    /** Release every active reservation of a source document; returns how many were released. */
    public function releaseBySource(string $sourceType, string $sourceId): int;
}
