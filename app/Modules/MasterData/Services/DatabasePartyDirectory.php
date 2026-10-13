<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Modules\MasterData\DTOs\PartyInfo;
use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\CustomerAddress;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Models\SupplierAddress;

final class DatabasePartyDirectory implements PartyDirectory
{
    public function customer(int $customerId): ?PartyInfo
    {
        $customer = Customer::query()->with(['currency', 'paymentTerms'])->find($customerId);

        return $customer === null ? null : new PartyInfo(
            $customer->id, $customer->public_id, $customer->code, $customer->name, $customer->kind, $customer->tax_id,
            $customer->currency_id, $customer->currency?->code, $customer->paymentTerms?->days,
            $customer->credit_limit, $customer->price_list_id, $customer->is_active,
        );
    }

    public function supplier(int $supplierId): ?PartyInfo
    {
        $supplier = Supplier::query()->with(['currency', 'paymentTerms'])->find($supplierId);

        return $supplier === null ? null : new PartyInfo(
            $supplier->id, $supplier->public_id, $supplier->code, $supplier->name, $supplier->kind, $supplier->tax_id,
            $supplier->currency_id, $supplier->currency?->code, $supplier->paymentTerms?->days,
            null, $supplier->price_list_id, $supplier->is_active,
        );
    }

    public function customerByCode(string $code): ?PartyInfo
    {
        $id = Customer::query()->whereRaw('upper(code) = ?', [strtoupper(trim($code))])->value('id');

        return $id === null ? null : $this->customer((int) $id);
    }

    public function supplierByCode(string $code): ?PartyInfo
    {
        $id = Supplier::query()->whereRaw('upper(code) = ?', [strtoupper(trim($code))])->value('id');

        return $id === null ? null : $this->supplier((int) $id);
    }

    public function search(string $side, string $term, int $limit = 50): array
    {
        $model = $side === 'payable' || $side === 'supplier' ? Supplier::class : Customer::class;
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($term))).'%';
        $ids = $model::query()->where(fn ($q) => $q->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like]))->orderBy('code')->limit($limit)->pluck('id');

        return $ids->map(fn ($id) => $model === Supplier::class ? $this->supplier((int) $id) : $this->customer((int) $id))->filter()->values()->all();
    }

    public function contact(string $side, int $partyId): array
    {
        $row = ($side === 'supplier' ? Supplier::query() : Customer::query())->whereKey($partyId)->first(['email', 'phone']);
        $clean = fn (?string $v) => $v === null || trim($v) === '' ? null : trim($v);

        return ['email' => $clean($row?->email), 'phone' => $clean($row?->phone)];
    }

    public function billingAddress(string $side, int $partyId): array
    {
        $query = $side === 'supplier' ? SupplierAddress::query()->where('supplier_id', $partyId) : CustomerAddress::query()->where('customer_id', $partyId);
        $address = $query->orderByRaw("case when type = 'billing' then 0 else 1 end")->orderByDesc('is_default')->orderBy('id')->first();
        if ($address === null) {
            return [];
        }

        return array_values(array_filter([$address->line1, $address->line2, trim(($address->city ?? '').' '.($address->postal_code ?? '')), $address->district], fn ($v) => $v !== null && trim((string) $v) !== ''));
    }

    public function requireActiveCustomer(int $customerId): PartyInfo
    {
        $info = $this->customer($customerId) ?? throw MasterDataException::notFound('customer');

        return $info->isActive ? $info : throw MasterDataException::inactive('customer');
    }

    public function requireActiveSupplier(int $supplierId): PartyInfo
    {
        $info = $this->supplier($supplierId) ?? throw MasterDataException::notFound('supplier');

        return $info->isActive ? $info : throw MasterDataException::inactive('supplier');
    }
}
