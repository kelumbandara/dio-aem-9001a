<?php

namespace App\Modules\MasterData\Support;

use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\Supplier;

/**
 * Turns validated request/CSV input (related records given by code) into what the Save actions expect
 * (ids). On update, fields that were not sent keep their current value, so PATCH can be partial.
 * Shared by the API, the web forms and the importer, so all three behave identically.
 */
final class InputMapper
{
    public function __construct(private readonly CodeLookup $codes) {}

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public function item(array $in, ?Item $existing = null): array
    {
        $has = fn (string $k) => array_key_exists($k, $in);

        $out = [
            'code' => $in['code'] ?? null,
            'name' => $in['name'] ?? $existing?->name,
            'description' => $has('description') ? $in['description'] : $existing?->description,
            'category_id' => $has('category') ? $this->codes->category($in['category']) : $existing?->category_id,
            'item_type' => $in['item_type'] ?? $existing->item_type ?? 'stock',
            'base_uom_id' => $has('base_uom') ? $this->codes->uom($in['base_uom'], 'base_uom') : $existing?->base_uom_id,
            'is_sellable' => $has('is_sellable') ? (bool) $in['is_sellable'] : $existing->is_sellable ?? true,
            'is_purchasable' => $has('is_purchasable') ? (bool) $in['is_purchasable'] : $existing->is_purchasable ?? true,
            'tracking' => $in['tracking'] ?? $existing->tracking ?? 'none',
            'shelf_life_days' => $has('shelf_life_days') ? $in['shelf_life_days'] : $existing?->shelf_life_days,
            'barcode' => $has('barcode') ? $in['barcode'] : $existing?->barcode,
            'default_tax_code_id' => $has('default_tax_code') ? $this->codes->taxCode($in['default_tax_code']) : $existing?->default_tax_code_id,
            'is_active' => $has('is_active') ? (bool) $in['is_active'] : $existing->is_active ?? true,
        ];

        if ($has('uoms')) {
            $out['uoms'] = array_map(fn ($row, $i) => [
                'uom_id' => $this->codes->uom($row['uom'] ?? null, "uoms.{$i}.uom"),
                'factor' => (string) ($row['factor'] ?? ''),
                'for_purchase' => (bool) ($row['for_purchase'] ?? true),
                'for_sale' => (bool) ($row['for_sale'] ?? true),
            ], $in['uoms'], array_keys($in['uoms']));
        } elseif ($existing !== null) {
            $out['uoms'] = $existing->uoms->map(fn ($u) => ['uom_id' => $u->uom_id, 'factor' => $u->factor, 'for_purchase' => $u->for_purchase, 'for_sale' => $u->for_sale])->all();
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public function customer(array $in, ?Customer $existing = null): array
    {
        $out = $this->party($in, $existing);
        $out['credit_limit'] = array_key_exists('credit_limit', $in) ? $in['credit_limit'] : $existing?->credit_limit;

        return $out;
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public function supplier(array $in, ?Supplier $existing = null): array
    {
        $out = $this->party($in, $existing);
        $out['lead_time_days'] = array_key_exists('lead_time_days', $in) ? $in['lead_time_days'] : $existing?->lead_time_days;

        return $out;
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function party(array $in, Customer|Supplier|null $existing): array
    {
        $has = fn (string $k) => array_key_exists($k, $in);

        $out = [
            'code' => $in['code'] ?? null,
            'name' => $in['name'] ?? $existing?->name,
            'legal_name' => $has('legal_name') ? $in['legal_name'] : $existing?->legal_name,
            'kind' => $in['kind'] ?? $existing->kind ?? 'company',
            'tax_id' => $has('tax_id') ? $in['tax_id'] : $existing?->tax_id,
            'business_reg_no' => $has('business_reg_no') ? $in['business_reg_no'] : $existing?->business_reg_no,
            'nic' => $has('nic') ? $in['nic'] : $existing?->nic,
            'email' => $has('email') ? $in['email'] : $existing?->email,
            'phone' => $has('phone') ? $in['phone'] : $existing?->phone,
            'website' => $has('website') ? $in['website'] : $existing?->website,
            'currency_id' => $has('currency') ? $this->codes->currency($in['currency']) : $existing?->currency_id,
            'payment_terms_id' => $has('payment_terms') ? $this->codes->paymentTerms($in['payment_terms']) : $existing?->payment_terms_id,
            'price_list_id' => $has('price_list') ? $this->codes->priceList($in['price_list']) : $existing?->price_list_id,
            'notes' => $has('notes') ? $in['notes'] : $existing?->notes,
            'is_active' => $has('is_active') ? (bool) $in['is_active'] : $existing->is_active ?? true,
        ];

        // Child lists: sent = replace; not sent = leave alone (the action skips null).
        foreach (['contacts', 'addresses'] as $list) {
            if ($has($list)) {
                $out[$list] = $in[$list];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public function priceList(array $in, ?PriceList $existing = null): array
    {
        $has = fn (string $k) => array_key_exists($k, $in);

        return [
            'code' => $in['code'] ?? null,
            'name' => $in['name'] ?? $existing?->name,
            'type' => $in['type'] ?? $existing?->type,
            'currency_id' => $has('currency') ? $this->codes->currency($in['currency']) : $existing?->currency_id,
            'tax_inclusive' => $has('tax_inclusive') ? (bool) $in['tax_inclusive'] : $existing->tax_inclusive ?? false,
            'is_default' => $has('is_default') ? (bool) $in['is_default'] : $existing->is_default ?? false,
            'valid_from' => $has('valid_from') ? $in['valid_from'] : $existing?->valid_from?->toDateString(),
            'valid_to' => $has('valid_to') ? $in['valid_to'] : $existing?->valid_to?->toDateString(),
            'is_active' => $has('is_active') ? (bool) $in['is_active'] : $existing->is_active ?? true,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $changes  each {item: code, uom?: code, min_qty?, unit_price|null}
     * @return list<array<string, mixed>>
     */
    public function priceChanges(array $changes): array
    {
        return array_map(function ($row, $i) {
            $item = $this->codes->item($row['item'] ?? null, "changes.{$i}.item");
            $uom = ! empty($row['uom']) ? $this->codes->uom($row['uom'], "changes.{$i}.uom") : null;

            return [
                'item_id' => $item,
                ...($uom !== null ? ['uom_id' => $uom] : []),
                'min_qty' => $row['min_qty'] ?? '0',
                'unit_price' => array_key_exists('unit_price', $row) && $row['unit_price'] !== '' ? $row['unit_price'] : null,
            ];
        }, $changes, array_keys($changes));
    }
}
