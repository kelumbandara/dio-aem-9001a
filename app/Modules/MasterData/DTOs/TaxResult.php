<?php

namespace App\Modules\MasterData\DTOs;

final readonly class TaxResult
{
    /**
     * @param  string  $net  amount before tax, rounded to the base currency's decimals
     * @param  string  $tax  total tax
     * @param  string  $gross  net + tax
     * @param  list<array{tax_code_id: int, rate: string, amount: string}>  $lines  one entry per tax code
     */
    public function __construct(
        public string $net,
        public string $tax,
        public string $gross,
        public array $lines,
    ) {}
}
