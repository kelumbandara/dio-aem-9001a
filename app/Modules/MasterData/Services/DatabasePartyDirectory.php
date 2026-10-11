<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Modules\MasterData\DTOs\PartyInfo;
use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Supplier;

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
