<?php

namespace App\Modules\MasterData\Contracts;

use App\Modules\MasterData\DTOs\TaxResult;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonInterface;

/**
 * Taxes from effective-dated rates. Each code is applied to the net amount independently and the tax
 * amounts are added. Compounded or cascaded statutory rules (one tax charged on top of another) are not
 * modelled here; they are validated with an accountant and arrive with Finance.
 */
interface TaxCalculator
{
    /**
     * Rate in percent ("18.0000") that applies on $on (default: today, Asia/Colombo).
     *
     * @throws BusinessException masterdata.tax_code_not_found | masterdata.no_tax_rate
     */
    public function rate(int $taxCodeId, ?CarbonInterface $on = null): string;

    /**
     * Tax on top of a net amount. Amounts are rounded half away from zero to $decimals places.
     *
     * @param  list<int>  $taxCodeIds
     *
     * @throws BusinessException
     */
    public function fromNet(string $net, array $taxCodeIds, ?CarbonInterface $on = null, int $decimals = 2): TaxResult;

    /**
     * Split a tax-inclusive amount into net and tax.
     *
     * @param  list<int>  $taxCodeIds
     *
     * @throws BusinessException
     */
    public function fromGross(string $gross, array $taxCodeIds, ?CarbonInterface $on = null, int $decimals = 2): TaxResult;
}
