<?php

namespace App\Modules\MasterData\Actions;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Services\CodeGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Shared rules for customers and suppliers: generated or given code (fixed afterwards), optimistic
 * versioning, reference checks, and id-based syncing of contacts and addresses.
 */
abstract class PartySaver
{
    public function __construct(
        protected readonly CodeGenerator $codes,
        protected readonly AuditLogger $audit,
        protected readonly EventOutbox $outbox,
    ) {}

    /** @return class-string<Customer|Supplier> */
    abstract protected function model(): string;

    /** @return class-string<Model> */
    abstract protected function contactModel(): string;

    /** @return class-string<Model> */
    abstract protected function addressModel(): string;

    /** Foreign key on contacts/addresses, e.g. customer_id. */
    abstract protected function foreignKey(): string;

    /** Short word used in codes, events and audit names: "customer" | "supplier". */
    abstract protected function kindName(): string;

    abstract protected function codeFormat(): string;

    /** Price list type that fits this party. */
    abstract protected function priceListType(): string;

    /**
     * Party-specific columns (credit limit, lead time…) validated and returned for filling.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    abstract protected function extraColumns(array $data): array;

    /**
     * @param  array<string, mixed>  $data  columns plus `contacts` and `addresses` lists (each row may carry `id`)
     */
    public function __invoke(array $data, Customer|Supplier|null $party = null, ?int $expectedVersion = null): Customer|Supplier
    {
        return DB::transaction(function () use ($data, $party, $expectedVersion): Customer|Supplier {
            $contacts = $data['contacts'] ?? null;
            $addresses = $data['addresses'] ?? null;
            unset($data['contacts'], $data['addresses']);

            $model = $this->model();
            if ($party !== null) {
                $party = $model::query()->whereKey($party->getKey())->lockForUpdate()->firstOrFail();
                if ($expectedVersion !== null && $party->version !== $expectedVersion) {
                    throw MasterDataException::stale($this->kindName());
                }
                if (isset($data['code']) && strtoupper(trim((string) $data['code'])) !== $party->code) {
                    throw MasterDataException::make('masterdata.code_locked', 'The code cannot be changed after creation.', 422, 'code');
                }
            }

            $kind = $data['kind'] ?? $party->kind ?? 'company';
            if (! in_array($kind, ['company', 'individual'], true)) {
                throw MasterDataException::make('masterdata.kind_invalid', 'Choose company or individual.', 422, 'kind');
            }

            $this->assertReference(Currency::class, $data['currency_id'] ?? null, 'currency_id', $party?->currency_id);
            $this->assertReference(PaymentTerm::class, $data['payment_terms_id'] ?? null, 'payment_terms_id', $party?->payment_terms_id);
            $this->assertPriceList($data['price_list_id'] ?? null, $party?->price_list_id);

            $creating = $party === null;
            $party ??= new $model;
            $party->fill([
                'code' => $creating ? $this->resolveCode($data['code'] ?? null) : $party->code,
                'name' => trim((string) $data['name']),
                'legal_name' => $data['legal_name'] ?? null,
                'kind' => $kind,
                'tax_id' => $this->clean($data['tax_id'] ?? null),
                'business_reg_no' => $this->clean($data['business_reg_no'] ?? null),
                'nic' => $kind === 'individual' ? $this->clean($data['nic'] ?? null) : null,
                'email' => $this->clean($data['email'] ?? null),
                'phone' => $this->clean($data['phone'] ?? null),
                'website' => $this->clean($data['website'] ?? null),
                'currency_id' => $data['currency_id'] ?? null,
                'payment_terms_id' => $data['payment_terms_id'] ?? null,
                'price_list_id' => $data['price_list_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'version' => $creating ? 1 : $party->version + 1,
                ...$this->extraColumns($data),
            ])->save();

            if ($contacts !== null) {
                $this->syncChildren($party, $this->contactModel(), $this->normaliseContacts($contacts), 'contacts');
            }
            if ($addresses !== null) {
                $this->syncChildren($party, $this->addressModel(), $this->normaliseAddresses($addresses), 'addresses');
            }

            $this->outbox->record("masterdata.{$this->kindName()}.changed", [
                $this->kindName().'_id' => $party->getKey(), 'code' => $party->code, 'created' => $creating, 'is_active' => $party->is_active,
            ], $this->kindName(), $party->getKey());

            return $party;
        });
    }

    private function resolveCode(?string $given): string
    {
        $model = $this->model();
        $given = $given !== null ? strtoupper(trim($given)) : '';

        if ($given === '') {
            return $this->codes->next("masterdata.{$this->kindName()}", $this->codeFormat(), fn (string $c) => $model::query()->where('code', $c)->exists());
        }
        if ($model::query()->where('code', $given)->exists()) {
            throw MasterDataException::make('masterdata.code_taken', 'This code is already used.', 422, 'code');
        }

        return $given;
    }

    private function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** @param  class-string<Model>  $model */
    private function assertReference(string $model, mixed $id, string $field, ?int $unchanged): void
    {
        if ($id === null || (int) $id === $unchanged) {
            return;
        }
        if (! $model::query()->whereKey((int) $id)->where('is_active', true)->exists()) {
            throw MasterDataException::make('masterdata.reference_invalid', 'The selected value does not exist or is inactive.', 422, $field);
        }
    }

    private function assertPriceList(mixed $id, ?int $unchanged): void
    {
        if ($id === null || (int) $id === $unchanged) {
            return;
        }
        if (! PriceList::query()->whereKey((int) $id)->where('is_active', true)->where('type', $this->priceListType())->exists()) {
            throw MasterDataException::make('masterdata.reference_invalid', "Choose an active {$this->priceListType()} price list.", 422, 'price_list_id');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function normaliseContacts(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => trim((string) ($r['name'] ?? '')) !== ''));
        $primary = null;
        foreach ($rows as $i => $row) {
            if (! empty($row['is_primary']) && $primary === null) {
                $primary = $i;
            }
        }
        $primary ??= $rows === [] ? null : 0;

        return array_map(fn ($row, $i) => [
            'id' => $row['id'] ?? null,
            'name' => trim($row['name']),
            'job_title' => $this->clean($row['job_title'] ?? null),
            'email' => $this->clean($row['email'] ?? null),
            'phone' => $this->clean($row['phone'] ?? null),
            'is_primary' => $i === $primary,
        ], $rows, array_keys($rows));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function normaliseAddresses(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => trim((string) ($r['line1'] ?? '')) !== ''));
        $default = null;
        foreach ($rows as $i => $row) {
            if (! empty($row['is_default']) && $default === null) {
                $default = $i;
            }
        }
        $default ??= $rows === [] ? null : 0;

        return array_map(function ($row, $i) use ($default) {
            $type = $row['type'] ?? 'billing';
            if (! in_array($type, ['billing', 'shipping', 'other'], true)) {
                throw MasterDataException::make('masterdata.address_type_invalid', 'Unknown address type.', 422, 'addresses');
            }
            $country = strtoupper(trim((string) ($row['country'] ?? 'LK')));
            if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                throw MasterDataException::make('masterdata.country_invalid', 'Use the 2-letter country code.', 422, 'addresses');
            }

            return [
                'id' => $row['id'] ?? null,
                'type' => $type,
                'line1' => trim($row['line1']),
                'line2' => $this->clean($row['line2'] ?? null),
                'city' => $this->clean($row['city'] ?? null),
                'district' => $this->clean($row['district'] ?? null),
                'postal_code' => $this->clean($row['postal_code'] ?? null),
                'country' => $country,
                'is_default' => $i === $default,
            ];
        }, $rows, array_keys($rows));
    }

    /**
     * Update rows that carry an id belonging to this party, create the rest, delete what was left out.
     *
     * @param  class-string<Model>  $childModel
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncChildren(Model $party, string $childModel, array $rows, string $label): void
    {
        $fk = $this->foreignKey();
        $existing = $childModel::query()->where($fk, $party->getKey())->get()->keyBy('id');
        $keep = [];
        $changes = 0;

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            unset($row['id']);

            if ($id !== null && $existing->has((int) $id)) {
                $child = $existing->get((int) $id);
                $child->fill($row);
                if ($child->isDirty()) {
                    $child->save();
                    $changes++;
                }
                $keep[] = (int) $id;
            } else {
                $child = $childModel::query()->create([$fk => $party->getKey(), ...$row]);
                $keep[] = $child->getKey();
                $changes++;
            }
        }

        foreach ($existing as $id => $child) {
            if (! in_array($id, $keep, true)) {
                $child->delete();
                $changes++;
            }
        }

        if ($changes > 0) {
            $this->audit->record("{$this->kindName()}.{$label}_changed", $party, [], [], ['count' => count($rows), 'changes' => $changes]);
        }
    }
}
