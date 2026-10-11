<?php

namespace App\Modules\Sales\Services;

use App\Modules\MasterData\Contracts\TaxCalculator;
use App\Modules\MasterData\Contracts\TaxCatalog;
use App\Modules\Sales\Exceptions\SalesException;
use App\Support\Decimal;
use Carbon\CarbonInterface;

/**
 * Amounts and taxes of a sales document, all in LKR rounded to 2 places, half away from zero.
 *
 * - VAT is charged per line at the rate of the line's VAT code on the document date.
 * - Levies (SSCL, NBT) are charged on the document's *liable turnover*: the net value of all lines that are not
 *   exempt, for every levy code that is active and has a rate in force that day. Each levy is calculated on that
 *   base on its own (not on top of VAT or of the other levy).
 */
final class SalesCalculator
{
    public const LEVY_KINDS = ['sscl', 'nbt'];

    public function __construct(
        private readonly TaxCalculator $rates,
        private readonly TaxCatalog $catalog,
    ) {}

    public function lineNet(string $qty, string $unitPrice, string $discountPct): string
    {
        $gross = Decimal::mul($qty, $unitPrice);
        $factor = Decimal::sub('1', Decimal::div($discountPct, '100'));

        return Decimal::round(Decimal::mul($gross, $factor), 2);
    }

    /**
     * @param  list<array{net: string, tax_code_id: int|null}>  $lines
     * @return array{lines: list<array{vat_rate: string, vat_amount: string, levy_liable: bool}>, taxes: list<array{tax_code_id: int, kind: string, rate: string, base_amount: string, amount: string}>, net: string, vat: string, levy: string, gross: string}
     */
    public function compute(array $lines, ?CarbonInterface $on = null): array
    {
        $out = [];
        $vatRows = [];
        $net = '0';
        $vat = '0';
        $liable = '0';

        foreach ($lines as $i => $line) {
            $kind = $line['tax_code_id'] !== null ? $this->catalog->kind($line['tax_code_id']) : null;
            if ($line['tax_code_id'] !== null && ! in_array($kind, ['vat', 'exempt'], true)) {
                throw SalesException::make('sales.tax_code_invalid', 'A line can only use a VAT or an exempt tax code.', 422, "lines.{$i}.tax_code");
            }

            $rate = '0';
            $amount = '0';
            if ($kind === 'vat') {
                $rate = $this->rates->rate($line['tax_code_id'], $on);
                $amount = Decimal::round(Decimal::div(Decimal::mul($line['net'], $rate), '100'), 2);
                $vatRows[$line['tax_code_id']] ??= ['tax_code_id' => $line['tax_code_id'], 'kind' => 'vat', 'rate' => $rate, 'base_amount' => '0', 'amount' => '0'];
                $vatRows[$line['tax_code_id']]['base_amount'] = Decimal::add($vatRows[$line['tax_code_id']]['base_amount'], $line['net']);
                $vatRows[$line['tax_code_id']]['amount'] = Decimal::add($vatRows[$line['tax_code_id']]['amount'], $amount);
            }
            $isLiable = $kind !== 'exempt';

            $out[] = ['vat_rate' => Decimal::round($rate, 4), 'vat_amount' => Decimal::round($amount, 2), 'levy_liable' => $isLiable];
            $net = Decimal::add($net, $line['net']);
            $vat = Decimal::add($vat, $amount);
            if ($isLiable) {
                $liable = Decimal::add($liable, $line['net']);
            }
        }

        $taxes = array_values($vatRows);
        $levy = '0';
        foreach ($this->catalog->activeLevies($on) as $levyCode) {
            $amount = Decimal::round(Decimal::div(Decimal::mul($liable, $levyCode['rate']), '100'), 2);
            $taxes[] = ['tax_code_id' => $levyCode['tax_code_id'], 'kind' => $levyCode['kind'], 'rate' => $levyCode['rate'], 'base_amount' => Decimal::round($liable, 2), 'amount' => $amount];
            $levy = Decimal::add($levy, $amount);
        }

        $net = Decimal::round($net, 2);
        $vat = Decimal::round($vat, 2);
        $levy = Decimal::round($levy, 2);

        return [
            'lines' => $out,
            'taxes' => array_map(fn (array $t) => [
                'tax_code_id' => $t['tax_code_id'], 'kind' => $t['kind'], 'rate' => Decimal::round($t['rate'], 4),
                'base_amount' => Decimal::round($t['base_amount'], 2), 'amount' => Decimal::round($t['amount'], 2),
            ], $taxes),
            'net' => $net, 'vat' => $vat, 'levy' => $levy, 'gross' => Decimal::round(Decimal::add(Decimal::add($net, $vat), $levy), 2),
        ];
    }
}
