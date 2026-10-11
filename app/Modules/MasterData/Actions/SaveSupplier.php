<?php

namespace App\Modules\MasterData\Actions;

use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Models\SupplierAddress;
use App\Modules\MasterData\Models\SupplierContact;

/** Bank accounts are not part of this action: they change through the approval-aware bank account flow. */
final class SaveSupplier extends PartySaver
{
    protected function model(): string
    {
        return Supplier::class;
    }

    protected function contactModel(): string
    {
        return SupplierContact::class;
    }

    protected function addressModel(): string
    {
        return SupplierAddress::class;
    }

    protected function foreignKey(): string
    {
        return 'supplier_id';
    }

    protected function kindName(): string
    {
        return 'supplier';
    }

    protected function codeFormat(): string
    {
        return 'SUP-{SEQ}';
    }

    protected function priceListType(): string
    {
        return PriceList::PURCHASE;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extraColumns(array $data): array
    {
        $days = $data['lead_time_days'] ?? null;
        $days = $days === '' ? null : $days;

        if ($days !== null && (! is_numeric($days) || (int) $days < 0 || (int) $days > 3650)) {
            throw MasterDataException::make('masterdata.lead_time_invalid', 'Lead time must be between 0 and 3650 days.', 422, 'lead_time_days');
        }

        return ['lead_time_days' => $days !== null ? (int) $days : null];
    }
}
