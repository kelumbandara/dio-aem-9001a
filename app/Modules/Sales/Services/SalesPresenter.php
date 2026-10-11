<?php

namespace App\Modules\Sales\Services;

use App\Modules\Core\Contracts\CompanyInfo;
use App\Modules\Finance\Contracts\ReceivablePosition;
use App\Modules\Inventory\Contracts\WarehouseDirectory;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Modules\MasterData\Contracts\TaxCatalog;
use App\Modules\Sales\Models\CreditNote;
use App\Modules\Sales\Models\CreditNoteLine;
use App\Modules\Sales\Models\CreditNoteTax;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\InvoiceTax;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderLine;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\QuotationLine;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/** Shapes sales documents for the API and the screens: ids are public ids, parties/items/units/taxes are shown by code and name. */
final class SalesPresenter
{
    /** @var array<int, array<int, string>> item id => uom id => code */
    private array $uomCodes = [];

    public function __construct(
        private readonly PartyDirectory $parties,
        private readonly ItemCatalog $items,
        private readonly TaxCatalog $taxes,
        private readonly WarehouseDirectory $warehouses,
        private readonly CompanyInfo $company,
        private readonly ReceivablePosition $receivables,
    ) {}

    /** @return array<string, mixed> */
    public function customer(int $id): array
    {
        $c = $this->parties->customer($id);

        return $c === null ? ['code' => '?', 'name' => '?'] : ['code' => $c->code, 'name' => $c->name, 'tax_id' => $c->taxId];
    }

    /**
     * @param  Collection<int, covariant Quotation|Order|Delivery|Invoice|CreditNote>  $docs
     * @return list<array<string, mixed>>
     */
    public function headers(Collection $docs): array
    {
        $cache = [];
        $warehouses = $this->warehouses->labels($docs->pluck('warehouse_id')->filter()->unique()->values()->all());

        return $docs->map(function ($d) use (&$cache, $warehouses) {
            $customer = $cache[$d->customer_id] ??= $this->customer($d->customer_id);
            $row = ['id' => $d->public_id, 'document_no' => $d->document_no, 'customer' => $customer['code'], 'customer_name' => $customer['name'], 'status' => $this->status($d)];
            foreach (['quote_date', 'valid_until', 'order_date', 'required_date', 'delivery_date', 'invoice_date', 'due_date', 'note_date'] as $f) {
                if (array_key_exists($f, $d->getAttributes())) {
                    $row[$f] = $d->{$f}?->toDateString();
                }
            }
            foreach (['net_total', 'vat_total', 'levy_total', 'gross_total'] as $f) {
                if (array_key_exists($f, $d->getAttributes())) {
                    $row[$f] = $d->{$f};
                }
            }
            if (array_key_exists('warehouse_id', $d->getAttributes()) && $d->warehouse_id) {
                $row['warehouse'] = $warehouses[$d->warehouse_id] ?? null;
            }

            return $row;
        })->values()->all();
    }

    public function status(object $d): string
    {
        if ($d instanceof Quotation && in_array($d->status, ['draft', 'sent'], true) && $d->valid_until->toDateString() < now(config('core.display_timezone'))->toDateString()) {
            return 'expired';
        }

        return $d->status ?? 'posted';
    }

    /** @return array<string, mixed> */
    public function quotation(Quotation $q): array
    {
        return $this->headers(collect([$q]))[0] + [
            'prices_include_vat' => $q->prices_include_vat, 'reference' => $q->reference, 'notes' => $q->notes, 'terms' => $q->terms, 'version' => $q->version, 'raw_status' => $q->status,
            'lines' => $this->lines($q->lines), 'totals' => $this->totals($q),
        ];
    }

