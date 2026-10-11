<?php

namespace App\Modules\Sales\Services;

use App\Modules\Core\Contracts\CompanyInfo;
use App\Modules\MasterData\Contracts\PartyDirectory;
use App\Modules\MasterData\DTOs\PartyInfo;
use App\Modules\Sales\Exceptions\SalesException;
use Carbon\CarbonImmutable;

/** Shared reading of the header fields of sales documents: the customer and business dates. */
final class DocumentHeader
{
    public function __construct(private readonly PartyDirectory $parties, private readonly CompanyInfo $company) {}

    /** @param  array<string, mixed>  $data  customer (code) or customer_id */
    public function customer(array $data): PartyInfo
    {
        $customer = isset($data['customer_id']) && $data['customer_id'] !== '' ? $this->parties->customer((int) $data['customer_id'])
            : (isset($data['customer']) ? $this->parties->customerByCode((string) $data['customer']) : null);
        if ($customer === null) {
            throw SalesException::make('sales.customer_required', 'Choose a customer.', 422, 'customer');
        }

        return $this->parties->requireActiveCustomer($customer->id);
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
                throw SalesException::make("sales.{$key}_required", 'Enter a date.', 422, $key);
            }

            return $default;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('core.display_timezone'));
        } catch (\Throwable) {
            $date = false;
        }
        if (! $date || $date->toDateString() !== $value) {
            throw SalesException::make("sales.{$key}_invalid", 'Dates are written YYYY-MM-DD.', 422, $key);
        }

        return $value;
    }

    /** @return array{legal_name: string, trade_name: ?string, registration_no: ?string, tax_id: ?string, address: list<string>, phone: ?string, email: ?string, website: ?string} */
    public function company(): array
    {
        return $this->company->profile();
    }
}
