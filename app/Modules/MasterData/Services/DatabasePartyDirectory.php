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

        return $customer === null ? null : $this->customerInfo($customer);
    }

    public function supplier(int $supplierId): ?PartyInfo
    {
        $supplier = Supplier::query()->with(['currency', 'paymentTerms'])->find($supplierId);

        return $supplier === null ? null : $this->supplierInfo($supplier);
    }

    public function customers(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 1000) as $chunk) {
            foreach (Customer::query()->with(['currency', 'paymentTerms'])->whereIn('id', $chunk)->get() as $customer) {
                $out[$customer->id] = $this->customerInfo($customer);
            }
        }

        return $out;
    }

    public function suppliers(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 1000) as $chunk) {
            foreach (Supplier::query()->with(['currency', 'paymentTerms'])->whereIn('id', $chunk)->get() as $supplier) {
                $out[$supplier->id] = $this->supplierInfo($supplier);
            }
        }

        return $out;
    }

    private function customerInfo(Customer $customer): PartyInfo
    {
        return new PartyInfo(
            $customer->id, $customer->public_id, $customer->code, $customer->name, $customer->kind, $customer->tax_id,
            $customer->currency_id, $customer->currency?->code, $customer->paymentTerms?->days,
            $customer->credit_limit, $customer->price_list_id, $customer->is_active,
        );
    }

    private function supplierInfo(Supplier $supplier): PartyInfo
    {
        return new PartyInfo(
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
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($term))).'%';
        $match = fn ($q) => $q->whereRaw('lower(code) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like]);
        // One query with the currency and payment terms loaded together (not one lookup per party: a form listing 500 customers
        // used to make 500 queries).
        if ($side === 'payable' || $side === 'supplier') {
            return Supplier::query()->with(['currency', 'paymentTerms'])->where($match)->orderBy('code')->limit($limit)->get()
                ->map(fn (Supplier $s) => $this->supplierInfo($s))->values()->all();
        }

        return Customer::query()->with(['currency', 'paymentTerms'])->where($match)->orderBy('code')->limit($limit)->get()
            ->map(fn (Customer $c) => $this->customerInfo($c))->values()->all();
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
