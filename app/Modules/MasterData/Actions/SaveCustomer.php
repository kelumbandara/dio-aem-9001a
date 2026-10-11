<?php

namespace App\Modules\MasterData\Actions;

use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\CustomerAddress;
use App\Modules\MasterData\Models\CustomerContact;
use App\Modules\MasterData\Models\PriceList;

final class SaveCustomer extends PartySaver
{
    protected function model(): string
    {
        return Customer::class;
    }

    protected function contactModel(): string
    {
        return CustomerContact::class;
    }

    protected function addressModel(): string
    {
        return CustomerAddress::class;
    }

    protected function foreignKey(): string
    {
        return 'customer_id';
    }

    protected function kindName(): string
    {
        return 'customer';
    }

    protected function codeFormat(): string
    {
        return 'CUS-{SEQ}';
    }

    protected function priceListType(): string
    {
        return PriceList::SALES;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extraColumns(array $data): array
    {
        $limit = $data['credit_limit'] ?? null;
        $limit = $limit === '' ? null : $limit;

        if ($limit !== null && (preg_match('/^\d{1,14}(\.\d{1,4})?$/', (string) $limit) !== 1)) {
            throw MasterDataException::make('masterdata.credit_limit_invalid', 'The credit limit must be zero or a positive amount.', 422, 'credit_limit');
        }

        return ['credit_limit' => $limit !== null ? (string) $limit : null];
    }
}
