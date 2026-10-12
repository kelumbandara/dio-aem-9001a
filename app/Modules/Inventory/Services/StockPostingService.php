<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Core\Contracts\NumberingService;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\DTOs\PostedMovement;
use App\Modules\Inventory\DTOs\StockMovement;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\ItemCost;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Lot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Models\StockDocumentLine;
use App\Modules\Inventory\Models\StockLedgerEntry;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Modules\MasterData\Contracts\UomConverter;
use App\Modules\MasterData\DTOs\ItemInfo;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The single place where stock and its value change (docs/architecture/inventory.md).
 *
 * Everything for one movement happens in one database transaction: the document and its lines, the ledger rows,
 * the balances and the item's weighted-average cost. Concurrency: every item touched is locked first (its
 * `item_costs` row, in item-id order, so two movements never wait on each other in a circle); after that, nobody
 * else can change that item's balances, so quantities read afterwards are the truth for the rest of the transaction.
 *
 * Costing: stock in adds its value; stock out leaves at the current average (value / quantity), except that the
 * last unit takes whatever value is left so no rounding residue ever stays behind; a transfer moves stock at
 * average cost so the item's total value does not change; a reversal restores the exact quantities and values of
 * the original, never a recalculated cost.
 */
final class StockPostingService implements StockPoster
{
    public const REASONS = [
        StockDocument::RECEIPT => ['purchase', 'production', 'return', 'opening', 'other'],
        StockDocument::ISSUE => ['sale', 'production', 'sample', 'scrap', 'supplier_return', 'other'],
        StockDocument::TRANSFER => ['transfer'],
        StockDocument::ADJUSTMENT => ['count', 'damage', 'expiry', 'loss', 'found', 'other'],
    ];

    private const MAX_LINES = 200;

    /** @var array<int, int> location id => warehouse id */
    private array $warehouseByLocation = [];

    private const PREFIX = [
        StockDocument::RECEIPT => 'SRC', StockDocument::ISSUE => 'SIS', StockDocument::TRANSFER => 'STR', StockDocument::ADJUSTMENT => 'SAD',
    ];

    public function __construct(
        private readonly ItemCatalog $items,
        private readonly UomConverter $uoms,
        private readonly NumberingService $numbering,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
        private readonly LocationResolver $locations,
        private readonly ReorderMonitor $reorder,
    ) {}

    public function post(StockMovement $movement): PostedMovement
    {
        return DB::transaction(fn () => $this->run($movement));
    }

    public function check(StockMovement $movement): PostedMovement
    {
        DB::beginTransaction();

        try {
            return $this->run($movement);
        } finally {
            DB::rollBack();
        }
    }

    public function reverse(int $documentId, ?string $note = null, ?int $by = null): PostedMovement
    {
        return DB::transaction(function () use ($documentId, $note, $by): PostedMovement {
            $original = StockDocument::query()->whereKey($documentId)->lockForUpdate()->first() ?? throw InventoryException::notFound('document');
            if ($original->reverses_id !== null) {
                throw InventoryException::make('inventory.cannot_reverse_reversal', 'A reversal cannot itself be reversed; post a new movement instead.');
            }
            if ($original->status === 'reversed') {
                throw InventoryException::make('inventory.already_reversed', "{$original->document_no} has already been reversed.", 409);
            }

            $lines = StockDocumentLine::query()->where('document_id', $original->id)->orderBy('line_no')->get();
            $entries = StockLedgerEntry::query()->where('document_id', $original->id)->orderBy('id')->get()->groupBy('line_id');
            $itemIds = $lines->pluck('item_id')->unique()->sort()->values()->all();

            $state = $this->lockItems($itemIds);
            $before = $this->reorder->snapshot($itemIds);
            $date = $this->today();

            // Undo in the opposite order of the original, so a transfer's stock is never taken before it was put back.
            $slices = [];
            foreach ($lines->reverse()->values() as $i => $line) {
                $ops = [];
                foreach ($entries->get($line->id, collect())->reverse() as $e) {
                    $removing = Decimal::compare($e->qty, '0') > 0;   // the original added stock, so undoing removes it
                    $abs = ltrim($e->qty, '-');
                    $value = ltrim($e->value, '-');
                    $location = Location::query()->find($e->location_id);
                    $op = $removing
                        ? $this->applyOut($state, $e->item_id, $e->location_id, $e->lot_id, $abs, $value, "lines.{$i}", $location, true)
                        : $this->applyIn($state, $e->item_id, $e->location_id, $e->lot_id, $abs, $value);
                    $op['reverses'] = $e->id;
                    $ops[] = $op;
                }
                $slices[] = [
                    'item' => $line->item_id, 'uomId' => $line->uom_id, 'uomCode' => $line->uom_code, 'qty' => $line->qty, 'base' => $line->base_qty,
                    'from' => $line->to_location_id, 'to' => $line->from_location_id, 'lotId' => $line->lot_id, 'unitCost' => $line->unit_cost,
                    'note' => $line->note, 'value' => $line->value, 'ops' => $ops,
                ];
            }

            $this->assertNothingReservedAway($state);
            $posted = $this->persist($state, $slices, [
                'type' => $original->doc_type, 'date' => $date, 'reason' => 'reversal', 'reference' => "Reversal of {$original->document_no}",
                'notes' => $note, 'sourceType' => null, 'sourceId' => null, 'by' => $by, 'reverses' => $original->id,
                'originalType' => $original->doc_type, 'originalReason' => $original->reason,
            ], $itemIds, $before);

            $original->forceFill(['status' => 'reversed', 'reversed_by_id' => $posted->documentId])->save();
            $this->audit->record('inventory.stock_reversed', $original, [], ['reversed_by' => $posted->documentNo, 'note' => $note]);

            return $posted;
        });
    }