    /** @return array<string, mixed> */
    public function order(Order $o): array
    {
        $base = $this->headers(collect([$o]))[0] + [
            'prices_include_vat' => $o->prices_include_vat, 'customer_ref' => $o->customer_ref, 'payment_terms_days' => $o->payment_terms_days, 'notes' => $o->notes, 'version' => $o->version,
            'quotation' => $o->quotation_id ? Quotation::query()->whereKey($o->quotation_id)->value('document_no') : null,
            'confirmed_at' => $o->confirmed_at?->toIso8601String(), 'credit_override_reason' => $o->credit_override_reason, 'cancel_reason' => $o->cancel_reason,
            'lines' => $this->lines($o->lines, true), 'totals' => $this->totals($o),
        ];
        $base['deliveries'] = Delivery::query()->where('order_id', $o->id)->orderBy('id')->get()->map(fn (Delivery $d) => ['id' => $d->public_id, 'document_no' => $d->document_no, 'date' => $d->delivery_date->toDateString(), 'status' => $d->status])->all();
        $base['invoices'] = Invoice::query()->where('order_id', $o->id)->orderBy('id')->get()->map(fn (Invoice $i) => ['id' => $i->public_id, 'document_no' => $i->document_no, 'date' => $i->invoice_date->toDateString(), 'gross_total' => $i->gross_total])->all();

        return $base;
    }

    /** @return array<string, mixed> */
    public function delivery(Delivery $d): array
    {
        $order = Order::query()->find($d->order_id);
        $lineNos = $order?->lines()->pluck('line_no', 'id') ?? collect();
        $rows = $d->lines->map(function ($l) use ($lineNos) {
            $item = $this->items->find($l->item_id);

            return ['order_line' => $lineNos[$l->order_line_id] ?? null, 'item' => $item?->code, 'item_name' => $item?->name, 'uom' => $this->uom($l->item_id, $l->uom_id), 'qty' => $l->qty];
        })->all();

        return $this->headers(collect([$d]))[0] + ['order' => $order?->document_no, 'order_id' => $order?->public_id, 'notes' => $d->notes, 'lines' => $rows];
    }

