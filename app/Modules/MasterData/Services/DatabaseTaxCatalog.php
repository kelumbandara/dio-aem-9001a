<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Contracts\TaxCalculator;
use App\Modules\MasterData\Contracts\TaxCatalog;
use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\TaxCode;
use Carbon\CarbonInterface;

final class DatabaseTaxCatalog implements TaxCatalog
{
    public function __construct(private readonly TaxCalculator $rates) {}

    public function kind(int $taxCodeId): ?string
    {
        return TaxCode::query()->whereKey($taxCodeId)->value('kind');
    }

    public function activeLevies(?CarbonInterface $on = null): array
    {
        $levies = [];
        foreach (TaxCode::query()->where('is_active', true)->whereIn('kind', ['sscl', 'nbt'])->orderBy('code')->get() as $code) {
            try {
                $rate = $this->rates->rate($code->id, $on);
            } catch (MasterDataException) {
                continue; // a levy with no rate in force yet is simply not charged
            }
            $levies[] = ['tax_code_id' => $code->id, 'code' => $code->code, 'kind' => $code->kind, 'rate' => $rate];
        }

        return $levies;
    }

    public function find(int $taxCodeId): ?array
    {
        $c = TaxCode::query()->find($taxCodeId);

        return $c === null ? null : ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'kind' => $c->kind];
    }

    public function codes(array $kinds = ['vat', 'exempt']): array
    {
        return TaxCode::query()->where('is_active', true)->whereIn('kind', $kinds)->orderBy('code')->get()
            ->map(fn (TaxCode $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'kind' => $c->kind])->all();
    }
}
