<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Core\Contracts\CompanyInfo;
use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Modules\MasterData\DTOs\PartyInfo;
use App\Modules\Procurement\Exceptions\ProcurementException;
use Carbon\CarbonImmutable;

/** Shared reading of the header fields of purchasing documents: the supplier and business dates. */
final class DocumentHeader
{
    public function __construct(private readonly PartyDirectory $parties, private readonly CompanyInfo $company) {}

    /** @param  array<string, mixed>  $data  supplier (code) or supplier_id */
    public function supplier(array $data): PartyInfo
    {
        $supplier = isset($data['supplier_id']) && $data['supplier_id'] !== '' ? $this->parties->supplier((int) $data['supplier_id'])
            : (isset($data['supplier']) ? $this->parties->supplierByCode((string) $data['supplier']) : null);
        if ($supplier === null) {
            throw ProcurementException::make('procurement.supplier_required', 'Choose a supplier.', 422, 'supplier');
        }

        return $this->parties->requireActiveSupplier($supplier->id);
    }

    public function today(): string
    {
        return CarbonImmutable::now(config('core.display_timezone'))->toDateString();
    }

    /** @param  array<string, mixed>  $data */
    public function date(array $data, string $key, ?string $default = null, bool $required = true): ?string
    {
        $value = isset($data[$key]) ? trim((string) $data[$key]) : '';
        if ($value === '') {
            if ($required && $default === null) {
                throw ProcurementException::make("procurement.{$key}_required", 'Enter a date.', 422, $key);
            }

            return $default;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('core.display_timezone'));
        } catch (\Throwable) {
            $date = false;
        }
        if (! $date || $date->toDateString() !== $value) {
            throw ProcurementException::make("procurement.{$key}_invalid", 'Dates are written YYYY-MM-DD.', 422, $key);
        }

        return $value;
    }

    /** @return array{legal_name: string, trade_name: ?string, registration_no: ?string, tax_id: ?string, address: list<string>, phone: ?string, email: ?string, website: ?string} */
    public function company(): array
    {
        return $this->company->profile();
    }
}