    /** @return array<string, mixed> */
    public function invoice(Invoice $i): array
    {
        $order = Order::query()->find($i->order_id);

        return $this->headers(collect([$i]))[0] + [
            'order' => $order?->document_no, 'order_id' => $order?->public_id, 'prices_include_vat' => $i->prices_include_vat, 'customer_ref' => $i->customer_ref, 'notes' => $i->notes,
            'lines' => $this->invoiceLines($i), 'taxes' => $this->taxRows($i->taxes), 'totals' => $this->totals($i),
            'credit_notes' => CreditNote::query()->where('invoice_id', $i->id)->orderBy('id')->get()->map(fn (CreditNote $c) => ['id' => $c->public_id, 'document_no' => $c->document_no, 'date' => $c->note_date->toDateString(), 'gross_total' => $c->gross_total])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function creditNote(CreditNote $c): array
    {
        $invoice = Invoice::query()->find($c->invoice_id);
        $invoiceLines = $invoice?->lines()->get()->keyBy('id') ?? collect();
        $rows = $c->lines->map(function ($l) use ($invoiceLines) {
            $item = $this->items->find($l->item_id);

            return ['invoice_line' => $invoiceLines[$l->invoice_line_id]->line_no ?? null, 'item' => $item?->code, 'item_name' => $item?->name, 'uom' => $this->uom($l->item_id, $l->uom_id), 'qty' => $l->qty, 'net_amount' => $l->net_amount, 'vat_amount' => $l->vat_amount];
        })->all();

        return $this->headers(collect([$c]))[0] + [
            'invoice' => $invoice?->document_no, 'invoice_id' => $invoice?->public_id, 'reason' => $c->reason, 'restock' => $c->restock,
            'lines' => $rows, 'taxes' => $this->taxRows($c->taxes), 'totals' => $this->totals($c),
        ];
    }

    /**
     * Everything the printable tax invoice shows.
     *
     * @return array<string, mixed>
     */
    public function invoicePrint(Invoice $i): array
    {
        $data = $this->invoice($i);
        $customer = $this->parties->customer($i->customer_id);

        return $data + [
            'company' => $this->company->profile(),
            'bill_to' => ['name' => $customer?->name, 'code' => $customer?->code, 'tax_id' => $customer?->taxId, 'address' => $this->parties->billingAddress('customer', $i->customer_id)],
            'banks' => $this->receivables->invoiceBankAccounts(),
            'payment_terms_days' => (int) $i->invoice_date->diffInDays($i->due_date),
        ];
    }

    /**
     * @param  Collection<int, covariant QuotationLine|OrderLine>  $lines
     * @return list<array<string, mixed>>
     */
    private function lines(Collection $lines, bool $progress = false): array
    {
        $items = $this->items->findMany($lines->pluck('item_id')->all());

        return $lines->map(function ($l) use ($items, $progress) {
            $item = $items[$l->item_id] ?? null;
            $tax = $l->tax_code_id ? $this->taxes->find($l->tax_code_id) : null;
            $row = [
                'line' => $l->line_no, 'item' => $item?->code, 'item_name' => $item?->name, 'item_id' => $l->item_id, 'description' => $l->description, 'uom_id' => $l->uom_id, 'uom' => $this->uom($l->item_id, $l->uom_id),
                'qty' => $l->qty, 'unit_price' => $l->unit_price, 'discount_pct' => $l->discount_pct, 'tax_code_id' => $l->tax_code_id, 'tax_code' => $tax['code'] ?? null, 'net_amount' => $l->net_amount,
                'stocked' => $item?->isStocked() ?? false,
            ];
            if ($progress) {
                $row += ['qty_delivered' => $l->qty_delivered, 'qty_invoiced' => $l->qty_invoiced, 'reserved' => $l->reservation_id !== null];
            }

            return $row;
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function invoiceLines(Invoice $i): array
    {
        $items = $this->items->findMany($i->lines->pluck('item_id')->all());
        $credited = CreditNoteLine::query()->whereIn('invoice_line_id', $i->lines->pluck('id'))->selectRaw('invoice_line_id, sum(qty) as qty')->groupBy('invoice_line_id')->pluck('qty', 'invoice_line_id');

        return $i->lines->map(function ($l) use ($items, $credited) {
            $item = $items[$l->item_id] ?? null;
            $tax = $l->tax_code_id ? $this->taxes->find($l->tax_code_id) : null;

            return [
                'line' => $l->line_no, 'item' => $item?->code, 'item_name' => $item?->name, 'description' => $l->description, 'uom' => $this->uom($l->item_id, $l->uom_id),
                'qty' => $l->qty, 'unit_price' => $l->unit_price, 'discount_pct' => $l->discount_pct, 'net_amount' => $l->net_amount,
                'tax_code' => $tax['code'] ?? null, 'vat_rate' => $l->vat_rate, 'vat_amount' => $l->vat_amount, 'levy_liable' => $l->levy_liable,
                'qty_credited' => Decimal::round((string) ($credited[$l->id] ?? '0'), 6), 'stocked' => $item?->isStocked() ?? false,
            ];
        })->all();
    }

    /**
     * @param  Collection<int, covariant InvoiceTax|CreditNoteTax>  $taxes
     * @return list<array<string, mixed>>
     */
    private function taxRows(Collection $taxes): array
    {
        return $taxes->map(function ($t) {
            $code = $this->taxes->find($t->tax_code_id);

            return ['code' => $code['code'] ?? '?', 'name' => $code['name'] ?? '', 'kind' => $t->kind, 'rate' => $t->rate, 'base_amount' => $t->base_amount, 'amount' => $t->amount];
        })->all();
    }

    /** @return array{net: string, vat: string, levy: string, gross: string} */
    private function totals(object $d): array
    {
        return ['net' => $d->net_total, 'vat' => $d->vat_total, 'levy' => $d->levy_total, 'gross' => $d->gross_total];
    }

    private function uom(int $itemId, int $uomId): ?string
    {
        if (! isset($this->uomCodes[$itemId])) {
            $this->uomCodes[$itemId] = collect($this->items->units($itemId))->mapWithKeys(fn ($u) => [$u->uomId => $u->code])->all();
        }

        return $this->uomCodes[$itemId][$uomId] ?? null;
    }
}