    private function run(StockMovement $m): PostedMovement
    {
        $date = $this->validateHeader($m);

        if ($m->sourceType !== null && $m->sourceId !== null) {
            $existing = StockDocument::query()->where('source_type', $m->sourceType)->where('source_id', $m->sourceId)->where('doc_type', $m->type)->first();
            if ($existing !== null) {
                return new PostedMovement($existing->id, $existing->public_id, $existing->document_no, $existing->total_value, $existing->lines()->count(), true);
            }
        }

        $prepared = $this->prepare($m, $date);
        $itemIds = collect($prepared)->pluck('item.id')->unique()->sort()->values()->all();

        $state = $this->lockItems($itemIds);
        $before = $this->reorder->snapshot($itemIds);

        $slices = [];
        foreach ($prepared as $line) {
            array_push($slices, ...$this->applyLine($state, $line, $m->type, $date));
        }

        $this->consumeReservations($state, $prepared, $slices);
        $this->assertNothingReservedAway($state);

        return $this->persist($state, $slices, [
            'type' => $m->type, 'date' => $date, 'reason' => $m->reason, 'reference' => $m->reference, 'notes' => $m->notes,
            'sourceType' => $m->sourceType, 'sourceId' => $m->sourceId, 'by' => $m->createdBy, 'reverses' => null,
        ], $itemIds, $before);
    }

    // ---------------------------------------------------------------- validation and preparation

