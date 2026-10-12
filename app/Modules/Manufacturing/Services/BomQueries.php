<?php

namespace App\Modules\Manufacturing\Services;

use App\Modules\Manufacturing\Exceptions\ManufacturingException;
use App\Modules\Manufacturing\Models\BomComponent;
use App\Modules\Manufacturing\Models\BomVersion;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\Contracts\UomConverter;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Reading a bill of materials: which version applies, what a quantity of a product needs (one level or all the way down),
 * where a component is used, and whether a component list would make a product contain itself.
 *
 * Quantity rule: a component line says "{qty} {unit} for every {base_qty} of the product, plus {scrap_pct}% for loss".
 * What Q of the product needs is  qty x (base units per unit) x Q / base_qty x (1 + scrap_pct/100), worked out exactly and
 * rounded half-up once, to the component's base-unit decimals, only when it becomes a figure someone acts on.
 */
final class BomQueries
{
    public function __construct(private readonly ItemCatalog $items, private readonly UomConverter $units) {}

    public function activeFor(int $itemId): ?BomVersion
    {
        return BomVersion::query()->where('item_id', $itemId)->where('status', BomVersion::ACTIVE)->first();
    }

    /**
     * One level: what producing $qty of the version's product needs, per component, in the component's base unit.
     *
     * @return list<array{line_no: int, item_id: int, qty: string, exact: string}>
     */
    public function requirements(BomVersion $version, string $qty): array
    {
        $rows = [];
        foreach ($version->components as $c) {
            $exact = $this->exactNeed($version, $c, $qty);
            $info = $this->items->find($c->component_item_id);
            $rows[] = [
                'line_no' => $c->line_no, 'item_id' => $c->component_item_id,
                'qty' => Decimal::round($exact, $info->baseUomDecimals ?? 6), 'exact' => $exact,
            ];
        }

        return $rows;
    }

    /** Component need for $qty of the product, unrounded (12 decimals), in the component's base unit. */
    public function exactNeed(BomVersion $version, BomComponent $c, string $qty): string
    {
        $perBatch = Decimal::mul((string) $c->qty, $this->units->factor($c->component_item_id, $c->uom_id));
        $scaled = Decimal::div(Decimal::mul($perBatch, $qty), (string) $version->base_qty);

        return Decimal::mul($scaled, Decimal::add('1', Decimal::div((string) $c->scrap_pct, '100')));
    }

    /**
     * The indented bill: every component, and under any component that has its own active bill, that bill's components,
     * and so on. Quantities are what is needed for $qty of the top product (unrounded, 6 decimals, base units).
     * A component without an active bill is a leaf (bought, or made elsewhere).
     *
     * @return list<array{level: int, item_id: int, qty: string, version_no: int|null, bom_version_id: int|null, leaf: bool}>
     */
    public function explode(int $itemId, string $qty, ?BomVersion $version = null): array
    {
        $version ??= $this->activeFor($itemId);
        if ($version === null) {
            throw ManufacturingException::make('manufacturing.no_active_bom', 'This item has no active bill of materials.', 422, 'item');
        }
        $rows = [];
        $this->walk($version, $qty, 1, [$itemId], $rows);

        return $rows;
    }

    /**
     * What has to be bought (or made elsewhere): the leaves of the indented bill, added up per item.
     *
     * @param  list<array{level: int, item_id: int, qty: string, version_no: int|null, bom_version_id: int|null, leaf: bool}>  $rows
     * @return array<int, string> item id => base-unit quantity (6 decimals)
     */
    public function leafTotals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $r) {
            if ($r['leaf']) {
                $totals[$r['item_id']] = Decimal::add($totals[$r['item_id']] ?? '0', $r['qty']);
            }
        }
        ksort($totals);

        return array_map(fn (string $q) => Decimal::round($q, 6), $totals);
    }

    /**
     * @param  list<int>  $path  items above this point (cycle guard)
     * @param  list<array<string, mixed>>  $rows
     */
    private function walk(BomVersion $version, string $qty, int $level, array $path, array &$rows): void
    {
        if ($level > (int) config('manufacturing.max_bom_depth')) {
            throw ManufacturingException::make('manufacturing.bom_too_deep', 'This bill of materials goes deeper than '.config('manufacturing.max_bom_depth').' levels. Check it for a loop.', 422);
        }
        foreach ($version->components as $c) {
            $need = Decimal::round($this->exactNeed($version, $c, $qty), 6);
            $child = in_array($c->component_item_id, $path, true) ? null : $this->activeFor($c->component_item_id);
            if (in_array($c->component_item_id, $path, true)) {
                throw ManufacturingException::make('manufacturing.bom_loop', 'A product cannot contain itself, directly or through its components.', 422);
            }
            $rows[] = ['level' => $level, 'item_id' => $c->component_item_id, 'qty' => $need, 'version_no' => $child?->version_no, 'bom_version_id' => $child?->id, 'leaf' => $child === null];
            if ($child !== null) {
                $this->walk($child, $need, $level + 1, [...$path, $c->component_item_id], $rows);
            }
        }
    }

    /**
     * Would giving $itemId these components make it (indirectly) contain itself? Follows each component's active bill.
     *
     * @param  list<int>  $componentIds
     */
    public function wouldLoop(int $itemId, array $componentIds): bool
    {
        $seen = [];
        $stack = $componentIds;
        while ($stack !== []) {
            $current = array_pop($stack);
            if ($current === $itemId) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            $active = $this->activeFor($current);
            if ($active !== null) {
                foreach ($active->components as $c) {
                    $stack[] = $c->component_item_id;
                }
            }
        }

        return false;
    }

    /**
     * The products whose active bill uses this item.
     *
     * @return list<array{item_id: int, bom_version_id: int, version_no: int, qty: string, uom_id: int}>
     */
    public function whereUsed(int $itemId): array
    {
        return DB::table('bom_components')
            ->join('bom_versions', 'bom_versions.id', '=', 'bom_components.bom_version_id')
            ->where('bom_components.component_item_id', $itemId)->where('bom_versions.status', BomVersion::ACTIVE)
            ->orderBy('bom_versions.item_id')
            ->get(['bom_versions.item_id as item_id', 'bom_versions.id as bom_version_id', 'bom_versions.version_no as version_no', 'bom_components.qty as qty', 'bom_components.uom_id as uom_id'])
            ->map(fn ($r) => ['item_id' => (int) $r->item_id, 'bom_version_id' => (int) $r->bom_version_id, 'version_no' => (int) $r->version_no, 'qty' => (string) $r->qty, 'uom_id' => (int) $r->uom_id])
            ->all();
    }
}
