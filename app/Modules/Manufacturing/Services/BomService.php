<?php

namespace App\Modules\Manufacturing\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Manufacturing\Exceptions\ManufacturingException;
use App\Modules\Manufacturing\Models\BomVersion;
use App\Modules\Manufacturing\Models\Machine;
use App\Modules\Manufacturing\Models\WorkCenter;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\DTOs\ItemInfo;
use App\Support\Decimal;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Bills of materials with their routing, in versions. A version is a draft while it is being written, becomes the one
 * ACTIVE version of its product when activated (the previous active version is retired in the same step) and is never
 * edited again: a change is a new version copied from it. Production orders remember the version they were made with.
 */
final class BomService
{
    private const MAX_COMPONENTS = 200;

    private const MAX_OPERATIONS = 50;

    public function __construct(
        private readonly ItemCatalog $items,
        private readonly BomQueries $queries,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
    ) {}

    /**
     * Create the product's first draft, or edit its open draft.
     *
     * @param  array<string, mixed>  $data  item|item_id (new only), base_qty?, description?, components: [{item|item_id, qty, uom?, scrap_pct?, notes?}], operations?: [{op_no?, name, work_center, machine?, setup_minutes?, run_minutes?, notes?}], version? (edit)
     *
     * @throws BusinessException
     */
    public function save(array $data, ?BomVersion $draft = null, ?int $by = null): BomVersion
    {
        return DB::transaction(function () use ($data, $draft, $by): BomVersion {
            if ($draft !== null) {
                $draft = BomVersion::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
                if ($draft->status !== BomVersion::DRAFT) {
                    throw ManufacturingException::state('BOM version', $draft->status, 'a draft');
                }
                if (isset($data['version']) && (int) $data['version'] !== $draft->version) {
                    throw ManufacturingException::stale('BOM version');
                }
                $product = $this->items->requireActive($draft->item_id);
            } else {
                $product = $this->product($data);
                $this->serialise($product->id);   // one writer per product
                if (BomVersion::query()->where('item_id', $product->id)->where('status', BomVersion::DRAFT)->exists()) {
                    throw ManufacturingException::make('manufacturing.draft_exists', "{$product->code} already has a draft version; finish or delete it first.", 422, 'item');
                }
            }

            $baseQty = $this->decimal($data['base_qty'] ?? ($draft->base_qty ?? '1'), 'base_qty', 6);
            $components = $this->components((array) ($data['components'] ?? []), $product);
            $operations = $this->operations((array) ($data['operations'] ?? []));
            $description = isset($data['description']) && trim((string) $data['description']) !== '' ? mb_substr(trim((string) $data['description']), 0, 255) : null;

            if ($draft === null) {
                $next = (int) BomVersion::query()->where('item_id', $product->id)->max('version_no') + 1;
                $draft = BomVersion::query()->create([
                    'item_id' => $product->id, 'version_no' => $next, 'status' => BomVersion::DRAFT, 'base_qty' => $baseQty,
                    'description' => $description, 'version' => 1, 'created_by' => $by,
                ]);
            } else {
                $draft->forceFill(['base_qty' => $baseQty, 'description' => $description, 'version' => $draft->version + 1])->save();
                $draft->components()->delete();
                $draft->operations()->delete();
            }
            foreach ($components as $row) {
                $draft->components()->create($row);
            }
            foreach ($operations as $row) {
                $draft->operations()->create($row);
            }

            $this->audit->record('manufacturing.bom_saved', $draft, [], ['item' => $product->code, 'version_no' => $draft->version_no, 'components' => count($components), 'operations' => count($operations)]);

            return $draft->refresh();
        });
    }

