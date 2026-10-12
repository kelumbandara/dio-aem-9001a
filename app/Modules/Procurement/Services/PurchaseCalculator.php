<?php

namespace App\Modules\Procurement\Services;

use App\Modules\MasterData\Contracts\TaxCalculator;
use App\Modules\MasterData\Contracts\TaxCatalog;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Support\Decimal;
use Carbon\CarbonInterface;

/**
 * Amounts of a purchasing document, LKR, rounded to 2 places half away from zero. Purchase prices are net of VAT;
 * input VAT is worked out per line from the line's VAT code (an exempt line has none).
 */
final class PurchaseCalculator
{
    public function __construct(
        private readonly TaxCalculator $rates,
        private readonly TaxCatalog $catalog,
    ) {}

    public function lineNet(string $qty, string $unitPrice, string $discountPct = '0'): string
    {
        $factor = Decimal::sub('1', Decimal::div($discountPct, '100'));

        return Decimal::round(Decimal::mul(Decimal::mul($qty, $unitPrice), $factor), 2);
    }

    /**
     * Net value of a line and, when the typed price includes VAT, the line total including VAT (null otherwise). With
     * VAT-inclusive prices the typed total is what the supplier charges for the line; net is that total divided by
     * (1 + rate), rounded to 2 places, and the VAT is whatever is left of the total.
     *
     * @return array{net: string, gross: string|null}
     */
    public function lineAmounts(string $qty, string $unitPrice, string $discountPct, ?int $taxCodeId, bool $inclusive, ?CarbonInterface $on = null): array
    {
        $typed = $this->lineNet($qty, $unitPrice, $discountPct);
        if (! $inclusive || $taxCodeId === null || $this->catalog->kind($taxCodeId) !== 'vat') {
            return ['net' => $typed, 'gross' => null];
        }
        $rate = $this->rates->rate($taxCodeId, $on);

        return ['net' => Decimal::round(Decimal::div($typed, Decimal::add('1', Decimal::div($rate, '100'))), 2), 'gross' => $typed];
    }

    /** @return array{rate: string, amount: string} */
    public function vat(?int $taxCodeId, string $net, ?CarbonInterface $on = null): array
    {
        if ($taxCodeId === null) {
            return ['rate' => '0.0000', 'amount' => '0.00'];
        }
        $kind = $this->catalog->kind($taxCodeId);
        if (! in_array($kind, ['vat', 'exempt'], true)) {
            throw ProcurementException::make('procurement.tax_code_invalid', 'A line can only use a VAT or an exempt tax code.', 422, 'tax_code');
        }
        if ($kind === 'exempt') {
            return ['rate' => '0.0000', 'amount' => '0.00'];
        }
        $rate = $this->rates->rate($taxCodeId, $on);

        return ['rate' => Decimal::round($rate, 4), 'amount' => Decimal::round(Decimal::div(Decimal::mul($net, $rate), '100'), 2)];
    }
}
