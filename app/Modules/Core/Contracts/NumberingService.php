<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Exceptions\NumberingException;
use Carbon\CarbonInterface;

interface NumberingService
{
    /**
     * Create the sequence if it does not exist yet (idempotent; never alters an existing one).
     *
     * Format tokens: {SEQ} {YYYY} {YY} {MM} {FY} {BR}.
     * Reset policy: never | yearly | fiscal_yearly | monthly (anything else is rejected).
     */
    public function define(
        string $key,
        string $format = '{SEQ}',
        int $padding = 5,
        string $resetPolicy = 'never',
        bool $perBranch = false,
    ): void;

    /**
     * Allocate the next document number.
     *
     * Allocation happens inside the caller's DB transaction under a row lock, so a rolled-back
     * document does not consume a number (gapless) and concurrent callers are serialised per
     * series/period/branch.
     *
     * @throws NumberingException when the sequence is unknown or inactive
     */
    public function next(string $key, ?int $branchId = null, ?CarbonInterface $date = null): string;
}