    /** A new draft copied from any version (so a change to a live bill starts from what is running now). */
    public function copyToDraft(BomVersion $source, ?int $by = null): BomVersion
    {
        $data = [
            'item_id' => $source->item_id, 'base_qty' => (string) $source->base_qty, 'description' => $source->description,
            'components' => $source->components->map(fn ($c) => ['item_id' => $c->component_item_id, 'qty' => (string) $c->qty, 'uom_id' => $c->uom_id, 'scrap_pct' => (string) $c->scrap_pct, 'notes' => $c->notes])->all(),
            'operations' => $source->operations->map(fn ($o) => ['op_no' => $o->op_no, 'name' => $o->name, 'work_center_id' => $o->work_center_id, 'machine_id' => $o->machine_id, 'setup_minutes' => (string) $o->setup_minutes, 'run_minutes' => (string) $o->run_minutes, 'notes' => $o->notes])->all(),
        ];

        return $this->save($data, null, $by);
    }

    /**
     * Make a draft the product's active version; the version that was active (if any) is retired in the same step.
     *
     * @throws BusinessException
     */
    public function activate(BomVersion $version, ?int $by = null): BomVersion
    {
        return DB::transaction(function () use ($version, $by): BomVersion {
            $this->serialise($version->item_id);
            $locked = BomVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== BomVersion::DRAFT) {
                throw ManufacturingException::state('BOM version', $locked->status, 'a draft');
            }
            $product = $this->items->requireActive($locked->item_id);
            $ids = $locked->components->pluck('component_item_id')->all();
            if ($ids === []) {
                throw ManufacturingException::make('manufacturing.bom_empty', 'A bill of materials needs at least one component.', 422, 'components');
            }
            foreach ($this->items->findMany($ids) as $component) {
                if (! $component->isActive || ! $component->isStocked()) {
                    throw ManufacturingException::make('manufacturing.component_invalid', "{$component->code} is no longer an active stock item.", 422, 'components');
                }
            }
            if ($this->queries->wouldLoop($product->id, $ids)) {
                throw ManufacturingException::make('manufacturing.bom_loop', "{$product->code} would end up containing itself through its components.", 422, 'components');
            }

            $today = $this->today();
            $previous = BomVersion::query()->where('item_id', $locked->item_id)->where('status', BomVersion::ACTIVE)->lockForUpdate()->first();
            if ($previous !== null) {
                $previous->forceFill(['status' => BomVersion::RETIRED, 'effective_to' => $today->toDateString()])->save();
            }
            $locked->forceFill([
                'status' => BomVersion::ACTIVE, 'effective_from' => $today->toDateString(), 'activated_at' => now(), 'activated_by' => $by, 'version' => $locked->version + 1,
            ])->save();

            $this->audit->record('manufacturing.bom_activated', $locked, ['retired_version' => $previous?->version_no], ['item' => $product->code, 'version_no' => $locked->version_no]);
            $this->outbox->record('manufacturing.bom.activated', ['bom_version_id' => $locked->id, 'item_id' => $locked->item_id, 'version_no' => $locked->version_no, 'retired_version_no' => $previous?->version_no], 'bom_version', $locked->id);

            return $locked->refresh();
        });
    }

    /** Stop using the active version without replacing it (new production orders for the product then cannot be made). */
    public function retire(BomVersion $version, ?int $by = null): BomVersion
    {
        return DB::transaction(function () use ($version): BomVersion {
            $locked = BomVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== BomVersion::ACTIVE) {
                throw ManufacturingException::state('BOM version', $locked->status, 'active');
            }
            $locked->forceFill(['status' => BomVersion::RETIRED, 'effective_to' => $this->today()->toDateString(), 'version' => $locked->version + 1])->save();
            $this->audit->record('manufacturing.bom_retired', $locked, [], ['version_no' => $locked->version_no]);
            $this->outbox->record('manufacturing.bom.retired', ['bom_version_id' => $locked->id, 'item_id' => $locked->item_id, 'version_no' => $locked->version_no], 'bom_version', $locked->id);

            return $locked->refresh();
        });
    }

    public function deleteDraft(BomVersion $version): void
    {
        DB::transaction(function () use ($version): void {
            $locked = BomVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== BomVersion::DRAFT) {
                throw ManufacturingException::state('BOM version', $locked->status, 'a draft');
            }
            $this->audit->record('manufacturing.bom_draft_deleted', $locked, [], ['version_no' => $locked->version_no]);
            $locked->delete();
        });
    }

    // ------------------------------------------------------------------ validation

    /** @param  array<string, mixed>  $data */
    private function product(array $data): ItemInfo
    {
        $info = $this->item($data, 'item');
        if (! $info->isStocked()) {
            throw ManufacturingException::make('manufacturing.item_not_stocked', "{$info->code} is not a stock item, so it cannot be made.", 422, 'item');
        }

        return $info;
    }

    /** @param  array<string, mixed>  $data */
    private function item(array $data, string $field): ItemInfo
    {
        if (isset($data['item_id'])) {
            return $this->items->requireActive((int) $data['item_id']);
        }
        $code = trim((string) ($data['item'] ?? ''));
        $info = $code === '' ? null : $this->items->findByCode($code);
        if ($info === null) {
            throw ManufacturingException::make('manufacturing.item_not_found', $code === '' ? 'Choose an item.' : "There is no item {$code}.", 422, $field);
        }

        return $this->items->requireActive($info->id);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function components(array $lines, ItemInfo $product): array
    {
        if ($lines === [] || count($lines) > self::MAX_COMPONENTS) {
            throw ManufacturingException::make('manufacturing.components_invalid', 'A bill of materials needs between 1 and '.self::MAX_COMPONENTS.' components.', 422, 'components');
        }
        $rows = [];
        $seen = [];
        foreach (array_values($lines) as $i => $line) {
            $field = "components.{$i}";
            $info = $this->item($line, "{$field}.item");
            if (! $info->isStocked()) {
                throw ManufacturingException::make('manufacturing.component_invalid', "{$info->code} is not a stock item and cannot be a component.", 422, "{$field}.item");
            }
            if ($info->id === $product->id) {
                throw ManufacturingException::make('manufacturing.component_self', 'A product cannot be a component of itself.', 422, "{$field}.item");
            }
            if (isset($seen[$info->id])) {
                throw ManufacturingException::make('manufacturing.component_duplicate', "{$info->code} is listed twice; add the quantities into one line.", 422, "{$field}.item");
            }
            $seen[$info->id] = true;

            $unit = $this->unit($info, $line, "{$field}.uom");
            $scrap = isset($line['scrap_pct']) && $line['scrap_pct'] !== '' ? (string) $line['scrap_pct'] : '0';
            if (! Decimal::isDecimal($scrap, 3) || Decimal::compare($scrap, '0') < 0 || Decimal::compare($scrap, '100') >= 0) {
                throw ManufacturingException::make('manufacturing.scrap_invalid', 'Scrap is a percentage from 0 up to (not including) 100, with at most 3 decimals.', 422, "{$field}.scrap_pct");
            }
            $rows[] = [
                'line_no' => $i + 1, 'component_item_id' => $info->id, 'qty' => $this->decimal($line['qty'] ?? null, "{$field}.qty", 6),
                'uom_id' => $unit, 'scrap_pct' => Decimal::round($scrap, 3), 'notes' => $this->text($line['notes'] ?? null),
            ];
        }
        if ($this->queries->wouldLoop($product->id, array_keys($seen))) {
            throw ManufacturingException::make('manufacturing.bom_loop', "{$product->code} would end up containing itself through its components.", 422, 'components');
        }

        return $rows;
    }

    /** @param  array<string, mixed>  $line */
    private function unit(ItemInfo $info, array $line, string $field): int
    {
        if (isset($line['uom_id'])) {
            foreach ($this->items->units($info->id) as $u) {
                if ($u->uomId === (int) $line['uom_id']) {
                    return $u->uomId;
                }
            }
        } else {
            $code = strtolower(trim((string) ($line['uom'] ?? '')));
            if ($code === '') {
                return $info->baseUomId;
            }
            foreach ($this->items->units($info->id) as $u) {
                if (strtolower($u->code) === $code) {
                    return $u->uomId;
                }
            }
        }

        throw ManufacturingException::make('manufacturing.uom_invalid', "{$info->code} cannot be measured in that unit.", 422, $field);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function operations(array $lines): array
    {
        if (count($lines) > self::MAX_OPERATIONS) {
            throw ManufacturingException::make('manufacturing.operations_invalid', 'At most '.self::MAX_OPERATIONS.' routing steps.', 422, 'operations');
        }
        $rows = [];
        $used = [];
        foreach (array_values($lines) as $i => $line) {
            $field = "operations.{$i}";
            $opNo = isset($line['op_no']) && $line['op_no'] !== '' ? (int) $line['op_no'] : ($i + 1) * 10;
            if ($opNo < 1 || $opNo > 65000 || isset($used[$opNo])) {
                throw ManufacturingException::make('manufacturing.op_no_invalid', 'Step numbers must be positive and different from each other (10, 20, 30 ...).', 422, "{$field}.op_no");
            }
            $used[$opNo] = true;
            $name = trim((string) ($line['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150) {
                throw ManufacturingException::make('manufacturing.op_name_required', 'Every routing step needs a name.', 422, "{$field}.name");
            }
            $center = isset($line['work_center_id'])
                ? WorkCenter::query()->find((int) $line['work_center_id'])
                : WorkCenter::query()->whereRaw('lower(code) = ?', [strtolower(trim((string) ($line['work_center'] ?? '')))])->first();
            if ($center === null || ! $center->is_active) {
                throw ManufacturingException::make('manufacturing.work_center_invalid', 'Choose an active work centre for each routing step.', 422, "{$field}.work_center");
            }
            $machine = null;
            $machineId = isset($line['machine_id']) && $line['machine_id'] !== '' ? (int) $line['machine_id'] : null;
            $machineCode = trim((string) ($line['machine'] ?? ''));
            if ($machineId !== null || $machineCode !== '') {
                $machine = $machineId !== null
                    ? Machine::query()->find($machineId)
                    : Machine::query()->whereRaw('lower(code) = ?', [strtolower($machineCode)])->first();
                if ($machine === null || $machine->work_center_id !== $center->id || $machine->status === 'retired') {
                    throw ManufacturingException::make('manufacturing.machine_invalid', 'The machine must belong to the step\'s work centre and not be retired.', 422, "{$field}.machine");
                }
            }
            $rows[] = [
                'op_no' => $opNo, 'name' => $name, 'work_center_id' => $center->id, 'machine_id' => $machine?->id,
                'setup_minutes' => $this->minutes($line['setup_minutes'] ?? '0', "{$field}.setup_minutes", 2),
                'run_minutes' => $this->minutes($line['run_minutes'] ?? '0', "{$field}.run_minutes", 4), 'notes' => $this->text($line['notes'] ?? null),
            ];
        }
        usort($rows, fn (array $a, array $b) => $a['op_no'] <=> $b['op_no']);

        return $rows;
    }

    private function decimal(mixed $value, string $field, int $places): string
    {
        $v = trim((string) $value);
        if ($v === '' || ! Decimal::isDecimal($v, $places) || Decimal::compare($v, '0') <= 0) {
            throw ManufacturingException::make('manufacturing.qty_invalid', "Enter a quantity above zero with at most {$places} decimals.", 422, $field);
        }

        return Decimal::round($v, $places);
    }

    private function minutes(mixed $value, string $field, int $places): string
    {
        $v = trim((string) $value);
        $v = $v === '' ? '0' : $v;
        if (! Decimal::isDecimal($v, $places) || Decimal::compare($v, '0') < 0) {
            throw ManufacturingException::make('manufacturing.minutes_invalid', "Minutes must be zero or more, with at most {$places} decimals.", 422, $field);
        }

        return Decimal::round($v, $places);
    }

    private function text(mixed $value): ?string
    {
        $v = trim((string) $value);

        return $v === '' ? null : mb_substr($v, 0, 255);
    }

    /** Everything that creates or activates versions of one product waits its turn (released when the transaction ends). */
    private function serialise(int $itemId): void
    {
        DB::select('select pg_advisory_xact_lock(?, ?)', [7001, $itemId]);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('core.display_timezone'))->startOfDay();
    }
}