    private function validateHeader(StockMovement $m): CarbonImmutable
    {
        if (! in_array($m->type, StockDocument::TYPES, true)) {
            throw InventoryException::make('inventory.type_invalid', 'Unknown movement type.', 422, 'type');
        }
        if (! in_array($m->reason, self::REASONS[$m->type], true)) {
            throw InventoryException::make('inventory.reason_invalid', 'Choose one of: '.implode(', ', self::REASONS[$m->type]).'.', 422, 'reason');
        }
        if ($m->lines === [] || count($m->lines) > self::MAX_LINES) {
            throw InventoryException::make('inventory.lines_invalid', 'A movement needs between 1 and '.self::MAX_LINES.' lines.', 422, 'lines');
        }

        $tz = (string) config('core.display_timezone');
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $m->postingDate, $tz);
        } catch (\Throwable) {
            $date = null;
        }
        if ($date === null || $date->format('Y-m-d') !== $m->postingDate || $date->year < 2000) {
            throw InventoryException::make('inventory.date_invalid', 'Enter a valid posting date (YYYY-MM-DD).', 422, 'posting_date');
        }
        if ($date->gt($this->today())) {
            throw InventoryException::make('inventory.date_in_future', 'Stock cannot be posted with a future date.', 422, 'posting_date');
        }

        return $date;
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('core.display_timezone'))->startOfDay();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function prepare(StockMovement $m, CarbonImmutable $date): array
    {
        $out = [];

        foreach ($m->lines as $i => $l) {
            $field = "lines.{$i}";
            $item = $this->items->requireActive($l->itemId);
            if (! $item->isStocked()) {
                throw InventoryException::make('inventory.item_not_stocked', "{$item->code} is not a stock item, so it has no stock.", 422, "{$field}.item");
            }

            $unit = collect($this->items->units($item->id))->firstWhere('uomId', $l->uomId ?? $item->baseUomId)
                ?? throw InventoryException::make('inventory.unit_invalid', "That unit is not defined for {$item->code}.", 422, "{$field}.uom");
            if (! Decimal::isDecimal($l->qty, $unit->decimals) || Decimal::compare($l->qty, '0') <= 0) {
                throw InventoryException::make('inventory.qty_invalid', "Enter a quantity above zero with at most {$unit->decimals} decimal place(s) for {$unit->code}.", 422, "{$field}.qty");
            }
            $base = $unit->isBase ? $l->qty : $this->uoms->toBase($l->qty, $item->id, $unit->uomId);
            if (Decimal::compare($base, '0') <= 0) {
                throw InventoryException::make('inventory.qty_invalid', 'That quantity is too small to count in the base unit.', 422, "{$field}.qty");
            }

            $dir = match ($m->type) {
                StockDocument::RECEIPT => 'in',
                StockDocument::ISSUE => 'out',
                StockDocument::TRANSFER => 'move',
                default => in_array($l->direction, ['in', 'out'], true) ? $l->direction
                    : throw InventoryException::make('inventory.direction_required', 'Say whether the adjustment adds (in) or removes (out) stock.', 422, "{$field}.direction"),
            };

            $from = $to = null;
            if ($dir === 'move') {
                $from = $this->locations->resolve($l->warehouseId, $l->locationId, "{$field}.from");
                $to = $this->locations->resolve($l->toWarehouseId, $l->toLocationId, "{$field}.to");
                if ($from->id === $to->id) {
                    throw InventoryException::make('inventory.same_location', 'A transfer needs two different places.', 422, "{$field}.to");
                }
            } elseif ($dir === 'in') {
                $to = $this->locations->resolve($l->warehouseId, $l->locationId, $field);
            } else {
                $from = $this->locations->resolve($l->warehouseId, $l->locationId, $field);
            }

            $lotNo = $l->lotNo !== null ? trim($l->lotNo) : null;
            $lotNo = $lotNo === '' ? null : $lotNo;
            if (! $item->tracksLots && ($lotNo !== null || $l->lotId !== null)) {
                throw InventoryException::make('inventory.lot_not_tracked', "{$item->code} is not tracked by lot.", 422, "{$field}.lot");
            }
            if ($item->tracksLots && $dir === 'in' && $lotNo === null && $l->lotId === null) {
                throw InventoryException::make('inventory.lot_required', "{$item->code} is tracked by lot: enter a lot number.", 422, "{$field}.lot");
            }
            if ($lotNo !== null && mb_strlen($lotNo) > 60) {
                throw InventoryException::make('inventory.lot_invalid', 'A lot number is at most 60 characters.', 422, "{$field}.lot");
            }
            if ($l->expiryDate !== null && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $l->expiryDate) || strtotime($l->expiryDate) === false)) {
                throw InventoryException::make('inventory.date_invalid', 'Enter a valid expiry date (YYYY-MM-DD).', 422, "{$field}.expiry_date");
            }

            $cost = $l->unitCost;
            if ($m->type === StockDocument::RECEIPT && $cost === null) {
                throw InventoryException::make('inventory.cost_required', "Enter the unit cost for {$item->code}.", 422, "{$field}.unit_cost");
            }
            if ($cost !== null && (! Decimal::isDecimal($cost, 6) || Decimal::compare($cost, '0') < 0)) {
                throw InventoryException::make('inventory.cost_invalid', 'The unit cost must be zero or more, with at most 6 decimals.', 422, "{$field}.unit_cost");
            }
            if ($l->reservationId !== null && $dir === 'in') {
                throw InventoryException::make('inventory.reservation_invalid', 'Only stock leaving a place can use a reservation.', 422, "{$field}.reservation");
            }

            $out[] = [
                'idx' => $i, 'field' => $field, 'item' => $item, 'unit' => $unit, 'qty' => $l->qty, 'base' => $base, 'dir' => $dir,
                'from' => $from, 'to' => $to, 'lotNo' => $lotNo, 'lotId' => $l->lotId, 'expiry' => $l->expiryDate, 'cost' => $cost,
                'note' => $l->note !== null ? mb_substr($l->note, 0, 255) : null, 'reservationId' => $l->reservationId,
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------------- locking and arithmetic

    /**
     * @param  list<int>  $itemIds  sorted ascending
     * @return array<string, mixed> working state: costs, balances, touched balance keys
     */
    private function lockItems(array $itemIds): array
    {
        foreach ($itemIds as $id) {
            DB::table('item_costs')->insertOrIgnore(['item_id' => $id, 'qty' => '0', 'value' => '0']);
        }
        $costs = ItemCost::query()->whereIn('item_id', $itemIds)->orderBy('item_id')->lockForUpdate()->get()
            ->mapWithKeys(fn (ItemCost $c) => [$c->item_id => ['qty' => $c->qty, 'value' => $c->value]])->all();

        return ['costs' => $costs, 'balances' => [], 'warehouseOf' => []];
    }

    private function balanceKey(int $itemId, int $locationId, ?int $lotId): string
    {
        return "{$itemId}:{$locationId}:".($lotId ?? 'x');
    }

    /**
     * Current on-hand of one balance, loaded once per movement.
     *
     * @param  array<string, mixed>  $state
     * @return array{id: ?int, on_hand: string, orig: string, item: int, loc: int, lot: ?int}
     */
    private function &balance(array &$state, int $itemId, int $locationId, ?int $lotId): array
    {
        $key = $this->balanceKey($itemId, $locationId, $lotId);
        if (! isset($state['balances'][$key])) {
            $row = StockBalance::query()->where('item_id', $itemId)->where('location_id', $locationId)
                ->when($lotId === null, fn ($q) => $q->whereNull('lot_id'), fn ($q) => $q->where('lot_id', $lotId))->first();
            $state['balances'][$key] = [
                'id' => $row?->id, 'on_hand' => $row !== null ? $row->on_hand : '0.000000', 'orig' => $row !== null ? $row->on_hand : '0.000000',
                'item' => $itemId, 'loc' => $locationId, 'lot' => $lotId,
            ];
        }

        return $state['balances'][$key];
    }

    /**
     * Take stock out. Without $exactValue it leaves at average cost; with it (reversals) at exactly that value.
     *
     * @param  array<string, mixed>  $state
     * @return array{loc: int, lot: ?int, qty: string, value: string}
     */
    private function applyOut(array &$state, int $itemId, int $locationId, ?int $lotId, string $base, ?string $exactValue, string $field, ?Location $location, bool $reversal = false): array
    {
        $bal = &$this->balance($state, $itemId, $locationId, $lotId);
        if (Decimal::compare($bal['on_hand'], $base) < 0) {
            $where = $location !== null ? ($location->is_default ? '' : " at {$location->code}") : '';
            throw InventoryException::make('inventory.insufficient_stock', "Not enough stock{$where}: need {$base}, have {$bal['on_hand']}".($reversal ? ' (the stock this document added has since been used).' : '.'), 422, $field);
        }

        $cost = &$state['costs'][$itemId];
        if ($exactValue !== null) {
            $value = $exactValue;
        } elseif (Decimal::compare($cost['qty'], $base) === 0) {
            $value = $cost['value'];   // the last of the item takes what is left: no rounding residue
        } else {
            $value = Decimal::round(Decimal::div(Decimal::mul($base, $cost['value']), $cost['qty']), 4);
            if (Decimal::compare($value, $cost['value'], 4) > 0) {
                $value = $cost['value'];
            }
        }

        $newQty = Decimal::sub($cost['qty'], $base);
        $newValue = Decimal::sub($cost['value'], $value);
        if (Decimal::compare($newValue, '0', 4) < 0 || (Decimal::compare($newQty, '0') === 0 && Decimal::compare($newValue, '0', 4) !== 0)) {
            throw InventoryException::make('inventory.reversal_blocked', 'This cannot be undone exactly because the item\'s cost has moved on since; correct it with an adjustment instead.', 422, $field);
        }

        $cost = ['qty' => $newQty, 'value' => $newValue];
        $bal['on_hand'] = Decimal::sub($bal['on_hand'], $base);

        return ['loc' => $locationId, 'lot' => $lotId, 'qty' => '-'.$base, 'value' => Decimal::compare($value, '0', 4) === 0 ? '0.0000' : '-'.$value];
    }

    /**
     * Put stock in, worth $value (LKR).
     *
     * @param  array<string, mixed>  $state
     * @return array{loc: int, lot: ?int, qty: string, value: string}
     */
    private function applyIn(array &$state, int $itemId, int $locationId, ?int $lotId, string $base, string $value): array
    {
        $bal = &$this->balance($state, $itemId, $locationId, $lotId);
        $bal['on_hand'] = Decimal::add($bal['on_hand'], $base);
        $cost = &$state['costs'][$itemId];
        $cost = ['qty' => Decimal::add($cost['qty'], $base), 'value' => Decimal::add($cost['value'], $value)];

        return ['loc' => $locationId, 'lot' => $lotId, 'qty' => $base, 'value' => Decimal::round($value, 4)];
    }

    /**
     * Apply one prepared line; a line may become several slices (lots chosen first-expiry-first-out).
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $line
     * @return list<array<string, mixed>>
     */
    private function applyLine(array &$state, array $line, string $type, CarbonImmutable $date): array
    {
        /** @var ItemInfo $item */
        $item = $line['item'];
        $unit = $line['unit'];
        $slices = [];

        if ($line['dir'] === 'in') {
            $lot = $item->tracksLots ? $this->lotForReceipt($item, $line, $date) : null;
            if ($line['cost'] !== null) {
                $value = Decimal::round(Decimal::mul($line['qty'], $line['cost']), 4);
            } else {
                $c = $state['costs'][$item->id];   // an adjustment that adds stock without a cost values it at the current average
                if (Decimal::compare($c['qty'], '0') <= 0) {
                    throw InventoryException::make('inventory.cost_required', "There is no cost for {$item->code} yet: enter the unit cost.", 422, "{$line['field']}.unit_cost");
                }
                $value = Decimal::round(Decimal::div(Decimal::mul($line['base'], $c['value']), $c['qty']), 4);
            }
            $op = $this->applyIn($state, $item->id, $line['to']->id, $lot?->id, $line['base'], $value);
            $slices[] = $this->slice($line, $line['qty'], $line['base'], null, $line['to']->id, $lot?->id, $op['value'], [$op], $line['cost']);

            return $slices;
        }

        // Stock leaving a place: pick the lots.
        $parts = $this->pickLots($state, $line, $type, $date);
        foreach ($parts as $part) {
            $out = $this->applyOut($state, $item->id, $line['from']->id, $part['lotId'], $part['base'], null, $line['field'], $line['from']);
            $ops = [$out];
            $value = ltrim($out['value'], '-');
            if ($line['dir'] === 'move') {
                $ops[] = $this->applyIn($state, $item->id, $line['to']->id, $part['lotId'], $part['base'], $value);
            }
            $single = count($parts) === 1;
            $slices[] = $this->slice($line, $single ? $line['qty'] : $part['base'], $part['base'], $line['from']->id, $line['dir'] === 'move' ? $line['to']->id : null, $part['lotId'], $value, $ops, null, $single ? null : true);
        }

        return $slices;
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  list<array<string, mixed>>  $ops
     * @return array<string, mixed>
     */
    private function slice(array $line, string $qty, string $base, ?int $from, ?int $to, ?int $lotId, string $value, array $ops, ?string $unitCost, ?bool $inBase = null): array
    {
        /** @var ItemInfo $item */
        $item = $line['item'];

        return [
            'item' => $item->id, 'uomId' => $inBase ? $item->baseUomId : $line['unit']->uomId, 'uomCode' => $inBase ? $item->baseUomCode : $line['unit']->code,
            'qty' => $qty, 'base' => $base, 'from' => $from, 'to' => $to, 'lotId' => $lotId, 'unitCost' => $unitCost,
            'note' => $line['note'], 'value' => $value, 'ops' => $ops, 'reservationId' => $line['reservationId'], 'lineIdx' => $line['idx'],
        ];
    }

    /**
     * Which lots a quantity leaves from: the one named, or (lot-tracked item, none named) the earliest-expiring first.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $line
     * @return list<array{lotId: ?int, base: string}>
     */
    private function pickLots(array &$state, array $line, string $type, CarbonImmutable $date): array
    {
        /** @var ItemInfo $item */
        $item = $line['item'];
        $locId = $line['from']->id;

        if (! $item->tracksLots) {
            return [['lotId' => null, 'base' => $line['base']]];
        }

        if ($line['lotId'] !== null || $line['lotNo'] !== null) {
            $lot = Lot::query()->where('item_id', $item->id)
                ->when($line['lotId'] !== null, fn ($q) => $q->whereKey($line['lotId']), fn ($q) => $q->where('lot_no', $line['lotNo']))->first()
                ?? throw InventoryException::make('inventory.lot_not_found', "Lot not found for {$item->code}.", 422, "{$line['field']}.lot");
            if ($lot->expiry_date !== null && $lot->expiry_date->lt($date) && $type !== StockDocument::ADJUSTMENT) {
                throw InventoryException::make('inventory.lot_expired', "Lot {$lot->lot_no} expired on {$lot->expiry_date->toDateString()}; write it off with an adjustment.", 422, "{$line['field']}.lot");
            }

            return [['lotId' => $lot->id, 'base' => $line['base']]];
        }

        $rows = DB::table('stock_balances as b')->join('lots as l', 'l.id', '=', 'b.lot_id')
            ->where('b.item_id', $item->id)->where('b.location_id', $locId)->whereNotNull('b.lot_id')
            ->orderByRaw('l.expiry_date asc nulls last')->orderBy('l.id')->get(['b.lot_id', 'l.expiry_date']);

        $need = $line['base'];
        $parts = [];
        foreach ($rows as $row) {
            if ($row->expiry_date !== null && $row->expiry_date < $date->toDateString() && $type !== StockDocument::ADJUSTMENT) {
                continue;   // expired lots are never picked automatically
            }
            $bal = $this->balance($state, $item->id, $locId, (int) $row->lot_id);
            if (Decimal::compare($bal['on_hand'], '0') <= 0) {
                continue;
            }
            $take = Decimal::compare($bal['on_hand'], $need) >= 0 ? $need : $bal['on_hand'];
            $parts[] = ['lotId' => (int) $row->lot_id, 'base' => $take];
            $need = Decimal::sub($need, $take);
            if (Decimal::compare($need, '0') === 0) {
                return $parts;
            }
        }

        throw InventoryException::make('inventory.insufficient_stock', "Not enough usable stock of {$item->code} (short by {$need}; expired lots are not issued).", 422, "{$line['field']}.qty");
    }

    /** @param  array<string, mixed>  $line */
    private function lotForReceipt(ItemInfo $item, array $line, CarbonImmutable $date): Lot
    {
        if ($line['lotId'] !== null) {
            return Lot::query()->where('item_id', $item->id)->find($line['lotId'])
                ?? throw InventoryException::make('inventory.lot_not_found', "Lot not found for {$item->code}.", 422, "{$line['field']}.lot");
        }

        $existing = Lot::query()->where('item_id', $item->id)->where('lot_no', $line['lotNo'])->first();
        if ($existing !== null) {
            if ($line['expiry'] !== null && $existing->expiry_date?->toDateString() !== $line['expiry']) {
                throw InventoryException::make('inventory.lot_expiry_mismatch', "Lot {$existing->lot_no} already exists with a different expiry date.", 422, "{$line['field']}.expiry_date");
            }

            return $existing;
        }

        $expiry = $line['expiry'] ?? ($item->shelfLifeDays !== null ? $date->addDays($item->shelfLifeDays)->toDateString() : null);

        return Lot::query()->create(['item_id' => $item->id, 'lot_no' => $line['lotNo'], 'expiry_date' => $expiry, 'received_on' => $date->toDateString()]);
    }

    // ---------------------------------------------------------------- reservations

    /**
     * Stock leaving against a reservation uses it up (it stops being "promised away").
     *
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>  $prepared
     * @param  list<array<string, mixed>>  $slices
     */
    private function consumeReservations(array &$state, array $prepared, array $slices): void
    {
        $perReservation = [];
        foreach ($slices as $s) {
            if (($s['reservationId'] ?? null) !== null) {
                $perReservation[$s['reservationId']] = Decimal::add($perReservation[$s['reservationId']] ?? '0', $s['base']);
            }
        }

        foreach ($perReservation as $id => $qty) {
            $r = StockReservation::query()->whereKey($id)->lockForUpdate()->first();
            $line = collect($prepared)->first(fn ($p) => $p['reservationId'] === $id);
            $warehouseId = $line['from']->warehouse_id;
            if ($r === null || $r->status !== StockReservation::ACTIVE || $r->item_id !== $line['item']->id || $r->warehouse_id !== $warehouseId) {
                throw InventoryException::make('inventory.reservation_invalid', 'That reservation does not exist, is not active, or is for another item or warehouse.', 422, "{$line['field']}.reservation");
            }
            $remaining = Decimal::sub($r->qty, $r->consumed_qty);
            if (Decimal::compare($qty, $remaining) > 0) {
                throw InventoryException::make('inventory.reservation_insufficient', "The reservation only covers {$remaining}.", 422, "{$line['field']}.reservation");
            }
            $consumed = Decimal::add($r->consumed_qty, $qty);
            $r->forceFill(['consumed_qty' => $consumed, 'status' => Decimal::compare($consumed, $r->qty) === 0 ? StockReservation::CONSUMED : StockReservation::ACTIVE])->save();
        }
    }

    /**
     * After the movement, no warehouse may hold less of an item than is still promised to other documents.
     *
     * @param  array<string, mixed>  $state
     */
    private function assertNothingReservedAway(array $state): void
    {
        $deltas = [];
        foreach ($state['balances'] as $b) {
            $delta = Decimal::sub($b['on_hand'], $b['orig']);
            if (Decimal::compare($delta, '0') >= 0) {
                continue;
            }
            $wh = $this->warehouseOfLocation($b['loc']);
            $deltas["{$b['item']}:{$wh}"] = Decimal::add($deltas["{$b['item']}:{$wh}"] ?? '0', $delta);
        }

        foreach ($deltas as $key => $unused) {
            [$itemId, $wh] = array_map('intval', explode(':', $key));
            $reserved = (string) DB::table('stock_reservations')->where('item_id', $itemId)->where('warehouse_id', $wh)->where('status', 'active')->selectRaw('coalesce(sum(qty - consumed_qty), 0) as r')->value('r');
            if (Decimal::compare($reserved, '0') <= 0) {
                continue;
            }
            $after = '0';
            foreach ($state['balances'] as $b) {
                if ($b['item'] === $itemId && $this->warehouseOfLocation($b['loc']) === $wh) {
                    $after = Decimal::add($after, $b['on_hand']);
                }
            }
            $stored = (string) DB::table('stock_balances as b')->join('locations as l', 'l.id', '=', 'b.location_id')->where('b.item_id', $itemId)->where('l.warehouse_id', $wh)
                ->whereNotIn('b.id', array_values(array_filter(array_column($state['balances'], 'id'))))->sum('b.on_hand');
            if (Decimal::compare(Decimal::add($after, $stored), $reserved) < 0) {
                throw InventoryException::make('inventory.reserved_stock', 'Some of that stock is reserved for other documents; release the reservation or issue against it.', 422, 'lines');
            }
        }
    }

    private function warehouseOfLocation(int $locationId): int
    {
        return $this->warehouseByLocation[$locationId] ??= (int) Location::query()->whereKey($locationId)->value('warehouse_id');
    }

    // ---------------------------------------------------------------- persistence

    /**
     * Write the document, lines, ledger rows, balances and costs; announce the movement.
     *
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>  $slices
     * @param  array<string, mixed>  $h  header: type, date, reason, reference, notes, sourceType, sourceId, by, reverses
     * @param  list<int>  $itemIds
     * @param  array<int, string>  $before  reorder snapshot taken before the movement
     */
    private function persist(array $state, array $slices, array $h, array $itemIds, array $before): PostedMovement
    {
        $type = $h['type'];
        $this->numbering->define("inventory.{$type}", self::PREFIX[$type].'/{FY}/{SEQ}', 5, 'fiscal_yearly');
        $total = '0';
        foreach ($slices as $s) {
            $total = Decimal::add($total, $s['value']);
        }

        $doc = StockDocument::query()->create([
            'document_no' => $this->numbering->next("inventory.{$type}", null, $h['date']),
            'doc_type' => $type, 'status' => 'posted', 'posting_date' => $h['date']->toDateString(), 'reason' => $h['reason'],
            'reference' => $h['reference'], 'notes' => $h['notes'], 'source_type' => $h['sourceType'], 'source_id' => $h['sourceId'],
            'reverses_id' => $h['reverses'], 'total_value' => Decimal::round($total, 4), 'created_by' => $h['by'],
        ]);

        foreach ($slices as $n => $s) {
            $line = StockDocumentLine::query()->create([
                'document_id' => $doc->id, 'line_no' => $n + 1, 'item_id' => $s['item'], 'uom_id' => $s['uomId'], 'uom_code' => $s['uomCode'],
                'qty' => $s['qty'], 'base_qty' => $s['base'], 'from_location_id' => $s['from'], 'to_location_id' => $s['to'], 'lot_id' => $s['lotId'],
                'unit_cost' => $s['unitCost'], 'value' => $s['value'], 'note' => $s['note'],
            ]);
            foreach ($s['ops'] as $op) {
                $abs = ltrim($op['qty'], '-');
                StockLedgerEntry::query()->create([
                    'posting_date' => $h['date']->toDateString(), 'document_id' => $doc->id, 'line_id' => $line->id, 'item_id' => $s['item'],
                    'location_id' => $op['loc'], 'lot_id' => $op['lot'], 'qty' => $op['qty'],
                    'unit_cost' => Decimal::round(Decimal::div(ltrim($op['value'], '-'), $abs), 6), 'value' => $op['value'],
                    'reverses_id' => $op['reverses'] ?? null, 'created_by' => $h['by'],
                ]);
            }
        }

        foreach ($state['balances'] as $b) {
            if ($b['on_hand'] === $b['orig'] && $b['id'] !== null) {
                continue;
            }
            if ($b['id'] === null) {
                StockBalance::query()->create(['item_id' => $b['item'], 'location_id' => $b['loc'], 'lot_id' => $b['lot'], 'on_hand' => $b['on_hand']]);
            } else {
                DB::table('stock_balances')->where('id', $b['id'])->update(['on_hand' => $b['on_hand'], 'updated_at' => now()]);
            }
        }
        foreach ($state['costs'] as $itemId => $c) {
            DB::table('item_costs')->where('item_id', $itemId)->update(['qty' => $c['qty'], 'value' => $c['value'], 'updated_at' => now()]);
        }

        $this->audit->record('inventory.stock_posted', $doc, [], [
            'document_no' => $doc->document_no, 'type' => $type, 'reason' => $h['reason'], 'lines' => count($slices), 'total_value' => $doc->total_value,
        ]);
        // Finance builds its journal from this alone (it never reads Inventory tables): the net change in stock value, and
        // what kind of movement it was. A reversal says what it reverses, so its journal uses the original's counter account.
        $delta = '0';
        foreach ($slices as $s) {
            foreach ($s['ops'] as $op) {
                $delta = Decimal::add($delta, $op['value']);
            }
        }
        $this->outbox->record('inventory.stock.moved', [
            'document_id' => $doc->id, 'document_no' => $doc->document_no, 'type' => $type, 'reason' => $h['reason'], 'item_ids' => $itemIds,
            'posting_date' => $doc->posting_date->toDateString(), 'inventory_delta' => Decimal::round($delta, 4),
            'reverses_document_id' => $h['reverses'], 'counter_type' => $h['originalType'] ?? $type, 'counter_reason' => $h['originalReason'] ?? $h['reason'],
        ], 'stock_document', $doc->id);
        $this->reorder->announceCrossings($before, $itemIds);

        return new PostedMovement($doc->id, $doc->public_id, $doc->document_no, $doc->total_value, count($slices), false);
    }
}
