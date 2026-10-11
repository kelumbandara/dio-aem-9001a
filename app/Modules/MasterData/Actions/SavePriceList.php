<?php

namespace App\Modules\MasterData\Actions;

use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\PriceListItem;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Services\CodeGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Price list header. The prices themselves change through {@see RequestPriceChanges}, which can be put
 * behind an approval policy.
 */
final class SavePriceList
{
    public function __construct(
        private readonly CodeGenerator $codes,
        private readonly EventOutbox $outbox,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function __invoke(array $data, ?PriceList $list = null, ?int $expectedVersion = null): PriceList
    {
        return DB::transaction(function () use ($data, $list, $expectedVersion): PriceList {
            if ($list !== null) {
                $list = PriceList::query()->whereKey($list->id)->lockForUpdate()->firstOrFail();
                if ($expectedVersion !== null && $list->version !== $expectedVersion) {
                    throw MasterDataException::stale('price list');
                }
                if (isset($data['code']) && strtoupper(trim((string) $data['code'])) !== $list->code) {
                    throw MasterDataException::make('masterdata.code_locked', 'The code cannot be changed after creation.', 422, 'code');
                }
                if (isset($data['type']) && $data['type'] !== $list->type) {
                    throw MasterDataException::make('masterdata.price_list_type_locked', 'A price list\'s type cannot change.', 422, 'type');
                }
                if (isset($data['currency_id']) && (int) $data['currency_id'] !== $list->currency_id
                    && PriceListItem::query()->where('price_list_id', $list->id)->exists()) {
                    throw MasterDataException::make('masterdata.price_list_currency_locked', 'The currency cannot change once the list has prices.', 422, 'currency_id');
                }
            }

            $type = $list->type ?? $data['type'] ?? '';
            if (! in_array($type, [PriceList::SALES, PriceList::PURCHASE], true)) {
                throw MasterDataException::make('masterdata.price_list_type_invalid', 'Choose sales or purchase.', 422, 'type');
            }

            $currencyId = (int) ($data['currency_id'] ?? $list->currency_id ?? 0);
            if ($currencyId !== $list?->currency_id && ! Currency::query()->whereKey($currencyId)->where('is_active', true)->exists()) {
                throw MasterDataException::make('masterdata.reference_invalid', 'The currency does not exist or is inactive.', 422, 'currency_id');
            }

            $from = ($data['valid_from'] ?? null) ? CarbonImmutable::parse($data['valid_from'])->toDateString() : null;
            $to = ($data['valid_to'] ?? null) ? CarbonImmutable::parse($data['valid_to'])->toDateString() : null;
            if ($from !== null && $to !== null && $to < $from) {
                throw MasterDataException::make('masterdata.date_invalid', 'The end date cannot be before the start date.', 422, 'valid_to');
            }

            $isActive = $data['is_active'] ?? true;
            $isDefault = (bool) ($data['is_default'] ?? false);
            if ($isDefault && ! $isActive) {
                throw MasterDataException::make('masterdata.default_inactive', 'The default price list must be active.', 422, 'is_default');
            }

            $creating = $list === null;
            $code = $creating ? $this->resolveCode($data['code'] ?? null, $type) : $list->code;

            if ($isDefault) {
                PriceList::query()->where('type', $type)->where('is_default', true)->when($list, fn ($q) => $q->where('id', '<>', $list->id))->update(['is_default' => false]);
            }

            $list ??= new PriceList;
            $list->fill([
                'code' => $code,
                'name' => trim((string) $data['name']),
                'type' => $type,
                'currency_id' => $currencyId,
                'tax_inclusive' => $data['tax_inclusive'] ?? false,
                'is_default' => $isDefault,
                'valid_from' => $from,
                'valid_to' => $to,
                'is_active' => $isActive,
                'version' => $creating ? 1 : $list->version + 1,
            ])->save();

            if (! $isActive) {
                $this->assertNotInUse($list);
            }

            $this->outbox->record('masterdata.price_list.changed', ['price_list_id' => $list->id, 'code' => $list->code, 'created' => $creating], 'price_list', $list->id);

            return $list->load('currency');
        });
    }

    private function resolveCode(?string $given, string $type): string
    {
        $given = $given !== null ? strtoupper(trim($given)) : '';
        if ($given === '') {
            $prefix = $type === PriceList::SALES ? 'PLS' : 'PLP';

            return $this->codes->next("masterdata.price_list.{$type}", "{$prefix}-{SEQ}", fn (string $c) => PriceList::query()->where('code', $c)->exists());
        }
        if (PriceList::query()->where('code', $given)->exists()) {
            throw MasterDataException::make('masterdata.code_taken', 'This code is already used.', 422, 'code');
        }

        return $given;
    }

    private function assertNotInUse(PriceList $list): void
    {
        // Deactivating is allowed: the resolver skips inactive lists and falls back to the default list.
        // Still refuse to leave a customer or supplier pointing at a list that cannot be used.
        $users = Customer::query()->where('price_list_id', $list->id)->where('is_active', true)->count()
            + Supplier::query()->where('price_list_id', $list->id)->where('is_active', true)->count();

        if ($users > 0) {
            throw MasterDataException::make('masterdata.in_use', "{$users} active customer(s)/supplier(s) use this list; switch them to another list first.", 422, 'is_active');
        }
    }
}
