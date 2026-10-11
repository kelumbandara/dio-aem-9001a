<?php

namespace App\Modules\MasterData\Database\Seeders;

use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\ItemCategory;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\TaxCode;
use App\Modules\MasterData\Models\TaxRate;
use App\Modules\MasterData\Models\UnitOfMeasure;
use Illuminate\Database\Seeder;

/**
 * Starter reference data for a new installation. Idempotent and non-destructive: existing rows are
 * left exactly as they are, so it is safe to run on every deployment.
 *
 * Tax rates are legal facts that change; these are starting points to be confirmed by your accountant:
 *  - VAT 18% is the standard rate in force since 1 January 2024.
 *  - NBT and SSCL are created INACTIVE with no rate: whether they apply, and at what rate, depends on the
 *    date and on the business, so they must be set up deliberately (Master data > Tax codes).
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['PCS', 'Pieces', 0], ['SET', 'Set', 0], ['BOX', 'Box', 0], ['PKT', 'Packet', 0], ['DOZ', 'Dozen', 0],
            ['KG', 'Kilogram', 3], ['G', 'Gram', 2], ['L', 'Litre', 3], ['ML', 'Millilitre', 0],
            ['M', 'Metre', 3], ['CM', 'Centimetre', 1], ['SQM', 'Square metre', 3], ['HR', 'Hour', 2],
        ] as [$code, $name, $decimals]) {
            UnitOfMeasure::query()->firstOrCreate(['code' => $code], ['name' => $name, 'decimals' => $decimals, 'is_active' => true]);
        }

        ItemCategory::query()->firstOrCreate(['code' => 'GENERAL'], ['name' => 'General', 'is_active' => true]);

        // LKR is the base currency of every installation (CLAUDE.md); the others need exchange rates before use.
        Currency::query()->firstOrCreate(['code' => 'LKR'], ['name' => 'Sri Lankan Rupee', 'symbol' => 'Rs', 'decimals' => 2, 'is_base' => true, 'is_active' => true]);
        foreach ([['USD', 'US Dollar', '$'], ['EUR', 'Euro', '€'], ['GBP', 'Pound Sterling', '£'], ['INR', 'Indian Rupee', '₹']] as [$code, $name, $symbol]) {
            Currency::query()->firstOrCreate(['code' => $code], ['name' => $name, 'symbol' => $symbol, 'decimals' => 2, 'is_base' => false, 'is_active' => true]);
        }

        foreach ([
            ['CASH', 'Cash on delivery', 0], ['NET7', 'Net 7 days', 7], ['NET14', 'Net 14 days', 14], ['NET30', 'Net 30 days', 30],
            ['NET45', 'Net 45 days', 45], ['NET60', 'Net 60 days', 60],
        ] as [$code, $name, $days]) {
            PaymentTerm::query()->firstOrCreate(['code' => $code], ['name' => $name, 'days' => $days, 'is_active' => true]);
        }

        $vat = TaxCode::query()->firstOrCreate(['code' => 'VAT'], ['name' => 'Value Added Tax', 'kind' => 'vat', 'is_active' => true]);
        if (! TaxRate::query()->where('tax_code_id', $vat->id)->exists()) {
            TaxRate::query()->create(['tax_code_id' => $vat->id, 'rate' => '18.0000', 'effective_from' => '2024-01-01']);
        }

        $exempt = TaxCode::query()->firstOrCreate(['code' => 'EXEMPT'], ['name' => 'Exempt / zero-rated', 'kind' => 'exempt', 'is_active' => true]);
        if (! TaxRate::query()->where('tax_code_id', $exempt->id)->exists()) {
            TaxRate::query()->create(['tax_code_id' => $exempt->id, 'rate' => '0.0000', 'effective_from' => '2000-01-01']);
        }

        TaxCode::query()->firstOrCreate(['code' => 'NBT'], ['name' => 'Nation Building Tax', 'kind' => 'nbt', 'is_active' => false, 'notes' => 'Inactive: confirm applicability and rate with your accountant, then add a rate and activate.']);
        TaxCode::query()->firstOrCreate(['code' => 'SSCL'], ['name' => 'Social Security Contribution Levy', 'kind' => 'sscl', 'is_active' => false, 'notes' => 'Inactive: confirm applicability and rate with your accountant, then add a rate and activate.']);
    }
}
