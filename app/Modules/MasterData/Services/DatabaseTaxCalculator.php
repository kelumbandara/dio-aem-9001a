<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Contracts\TaxCalculator;
use App\Modules\MasterData\DTOs\TaxResult;
use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\TaxCode;
use App\Modules\MasterData\Models\TaxRate;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class DatabaseTaxCalculator implements TaxCalculator
{
    public function rate(int $taxCodeId, ?CarbonInterface $on = null): string
    {
        $code = TaxCode::query()->find($taxCodeId) ?? throw MasterDataException::notFound('tax_code');
        $date = CarbonImmutable::instance($on ?? now())->setTimezone(config('core.display_timezone'))->toDateString();

        $rate = TaxRate::query()
            ->where('tax_code_id', $code->id)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->value('rate');

        return $rate ?? throw MasterDataException::make('masterdata.no_tax_rate', "Tax code {$code->code} has no rate for {$date}.");
    }

    public function fromNet(string $net, array $taxCodeIds, ?CarbonInterface $on = null, int $decimals = 2): TaxResult
    {
        $net = Decimal::round($net, $decimals);
        $lines = [];
        $total = Decimal::round('0', $decimals);

        foreach (array_values(array_unique($taxCodeIds)) as $id) {
            $rate = $this->rate($id, $on);
            $amount = Decimal::round(Decimal::div(Decimal::mul($net, $rate), '100'), $decimals);
            $lines[] = ['tax_code_id' => $id, 'rate' => $rate, 'amount' => $amount];
            $total = Decimal::add($total, $amount);
        }

        $total = Decimal::round($total, $decimals);

        return new TaxResult($net, $total, Decimal::round(Decimal::add($net, $total), $decimals), $lines);
    }

    public function fromGross(string $gross, array $taxCodeIds, ?CarbonInterface $on = null, int $decimals = 2): TaxResult
    {
        $gross = Decimal::round($gross, $decimals);
        $ids = array_values(array_unique($taxCodeIds));

        $rates = [];
        $sum = '0';
        foreach ($ids as $id) {
            $rates[$id] = $this->rate($id, $on);
            $sum = Decimal::add($sum, $rates[$id]);
        }

        // gross = net * (1 + sum(rate)/100)  ->  net = gross / (1 + sum/100)
        $net = Decimal::round(Decimal::div($gross, Decimal::add('1', Decimal::div($sum, '100'))), $decimals);

        $lines = [];
        $running = '0';
        foreach ($ids as $i => $id) {
            // the last line takes the remainder so net + tax always equals the gross exactly
            $amount = $i === array_key_last($ids)
                ? Decimal::sub(Decimal::sub($gross, $net), $running)
                : Decimal::round(Decimal::div(Decimal::mul($net, $rates[$id]), '100'), $decimals);
            $amount = Decimal::round($amount, $decimals);
            $lines[] = ['tax_code_id' => $id, 'rate' => $rates[$id], 'amount' => $amount];
            $running = Decimal::add($running, $amount);
        }

        return new TaxResult($net, Decimal::round(Decimal::sub($gross, $net), $decimals), $gross, $lines);
    }
}
