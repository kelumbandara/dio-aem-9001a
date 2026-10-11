<?php

namespace App\Modules\Sales\Services;

use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\Contracts\PriceResolver;
use App\Modules\MasterData\Contracts\TaxCalculator;
use App\Modules\MasterData\Contracts\TaxCatalog;
use App\Modules\MasterData\DTOs\ItemInfo;
use App\Modules\Sales\Exceptions\SalesException;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;

/**
 * Turns the lines a person (or the API) typed into priced, taxed document lines: finds the item, checks the unit,
 * fills the price from the customer's price list and the tax code from the item when they were left empty, and
 * totals the document. Prices on a document are always net of tax; a tax-inclusive price list is converted here.
 */
final class LineBuilder
{
    public function __construct(
        private readonly ItemCatalog $items,
        private readonly PriceResolver $prices,
        private readonly TaxCalculator $rates,
        private readonly TaxCatalog $taxCatalog,
        private readonly SalesCalculator $calculator,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $raw  item (code) or item_id, qty, uom_id?, unit_price?, discount_pct?, tax_code_id?, description?
     * @return array{lines: list<array<string, mixed>>, totals: array{net: string, vat: string, levy: string, gross: string}}
     *
     * @throws BusinessException
     */
    public function build(array $raw, int $customerId, string $date): array
    {
        if ($raw === []) {
            throw SalesException::make('sales.lines_required', 'Add at least one line.', 422, 'lines');
        }
        if (count($raw) > 200) {
            throw SalesException::make('sales.lines_too_many', 'A document can have at most 200 lines.', 422, 'lines');
        }

        $on = CarbonImmutable::parse($date, config('core.display_timezone'));
        $lines = [];
        $forTax = [];

        foreach ($raw as $i => $row) {
            $field = "lines.{$i}";
            $item = $this->item($row, $field);
            $uomId = $item->baseUomId;
            if (isset($row['uom_id']) && $row['uom_id'] !== '') {
                $uomId = (int) $row['uom_id'];
            } elseif (isset($row['uom']) && trim((string) $row['uom']) !== '') {
                $byCode = collect($this->items->units($item->id))->first(fn ($u) => strcasecmp($u->code, trim((string) $row['uom'])) === 0);
                $uomId = $byCode->uomId ?? 0;
            }
            $unit = collect($this->items->units($item->id))->first(fn ($u) => $u->uomId === $uomId && ($u->forSale || $u->isBase));
            if ($unit === null) {
                throw SalesException::make('sales.uom_invalid', "{$item->code} cannot be sold in that unit.", 422, "{$field}.uom");
            }

            $qty = trim((string) ($row['qty'] ?? ''));
            if (! Decimal::isDecimal($qty, $unit->decimals) || Decimal::compare($qty, '0') <= 0) {
                throw SalesException::make('sales.qty_invalid', "Enter a quantity above zero for {$item->code} with at most {$unit->decimals} decimal place(s).", 422, "{$field}.qty");
            }

            $discount = trim((string) ($row['discount_pct'] ?? '0')) ?: '0';
            if (! Decimal::isDecimal($discount, 4) || Decimal::compare($discount, '0') < 0 || Decimal::compare($discount, '100') > 0) {
                throw SalesException::make('sales.discount_invalid', 'A discount is between 0 and 100 percent.', 422, "{$field}.discount_pct");
            }

            $taxCodeId = $item->defaultTaxCodeId;
            if (isset($row['tax_code_id']) && $row['tax_code_id'] !== '') {
                $taxCodeId = (int) $row['tax_code_id'];
            } elseif (isset($row['tax_code']) && trim((string) $row['tax_code']) !== '') {
                $found = collect($this->taxCatalog->codes(['vat', 'exempt']))->first(fn ($c) => strcasecmp($c['code'], trim((string) $row['tax_code'])) === 0);
                if ($found === null) {
                    throw SalesException::make('sales.tax_code_invalid', 'Choose a VAT or exempt tax code.', 422, "{$field}.tax_code");
                }
                $taxCodeId = $found['id'];
            }
            if ($taxCodeId !== null && ! in_array($this->taxCatalog->kind($taxCodeId), ['vat', 'exempt'], true)) {
                throw SalesException::make('sales.tax_code_invalid', "Choose a VAT or exempt tax code for {$item->code}.", 422, "{$field}.tax_code");
            }

            $price = isset($row['unit_price']) && trim((string) $row['unit_price']) !== '' ? trim((string) $row['unit_price']) : null;
            if ($price === null) {
                $price = $this->priceFor($item, $qty, $uomId, $customerId, $taxCodeId, $on, $field);
            } elseif (! Decimal::isDecimal($price, 4) || Decimal::compare($price, '0') < 0) {
                throw SalesException::make('sales.price_invalid', 'A price is zero or more, with at most 4 decimal places.', 422, "{$field}.unit_price");
            }

            $net = $this->calculator->lineNet($qty, $price, $discount);
            $description = isset($row['description']) ? trim((string) $row['description']) : '';
            $lines[] = [
                'line_no' => $i + 1, 'item_id' => $item->id, 'description' => $description !== '' ? mb_substr($description, 0, 255) : $item->name,
                'uom_id' => $uomId, 'qty' => $qty, 'unit_price' => Decimal::round($price, 4), 'discount_pct' => Decimal::round($discount, 4),
                'tax_code_id' => $taxCodeId, 'net_amount' => $net,
            ];
            $forTax[] = ['net' => $net, 'tax_code_id' => $taxCodeId];
        }

        $computed = $this->calculator->compute($forTax, $on);

        return ['lines' => $lines, 'totals' => ['net' => $computed['net'], 'vat' => $computed['vat'], 'levy' => $computed['levy'], 'gross' => $computed['gross']]];
    }

    /** @param  array<string, mixed>  $row */
    private function item(array $row, string $field): ItemInfo
    {
        $item = isset($row['item_id']) && $row['item_id'] !== '' ? $this->items->find((int) $row['item_id']) : (isset($row['item']) ? $this->items->findByCode((string) $row['item']) : null);
        if ($item === null) {
            throw SalesException::make('sales.item_not_found', 'Choose an item for every line.', 422, "{$field}.item");
        }
        $item = $this->items->requireActive($item->id);
        if (! $item->isSellable) {
            throw SalesException::make('sales.item_not_sellable', "{$item->code} is not marked as sellable.", 422, "{$field}.item");
        }

        return $item;
    }

    private function priceFor(ItemInfo $item, string $qty, int $uomId, int $customerId, ?int $taxCodeId, CarbonImmutable $on, string $field): string
    {
        $quote = $this->prices->quote($item->id, $qty, $uomId, 'sales', null, $customerId, $on);
        if ($quote === null) {
            throw SalesException::make('sales.price_missing', "No price is set for {$item->code}; enter one.", 422, "{$field}.unit_price");
        }
        if ($quote->currencyCode !== 'LKR') {
            throw SalesException::make('sales.price_currency', "The price list for {$item->code} is in {$quote->currencyCode}; enter the LKR price.", 422, "{$field}.unit_price");
        }
        if (! $quote->taxInclusive || $taxCodeId === null || $this->taxCatalog->kind($taxCodeId) !== 'vat') {
            return $quote->unitPrice;
        }

        // A tax-inclusive list: the document carries the price without VAT.
        $rate = $this->rates->rate($taxCodeId, $on);

        return Decimal::round(Decimal::div($quote->unitPrice, Decimal::add('1', Decimal::div($rate, '100'))), 4);
    }
}
