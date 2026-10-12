<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Core\Contracts\CompanyInfo;
use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Modules\MasterData\Contracts\TaxCatalog;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseReturn;
use App\Modules\Procurement\Models\SupplierBill;
use Illuminate\Support\Collection;

/** Shapes purchasing documents for the API and the screens: ids are public ids, suppliers/items/units/taxes shown by code and name. */
final class PurchasePresenter
{
    /** @var array<int, array<int, string>> item id => uom id => code */
    private array $uomCodes = [];

    public function __construct(
        private readonly PartyDirectory $parties,
        private readonly ItemCatalog $items,
        private readonly TaxCatalog $taxes,
        private readonly WarehouseDirectory $warehouses,
        private readonly CompanyInfo $company,
    ) {}

    /**
     * @param  Collection<int, covariant PurchaseOrder|GoodsReceipt|SupplierBill|PurchaseReturn>  $docs
     * @return list<array<string, mixed>>
     */
    public function headers(Collection $docs): array
    {
        $cache = [];
        $warehouses = $this->warehouses->labels($docs->pluck('warehouse_id')->filter()->unique()->values()->all());

        return $docs->map(function ($d) use (&$cache, $warehouses) {
            $supplier = $cache[$d->supplier_id] ??= $this->supplier($d->supplier_id);
            $attrs = $d->getAttributes();
            $row = ['id' => $d->public_id, 'document_no' => $d->document_no, 'supplier' => $supplier['code'], 'supplier_name' => $supplier['name'], 'status' => $d->status ?? 'posted'];
            foreach (['order_date', 'expected_date', 'receipt_date', 'bill_date', 'due_date', 'return_date'] as $f) {
                if (array_key_exists($f, $attrs)) {
                    $row[$f] = $d->{$f}?->toDateString();
                }
            }
            foreach (['net_total', 'vat_total', 'gross_total'] as $f) {
                if (array_key_exists($f, $attrs)) {
                    $row[$f] = $d->{$f};
                }
            }
            if (array_key_exists('supplier_invoice_no', $attrs)) {
                $row['supplier_invoice_no'] = $d->supplier_invoice_no;
            }
            if (array_key_exists('warehouse_id', $attrs) && $d->warehouse_id) {
                $row['warehouse'] = $warehouses[$d->warehouse_id] ?? null;
            }

            return $row;
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public function supplier(int $id): array
    {
        $s = $this->parties->supplier($id);

        return $s === null ? ['code' => '?', 'name' => '?'] : ['code' => $s->code, 'name' => $s->name, 'tax_id' => $s->taxId];
    }

    /** @return array<string, mixed> */
    public function order(PurchaseOrder $o): array
    {
        $items = $this->items->findMany($o->lines->pluck('item_id')->all());
        $lines = $o->lines->map(function ($l) use ($items) {
            $item = $items[$l->item_id] ?? null;
            $tax = $l->tax_code_id ? $this->taxes->find($l->tax_code_id) : null;

            return [
                'line' => $l->line_no, 'item' => $item?->code, 'item_name' => $item?->name, 'description' => $l->description, 'uom_id' => $l->uom_id, 'uom' => $this->uom($l->item_id, $l->uom_id),
                'qty' => $l->qty, 'unit_price' => $l->unit_price, 'discount_pct' => $l->discount_pct, 'tax_code' => $tax['code'] ?? null, 'net_amount' => $l->net_amount,
                'qty_received' => $l->qty_received, 'qty_returned' => $l->qty_returned, 'qty_billed' => $l->qty_billed, 'stocked' => $item?->isStocked() ?? false,
            ];
        })->all();

        return $this->headers(collect([$o]))[0] + [
            'supplier_ref' => $o->supplier_ref, 'payment_terms_days' => $o->payment_terms_days, 'notes' => $o->notes, 'version' => $o->version, 'cancel_reason' => $o->cancel_reason,
            'approval_request_id' => $o->approval_request_id, 'approved_at' => $o->approved_at?->toIso8601String(),
            'lines' => $lines, 'totals' => ['net' => $o->net_total, 'vat' => $o->vat_total, 'gross' => $o->gross_total],
            'receipts' => GoodsReceipt::query()->where('order_id', $o->id)->orderBy('id')->get()->map(fn (GoodsReceipt $r) => ['id' => $r->public_id, 'document_no' => $r->document_no, 'date' => $r->receipt_date->toDateString(), 'status' => $r->status])->all(),
            'bills' => SupplierBill::query()->where('order_id', $o->id)->orderBy('id')->get()->map(fn (SupplierBill $b) => ['id' => $b->public_id, 'document_no' => $b->document_no, 'supplier_invoice_no' => $b->supplier_invoice_no, 'date' => $b->bill_date->toDateString(), 'gross_total' => $b->gross_total])->all(),
            'returns' => PurchaseReturn::query()->where('order_id', $o->id)->orderBy('id')->get()->map(fn (PurchaseReturn $r) => ['id' => $r->public_id, 'document_no' => $r->document_no, 'date' => $r->return_date->toDateString(), 'gross_total' => $r->gross_total])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function receipt(GoodsReceipt $r): array
    {
        $order = PurchaseOrder::query()->find($r->order_id);
        $lineNos = $order?->lines()->pluck('line_no', 'id') ?? collect();
        $rows = $r->lines->map(function ($l) use ($lineNos) {
            $item = $this->items->find($l->item_id);

            return ['order_line' => $lineNos[$l->order_line_id] ?? null, 'item' => $item?->code, 'item_name' => $item?->name, 'uom' => $this->uom($l->item_id, $l->uom_id), 'qty' => $l->qty, 'unit_cost' => $l->unit_cost, 'value' => $l->value, 'lot' => $l->lot_no, 'expiry_date' => $l->expiry_date];
        })->all();

        return $this->headers(collect([$r]))[0] + ['order' => $order?->document_no, 'order_id' => $order?->public_id, 'supplier_dn_ref' => $r->supplier_dn_ref, 'notes' => $r->notes, 'lines' => $rows];
    }

    /** @return array<string, mixed> */
    public function bill(SupplierBill $b): array
    {
        $order = PurchaseOrder::query()->find($b->order_id);
        $lineNos = $order?->lines()->pluck('line_no', 'id') ?? collect();
        $rows = $b->lines->map(function ($l) use ($lineNos) {
            $item = $this->items->find($l->item_id);
            $tax = $l->tax_code_id ? $this->taxes->find($l->tax_code_id) : null;

            return [
                'line' => $l->line_no, 'order_line' => $lineNos[$l->order_line_id] ?? null, 'item' => $item?->code, 'item_name' => $item?->name, 'uom' => $this->uom($l->item_id, $l->uom_id), 'qty' => $l->qty, 'unit_price' => $l->unit_price,
                'net_amount' => $l->net_amount, 'grni_amount' => $l->grni_amount, 'variance_amount' => $l->variance_amount, 'tax_code' => $tax['code'] ?? null, 'vat_rate' => $l->vat_rate, 'vat_amount' => $l->vat_amount,
            ];
        })->all();

        return $this->headers(collect([$b]))[0] + [
            'order' => $order?->document_no, 'order_id' => $order?->public_id, 'notes' => $b->notes, 'variance_total' => $b->variance_total, 'override_reason' => $b->override_reason,
            'lines' => $rows, 'totals' => ['net' => $b->net_total, 'vat' => $b->vat_total, 'gross' => $b->gross_total],
            'returns' => PurchaseReturn::query()->where('bill_id', $b->id)->orderBy('id')->get()->map(fn (PurchaseReturn $r) => ['id' => $r->public_id, 'document_no' => $r->document_no, 'gross_total' => $r->gross_total])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function purchaseReturn(PurchaseReturn $r): array
    {
        $order = PurchaseOrder::query()->find($r->order_id);
        $lineNos = $order?->lines()->pluck('line_no', 'id') ?? collect();
        $bill = $r->bill_id ? SupplierBill::query()->find($r->bill_id) : null;
        $rows = $r->lines->map(function ($l) use ($lineNos) {
            $item = $this->items->find($l->item_id);

            return ['order_line' => $lineNos[$l->order_line_id] ?? null, 'item' => $item?->code, 'item_name' => $item?->name, 'uom' => $this->uom($l->item_id, $l->uom_id), 'qty' => $l->qty, 'goods_returned' => $l->goods_returned, 'net_amount' => $l->net_amount, 'vat_amount' => $l->vat_amount];
        })->all();

        return $this->headers(collect([$r]))[0] + [
            'order' => $order?->document_no, 'order_id' => $order?->public_id, 'bill' => $bill?->supplier_invoice_no, 'bill_id' => $bill?->public_id, 'reason' => $r->reason,
            'lines' => $rows, 'totals' => ['net' => $r->net_total, 'vat' => $r->vat_total, 'gross' => $r->gross_total],
        ];
    }

    /**
     * Everything the printable purchase order shows.
     *
     * @return array<string, mixed>
     */
    public function orderPrint(PurchaseOrder $o): array
    {
        $supplier = $this->parties->supplier($o->supplier_id);

        return $this->order($o) + [
            'company' => $this->company->profile(),
            'vendor' => ['name' => $supplier?->name, 'code' => $supplier?->code, 'tax_id' => $supplier?->taxId, 'address' => $this->parties->billingAddress('supplier', $o->supplier_id)],
        ];
    }

    private function uom(int $itemId, int $uomId): ?string
    {
        if (! isset($this->uomCodes[$itemId])) {
            $this->uomCodes[$itemId] = collect($this->items->units($itemId))->mapWithKeys(fn ($u) => [$u->uomId => $u->code])->all();
        }

        return $this->uomCodes[$itemId][$uomId] ?? null;
    }
}
