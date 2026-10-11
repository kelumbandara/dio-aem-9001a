<?php

namespace App\Modules\Inventory\Contracts;

use App\Modules\Inventory\DTOs\PostedMovement;
use App\Modules\Inventory\DTOs\StockMovement;
use App\Support\Exceptions\BusinessException;

/**
 * The only way stock changes. Every screen, API call, import and other module (Sales, Procurement, Manufacturing)
 * goes through this; nothing else writes the ledger or the balances.
 */
interface StockPoster
{
    /**
     * Post the movement atomically: document, ledger rows, balances and average cost, or nothing at all.
     *
     * @throws BusinessException inventory.* (insufficient stock, inactive warehouse, lot rules…) or masterdata.*
     */
    public function post(StockMovement $movement): PostedMovement;

    /**
     * Run exactly the checks and arithmetic of post() and then undo everything: what would happen, saved nowhere.
     *
     * @throws BusinessException same as post()
     */
    public function check(StockMovement $movement): PostedMovement;

    /**
     * Undo a posted document with an opposite document (the original stays, marked reversed).
     *
     * @throws BusinessException inventory.document_not_found | inventory.already_reversed | inventory.reversal_blocked | inventory.insufficient_stock
     */
    public function reverse(int $documentId, ?string $note = null, ?int $by = null): PostedMovement;
}
