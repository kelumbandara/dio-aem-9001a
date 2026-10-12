<?php

namespace App\Modules\Procurement\Services;

use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\Contracts\PriceResolver;
use App\Modules\MasterData\Contracts\TaxCalculator;
use App\Modules\MasterData\Contracts\TaxCatalog;
use App\Modules\MasterData\DTOs\ItemInfo;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;

/**
 * Turns the lines a person typed into priced, taxed purchase order lines: finds the item and unit, fills the price from
 * the supplier's price list and the tax code from the item when left empty, and totals the order. Prices are net of VAT.
 */
final class LineBuilder
{
    public function __construct(
        private readonly ItemCatalog $items,
        private readonly PriceResolver $prices,
        private readonly TaxCalculator $rates,
        private readonly TaxCatalog $taxCatalog,
        private readonly PurchaseCalculator $calculator,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $raw  item (code) or item_id, qty, uom|uom_id?, unit_price?, discount_pct?, tax_code|tax_code_id?, description?
     * @return array{lines: list<array<string, mixed>>, totals: array{net: string, vat: string, gross: string}}
     *
     * @throws BusinessException
     */
    public function build(array $raw, int $supplierId, string $date): array
    {
        if ($raw === []) {
            throw ProcurementException::make('procurement.lines_required', 'Add at least one line.', 422, 'lines');
        }
        if (count($raw) > 200) {
            throw ProcurementException::make('procurement.lines_too_many', 'An order can have at most 200 lines.', 422, 'lines');
        }

        $on = CarbonImmutable::parse($date, config('core.display_timezone'));
        $lines = [];
        $net = '0';
        $vat = '0';

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
            $unit = collect($this->items->units($item->id))->first(fn ($u) => $u->uomId === $uomId && ($u->forPurchase || $u->isBase));
            if ($unit === null) {
                throw ProcurementException::make('procurement.uom_invalid', "{$item->code} cannot be bought in that unit.", 422, "{$field}.uom");
            }

            $qty = trim((string) ($row['qty'] ?? ''));
            if (! Decimal::isDecimal($qty, $unit->decimals) || Decimal::compare($qty, '0') <= 0) {
                throw ProcurementException::make('procurement.qty_invalid', "Enter a quantity above zero for {$item->code} with at most {$unit->decimals} decimal place(s).", 422, "{$field}.qty");
            }

            $discount = trim((string) ($row['discount_pct'] ?? '0')) ?: '0';
            if (! Decimal::isDecimal($discount, 4) || Decimal::compare($discount, '0') < 0 || Decimal::compare($discount, '100') > 0) {
                throw ProcurementException::make('procurement.discount_invalid', 'A discount is between 0 and 100 percent.', 422, "{$field}.discount_pct");
            }

            $taxCodeId = $item->defaultTaxCodeId ?? $this->defaultVatCodeId();
            if (isset($row['tax_code_id']) && $row['tax_code_id'] !== '') {
                $taxCodeId = (int) $row['tax_code_id'];
            } elseif (isset($row['tax_code']) && trim((string) $row['tax_code']) !== '') {
                $found = collect($this->taxCatalog->codes(['vat', 'exempt']))->first(fn ($c) => strcasecmp($c['code'], trim((string) $row['tax_code'])) === 0);
                if ($found === null) {
                    throw ProcurementException::make('procurement.tax_code_invalid', 'Choose a VAT or exempt tax code.', 422, "{$field}.tax_code");
                }
                $taxCodeId = $found['id'];
            }
            if ($taxCodeId !== null && ! in_array($this->taxCatalog->kind($taxCodeId), ['vat', 'exempt'], true)) {
                throw ProcurementException::make('procurement.tax_code_invalid', "Choose a VAT or exempt tax code for {$item->code}.", 422, "{$field}.tax_code");
            }

            $price = isset($row['unit_price']) && trim((string) $row['unit_price']) !== '' ? trim((string) $row['unit_price']) : null;
            if ($price === null) {
                $price = $this->priceFor($item, $qty, $uomId, $supplierId, $taxCodeId, $on, $field);
            } elseif (! Decimal::isDecimal($price, 4) || Decimal::compare($price, '0') < 0) {
                throw ProcurementException::make('procurement.price_invalid', 'A price is zero or more, with at most 4 decimal places.', 422, "{$field}.unit_price");
            }

            $lineNet = $this->calculator->lineNet($qty, $price, $discount);
            $lineVat = $this->calculator->vat($taxCodeId, $lineNet, $on);
            $description = isset($row['description']) ? trim((string) $row['description']) : '';
            $lines[] = [
                'line_no' => $i + 1, 'item_id' => $item->id, 'description' => $description !== '' ? mb_substr($description, 0, 255) : $item->name,
                'uom_id' => $uomId, 'qty' => $qty, 'unit_price' => Decimal::round($price, 4), 'discount_pct' => Decimal::round($discount, 4),
                'tax_code_id' => $taxCodeId, 'net_amount' => $lineNet,
            ];
            $net = Decimal::add($net, $lineNet);
            $vat = Decimal::add($vat, $lineVat['amount']);
        }

        $net = Decimal::round($net, 2);
        $vat = Decimal::round($vat, 2);

        return ['lines' => $lines, 'totals' => ['net' => $net, 'vat' => $vat, 'gross' => Decimal::round(Decimal::add($net, $vat), 2)]];
    }

    /** @param  array<string, mixed>  $row */
    private function item(array $row, string $field): ItemInfo
    {
        $item = isset($row['item_id']) && $row['item_id'] !== '' ? $this->items->find((int) $row['item_id']) : (isset($row['item']) ? $this->items->findByCode((string) $row['item']) : null);
        if ($item === null) {
            throw ProcurementException::make('procurement.item_not_found', 'Choose an item for every line.', 422, "{$field}.item");
        }
        $item = $this->items->requireActive($item->id);
        if (! $item->isPurchasable) {
            throw ProcurementException::make('procurement.item_not_purchasable', "{$item->code} is not marked as purchasable.", 422, "{$field}.item");
        }

        return $item;
    }

    private function priceFor(ItemInfo $item, string $qty, int $uomId, int $supplierId, ?int $taxCodeId, CarbonImmutable $on, string $field): string
    {
        $quote = $this->prices->quote($item->id, $qty, $uomId, 'purchase', null, $supplierId, $on);
        if ($quote === null) {
            throw ProcurementException::make('procurement.price_missing', "No price is set for {$item->code}; enter one.", 422, "{$field}.unit_price");
        }
        if ($quote->currencyCode !== 'LKR') {
            throw ProcurementException::make('procurement.price_currency', "The price list for {$item->code} is in {$quote->currencyCode}; enter the LKR price.", 422, "{$field}.unit_price");
        }
        if (! $quote->taxInclusive || $taxCodeId === null || $this->taxCatalog->kind($taxCodeId) !== 'vat') {
            return $quote->unitPrice;
        }

        return Decimal::round(Decimal::div($quote->unitPrice, Decimal::add('1', Decimal::div($this->rates->rate($taxCodeId, $on), '100'))), 4);
    }

    private function defaultVatCodeId(): ?int
    {
        $code = (string) config('procurement.default_vat_code');
        $found = collect($this->taxCatalog->codes(['vat']))->first(fn ($c) => strcasecmp($c['code'], $code) === 0);

        return $found['id'] ?? null;
    }
}
