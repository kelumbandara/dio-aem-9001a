<?php

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\OutboxEvent;
use App\Modules\Inventory\Actions\SaveLocation;
use App\Modules\Inventory\Actions\SaveWarehouse;
use App\Modules\Inventory\Contracts\StockAvailability;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\DTOs\StockMovement;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\ItemCost;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Lot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockDocument;
use App\Modules\Inventory\Models\StockLedgerEntry;
use App\Modules\Inventory\Services\StockReconciler;
use App\Support\Exceptions\BusinessException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->wh = invWarehouse(['code' => 'MAIN-WH']);
    $this->item = mdItem(['name' => 'Widget']);
    $this->cost = fn () => ItemCost::query()->find($this->item->id);
    $this->reconciled = fn () => expect(app(StockReconciler::class)->discrepancies())->toBe([]);
});

function expectBusinessError(Closure $fn, string $code): void
{
    try {
        $fn();
    } catch (BusinessException $e) {
        expect($e->errorCode)->toBe($code);

        return;
    }
    test()->fail("Expected business error {$code}, but nothing was thrown.");
}

describe('receipts and weighted-average cost', function () {
    it('records a receipt in the ledger, the balance and the average cost', function () {
        $posted = invReceive($this->item, $this->wh, '10', '100');

        expect($posted->documentNo)->toMatch('#^SRC/\d{4}-\d{2}/\d{5}$|^SRC/#')->and($posted->totalValue)->toBe('1000.0000')->and($posted->replayed)->toBeFalse();
        expect(invOnHand($this->item))->toBe('10.000000')
            ->and(($this->cost)()->only('qty', 'value'))->toBe(['qty' => '10.000000', 'value' => '1000.0000'])
            ->and(app(StockAvailability::class)->averageCost($this->item->id))->toBe('100.000000');
        expect(StockLedgerEntry::count())->toBe(1)->and(StockLedgerEntry::first())->qty->toBe('10.000000')->value->toBe('1000.0000');
        expect(AuditLog::where('event', 'inventory.stock_posted')->count())->toBe(1)
            ->and(OutboxEvent::where('event_type', 'inventory.stock.moved')->count())->toBe(1);
        ($this->reconciled)();
    });

    it('averages the cost of later receipts and issues at that average', function () {
        invReceive($this->item, $this->wh, '10', '100');
        invReceive($this->item, $this->wh, '10', '200');
        expect(app(StockAvailability::class)->averageCost($this->item->id))->toBe('150.000000');

        $issue = invPost('issue', [invLine($this->item, '5', ['warehouseId' => $this->wh->id])]);

        expect($issue->totalValue)->toBe('750.0000')
            ->and(($this->cost)()->only('qty', 'value'))->toBe(['qty' => '15.000000', 'value' => '2250.0000']);
        expect(StockLedgerEntry::orderByDesc('id')->first())->qty->toBe('-5.000000')->value->toBe('-750.0000');
        ($this->reconciled)();
    });

    it('never leaves a rounding residue: the last unit takes whatever value is left', function () {
        invReceive($this->item, $this->wh, '3', '3.333333');   // 3 units worth 10.0000 (9.999999 rounded to 4 places)
        foreach ([1, 1, 1] as $unused) {
            invPost('issue', [invLine($this->item, '1', ['warehouseId' => $this->wh->id])]);
        }

        expect(($this->cost)()->only('qty', 'value'))->toBe(['qty' => '0.000000', 'value' => '0.0000']);
        expect(StockLedgerEntry::sum('value'))->toEqual(0);
        ($this->reconciled)();
    });

    it('accepts quantities in another unit and keeps stock in the base unit', function () {
        $item = mdItem(['name' => 'Soap', 'uoms' => [['uom' => 'BOX', 'factor' => '12']]]);
        $box = DB::table('units_of_measure')->where('code', 'BOX')->value('id');

        $posted = invPost('receipt', [invLine($item, '2', ['warehouseId' => $this->wh->id, 'uomId' => $box, 'unitCost' => '1200'])]);

        expect($posted->totalValue)->toBe('2400.0000')->and(invOnHand($item))->toBe('24.000000');
        $line = DB::table('stock_document_lines')->where('document_id', $posted->documentId)->first();
        expect($line->uom_code)->toBe('BOX')->and($line->qty)->toBe('2.000000')->and($line->base_qty)->toBe('24.000000');
        ($this->reconciled)();
    });

    it('allows a zero-cost receipt (samples, opening stock) but not a missing cost', function () {
        invReceive($this->item, $this->wh, '5', '0');
        expect(($this->cost)()->only('value'))->toBe(['value' => '0.0000']);

        expectBusinessError(fn () => invPost('receipt', [invLine($this->item, '1', ['warehouseId' => $this->wh->id])]), 'inventory.cost_required');
    });
});

describe('what is refused', function () {
    it('refuses to issue more than is there and changes nothing', function () {
        invReceive($this->item, $this->wh, '4', '10');
        $documents = StockDocument::count();

        expectBusinessError(fn () => invPost('issue', [invLine($this->item, '5', ['warehouseId' => $this->wh->id])]), 'inventory.insufficient_stock');

        expect(invOnHand($this->item))->toBe('4.000000')->and(StockDocument::count())->toBe($documents)->and(StockLedgerEntry::count())->toBe(1);
    });

    it('is all or nothing across lines', function () {
        $other = mdItem(['name' => 'Other']);
        invReceive($this->item, $this->wh, '10', '10');

        expectBusinessError(fn () => invPost('issue', [
            invLine($this->item, '3', ['warehouseId' => $this->wh->id]),
            invLine($other, '1', ['warehouseId' => $this->wh->id]),
        ]), 'inventory.insufficient_stock');

        expect(invOnHand($this->item))->toBe('10.000000');
        ($this->reconciled)();
    });

    it('uses the running stock within one document', function () {
        invReceive($this->item, $this->wh, '5', '10');

        expectBusinessError(fn () => invPost('issue', [
            invLine($this->item, '3', ['warehouseId' => $this->wh->id]),
            invLine($this->item, '3', ['warehouseId' => $this->wh->id]),
        ]), 'inventory.insufficient_stock');
    });

    it('validates quantities, units, dates and reasons', function (Closure $build, string $code) {
        $args = $build($this->item, $this->wh);
        expectBusinessError(fn () => invPost(...$args), $code);
    })->with([
        'zero quantity' => [fn ($i, $w) => ['receipt', [invLine($i, '0', ['warehouseId' => $w->id, 'unitCost' => '1'])]], 'inventory.qty_invalid'],
        'negative quantity' => [fn ($i, $w) => ['receipt', [invLine($i, '-1', ['warehouseId' => $w->id, 'unitCost' => '1'])]], 'inventory.qty_invalid'],
        'too many decimals for the unit' => [fn ($i, $w) => ['receipt', [invLine($i, '1.5', ['warehouseId' => $w->id, 'unitCost' => '1'])]], 'inventory.qty_invalid'],
        'text quantity' => [fn ($i, $w) => ['receipt', [invLine($i, 'abc', ['warehouseId' => $w->id, 'unitCost' => '1'])]], 'inventory.qty_invalid'],
        'negative cost' => [fn ($i, $w) => ['receipt', [invLine($i, '1', ['warehouseId' => $w->id, 'unitCost' => '-1'])]], 'inventory.cost_invalid'],
        'no warehouse' => [fn ($i, $w) => ['receipt', [invLine($i, '1', ['unitCost' => '1'])]], 'inventory.warehouse_required'],
        'future date' => [fn ($i, $w) => ['receipt', [invLine($i, '1', ['warehouseId' => $w->id, 'unitCost' => '1'])], ['postingDate' => now('Asia/Colombo')->addDay()->toDateString()]], 'inventory.date_in_future'],
        'impossible date' => [fn ($i, $w) => ['receipt', [invLine($i, '1', ['warehouseId' => $w->id, 'unitCost' => '1'])], ['postingDate' => '2026-02-31']], 'inventory.date_invalid'],
        'wrong reason for the type' => [fn ($i, $w) => ['receipt', [invLine($i, '1', ['warehouseId' => $w->id, 'unitCost' => '1'])], ['reason' => 'sale']], 'inventory.reason_invalid'],
        'no lines' => [fn ($i, $w) => ['receipt', []], 'inventory.lines_invalid'],
        'adjustment without direction' => [fn ($i, $w) => ['adjustment', [invLine($i, '1', ['warehouseId' => $w->id])]], 'inventory.direction_required'],
    ]);

    it('only moves stock items in an active warehouse', function () {
        $service = mdItem(['name' => 'Consulting', 'item_type' => 'service']);
        $inactive = invWarehouse(['code' => 'OLD']);
        app(SaveWarehouse::class)(['is_active' => false], $inactive);

        expectBusinessError(fn () => invPost('receipt', [invLine($service, '1', ['warehouseId' => $this->wh->id, 'unitCost' => '1'])]), 'inventory.item_not_stocked');
        expectBusinessError(fn () => invPost('receipt', [invLine($this->item, '1', ['warehouseId' => $inactive->id, 'unitCost' => '1'])]), 'inventory.warehouse_inactive');

        $this->item->forceFill(['is_active' => false])->save();
        expectBusinessError(fn () => invReceive($this->item, $this->wh, '1', '1'), 'masterdata.item_inactive');
    });
});

describe('transfers', function () {
    it('moves stock between warehouses without changing the item cost', function () {
        $to = invWarehouse(['code' => 'SHOP']);
        invReceive($this->item, $this->wh, '10', '100');
        invReceive($this->item, $this->wh, '10', '200');

        $posted = invPost('transfer', [invLine($this->item, '8', ['warehouseId' => $this->wh->id, 'toWarehouseId' => $to->id])]);

        expect($posted->totalValue)->toBe('1200.0000')
            ->and(invOnHand($this->item, $this->wh))->toBe('12.000000')->and(invOnHand($this->item, $to))->toBe('8.000000')
            ->and(($this->cost)()->only('qty', 'value'))->toBe(['qty' => '20.000000', 'value' => '3000.0000']);
        expect(StockLedgerEntry::where('document_id', $posted->documentId)->count())->toBe(2);
        ($this->reconciled)();
    });

    it('refuses a transfer to the same place or of stock that is not there', function () {
        $to = invWarehouse(['code' => 'SHOP']);
        invReceive($this->item, $this->wh, '2', '10');

        expectBusinessError(fn () => invPost('transfer', [invLine($this->item, '1', ['warehouseId' => $this->wh->id, 'toWarehouseId' => $this->wh->id])]), 'inventory.same_location');
        expectBusinessError(fn () => invPost('transfer', [invLine($this->item, '3', ['warehouseId' => $this->wh->id, 'toWarehouseId' => $to->id])]), 'inventory.insufficient_stock');
    });
});

describe('locations', function () {
    it('keeps stock in the default location when a warehouse does not use locations', function () {
        $bin = app(SaveLocation::class)($this->wh, ['code' => 'A1', 'name' => 'Rack A1']);

        expectBusinessError(fn () => invPost('receipt', [invLine($this->item, '1', ['locationId' => $bin->id, 'unitCost' => '1'])]), 'inventory.locations_not_used');
        invReceive($this->item, $this->wh, '1', '1');
        expect(StockBalance::sole()->location_id)->toBe(Location::where('warehouse_id', $this->wh->id)->where('is_default', true)->value('id'));
    });

    it('lets people choose a location when the warehouse uses them, and counts warehouse totals across them', function () {
        $wh = invWarehouse(['code' => 'BIG', 'uses_locations' => true]);
        $a = app(SaveLocation::class)($wh, ['code' => 'A1', 'name' => 'Rack A1']);
        $b = app(SaveLocation::class)($wh, ['code' => 'B1', 'name' => 'Rack B1']);

        invPost('receipt', [invLine($this->item, '5', ['locationId' => $a->id, 'unitCost' => '10']), invLine($this->item, '7', ['locationId' => $b->id, 'unitCost' => '10'])]);
        invPost('transfer', [invLine($this->item, '2', ['locationId' => $a->id, 'toLocationId' => $b->id])]);

        expect(StockBalance::where('location_id', $a->id)->value('on_hand'))->toBe('3.000000')
            ->and(StockBalance::where('location_id', $b->id)->value('on_hand'))->toBe('9.000000')
            ->and(invOnHand($this->item, $wh))->toBe('12.000000');
        expectBusinessError(fn () => invPost('issue', [invLine($this->item, '1', ['warehouseId' => $this->wh->id, 'locationId' => $a->id])]), 'inventory.location_mismatch');
        ($this->reconciled)();
    });
});

describe('adjustments', function () {
    it('writes off at average cost and adds found stock at the average unless a cost is given', function () {
        invReceive($this->item, $this->wh, '10', '100');

        $loss = invPost('adjustment', [invLine($this->item, '2', ['warehouseId' => $this->wh->id, 'direction' => 'out'])], ['reason' => 'damage']);
        $gain = invPost('adjustment', [invLine($this->item, '1', ['warehouseId' => $this->wh->id, 'direction' => 'in'])], ['reason' => 'found']);
        $costed = invPost('adjustment', [invLine($this->item, '1', ['warehouseId' => $this->wh->id, 'direction' => 'in', 'unitCost' => '300'])], ['reason' => 'found']);

        expect($loss->totalValue)->toBe('200.0000')->and($gain->totalValue)->toBe('100.0000')->and($costed->totalValue)->toBe('300.0000')
            ->and(invOnHand($this->item))->toBe('10.000000')->and(($this->cost)()->only('value'))->toBe(['value' => '1200.0000']);
        ($this->reconciled)();
    });

    it('cannot add uncosted stock to an item that has no cost yet', function () {
        expectBusinessError(fn () => invPost('adjustment', [invLine($this->item, '1', ['warehouseId' => $this->wh->id, 'direction' => 'in'])], ['reason' => 'found']), 'inventory.cost_required');
    });
});

describe('lots and expiry', function () {
    beforeEach(function () {
        $this->lotItem = mdItem(['name' => 'Yoghurt', 'tracking' => 'lot', 'shelf_life_days' => 30]);
    });

    it('needs a lot number for lot-tracked items and refuses lots on other items', function () {
        expectBusinessError(fn () => invReceive($this->lotItem, $this->wh, '5', '10'), 'inventory.lot_required');
        expectBusinessError(fn () => invReceive($this->item, $this->wh, '5', '10', ['line' => ['lotNo' => 'L1']]), 'inventory.lot_not_tracked');
    });

    it('creates the lot with an expiry from the shelf life, or from the date given', function () {
        invReceive($this->lotItem, $this->wh, '5', '10', ['line' => ['lotNo' => 'L1']]);
        invReceive($this->lotItem, $this->wh, '5', '10', ['line' => ['lotNo' => 'L2', 'expiryDate' => '2030-01-31']]);

        expect(Lot::where('lot_no', 'L1')->first()->expiry_date->toDateString())->toBe(now('Asia/Colombo')->addDays(30)->toDateString())
            ->and(Lot::where('lot_no', 'L2')->first()->expiry_date->toDateString())->toBe('2030-01-31');
        expectBusinessError(fn () => invReceive($this->lotItem, $this->wh, '1', '10', ['line' => ['lotNo' => 'L2', 'expiryDate' => '2031-01-31']]), 'inventory.lot_expiry_mismatch');
    });

    it('issues first-expiry-first-out across lots and records which lots it used', function () {
        invReceive($this->lotItem, $this->wh, '5', '10', ['line' => ['lotNo' => 'LATE', 'expiryDate' => '2031-06-01']]);
        invReceive($this->lotItem, $this->wh, '5', '10', ['line' => ['lotNo' => 'EARLY', 'expiryDate' => '2031-01-01']]);

        $posted = invPost('issue', [invLine($this->lotItem, '7', ['warehouseId' => $this->wh->id])]);

        $lines = DB::table('stock_document_lines as l')->join('lots', 'lots.id', '=', 'l.lot_id')->where('l.document_id', $posted->documentId)->orderBy('l.line_no')->get(['lots.lot_no', 'l.base_qty']);
        expect($lines->pluck('lot_no')->all())->toBe(['EARLY', 'LATE'])->and($lines->pluck('base_qty')->all())->toBe(['5.000000', '2.000000']);
        expect(StockBalance::query()->whereHas('lot', fn ($q) => $q->where('lot_no', 'LATE'))->value('on_hand'))->toBe('3.000000');
        ($this->reconciled)();
    });

    it('never picks an expired lot automatically and refuses naming one, but lets an adjustment write it off', function () {
        invReceive($this->lotItem, $this->wh, '5', '10', ['line' => ['lotNo' => 'OLD', 'expiryDate' => now('Asia/Colombo')->subDays(2)->toDateString()]]);
        invReceive($this->lotItem, $this->wh, '3', '10', ['line' => ['lotNo' => 'NEW', 'expiryDate' => '2031-01-01']]);

        expectBusinessError(fn () => invPost('issue', [invLine($this->lotItem, '4', ['warehouseId' => $this->wh->id])]), 'inventory.insufficient_stock');
        expectBusinessError(fn () => invPost('issue', [invLine($this->lotItem, '1', ['warehouseId' => $this->wh->id, 'lotNo' => 'OLD'])]), 'inventory.lot_expired');
        invPost('issue', [invLine($this->lotItem, '3', ['warehouseId' => $this->wh->id])]);

        invPost('adjustment', [invLine($this->lotItem, '5', ['warehouseId' => $this->wh->id, 'direction' => 'out', 'lotNo' => 'OLD'])], ['reason' => 'expiry']);
        expect(invOnHand($this->lotItem))->toBe('0.000000');
        ($this->reconciled)();
    });
});

describe('reversals', function () {
    it('undoes a receipt exactly and marks the original', function () {
        $posted = invReceive($this->item, $this->wh, '10', '100');

        $reversal = app(StockPoster::class)->reverse($posted->documentId, 'Entered twice');

        expect(invOnHand($this->item))->toBe('0.000000')->and(($this->cost)()->only('value'))->toBe(['value' => '0.0000']);
        $original = StockDocument::find($posted->documentId);
        expect($original->status)->toBe('reversed')->and($original->reversed_by_id)->toBe($reversal->documentId);
        $doc = StockDocument::find($reversal->documentId);
        expect($doc->reverses_id)->toBe($original->id)->and($doc->reason)->toBe('reversal')->and($doc->notes)->toBe('Entered twice');
        expect(StockLedgerEntry::whereNotNull('reverses_id')->count())->toBe(1);
        ($this->reconciled)();
    });

    it('puts issued stock back at its original value', function () {
        invReceive($this->item, $this->wh, '10', '100');
        invReceive($this->item, $this->wh, '10', '200');
        $issue = invPost('issue', [invLine($this->item, '5', ['warehouseId' => $this->wh->id])]);

        app(StockPoster::class)->reverse($issue->documentId);

        expect(invOnHand($this->item))->toBe('20.000000')->and(($this->cost)()->only('value'))->toBe(['value' => '3000.0000']);
        ($this->reconciled)();
    });

    it('undoes a transfer', function () {
        $to = invWarehouse(['code' => 'SHOP']);
        invReceive($this->item, $this->wh, '10', '10');
        $transfer = invPost('transfer', [invLine($this->item, '4', ['warehouseId' => $this->wh->id, 'toWarehouseId' => $to->id])]);

        app(StockPoster::class)->reverse($transfer->documentId);

        expect(invOnHand($this->item, $this->wh))->toBe('10.000000')->and(invOnHand($this->item, $to))->toBe('0.000000');
        ($this->reconciled)();
    });

    it('cannot undo a receipt whose stock has been used, a second time, or a reversal', function () {
        $posted = invReceive($this->item, $this->wh, '10', '100');
        invPost('issue', [invLine($this->item, '4', ['warehouseId' => $this->wh->id])]);

        expectBusinessError(fn () => app(StockPoster::class)->reverse($posted->documentId), 'inventory.insufficient_stock');
        expect(StockDocument::find($posted->documentId)->status)->toBe('posted');

        $fresh = invReceive($this->item, $this->wh, '1', '1');
        $reversal = app(StockPoster::class)->reverse($fresh->documentId);
        expectBusinessError(fn () => app(StockPoster::class)->reverse($fresh->documentId), 'inventory.already_reversed');
        expectBusinessError(fn () => app(StockPoster::class)->reverse($reversal->documentId), 'inventory.cannot_reverse_reversal');
        expectBusinessError(fn () => app(StockPoster::class)->reverse(999999), 'inventory.document_not_found');
        ($this->reconciled)();
    });
});

describe('posting on behalf of other modules', function () {
    it('posts a source document once: asking again returns the first result', function () {
        $args = [[invLine($this->item, '5', ['warehouseId' => $this->wh->id, 'unitCost' => '10'])], ['sourceType' => 'goods_receipt', 'sourceId' => 'GRN-1']];

        $first = invPost('receipt', ...$args);
        $again = invPost('receipt', ...$args);

        expect($again->documentId)->toBe($first->documentId)->and($again->replayed)->toBeTrue()->and(invOnHand($this->item))->toBe('5.000000')->and(StockDocument::count())->toBe(1);
    });

    it('can be asked what would happen without it happening', function () {
        $m = new StockMovement('receipt', now('Asia/Colombo')->toDateString(), 'purchase', [invLine($this->item, '5', ['warehouseId' => $this->wh->id, 'unitCost' => '10'])]);

        $preview = app(StockPoster::class)->check($m);

        expect($preview->totalValue)->toBe('50.0000')->and(StockDocument::count())->toBe(0)->and(StockBalance::count())->toBe(0);
        expectBusinessError(fn () => app(StockPoster::class)->check(new StockMovement('issue', now('Asia/Colombo')->toDateString(), 'sale', [invLine($this->item, '1', ['warehouseId' => $this->wh->id])])), 'inventory.insufficient_stock');
    });
});

describe('the ledger is permanent', function () {
    it('refuses changes and deletes at the model and the database', function () {
        invReceive($this->item, $this->wh, '5', '10');
        $entry = StockLedgerEntry::first();

        expect(fn () => $entry->update(['qty' => '9']))->toThrow(LogicException::class);
        expect(fn () => $entry->delete())->toThrow(LogicException::class);
        expect(fn () => DB::table('stock_ledger')->update(['qty' => '9']))->toThrow(QueryException::class);
        expect(fn () => DB::table('stock_ledger')->delete())->toThrow(QueryException::class);
        expect(fn () => DB::table('stock_document_lines')->update(['qty' => '9']))->toThrow(QueryException::class);
        expect(fn () => DB::table('stock_documents')->update(['total_value' => '9']))->toThrow(QueryException::class);
        expect(fn () => DB::table('stock_documents')->delete())->toThrow(QueryException::class);
    });

    it('refuses a negative balance even if application code tried to write one', function () {
        invReceive($this->item, $this->wh, '5', '10');

        expect(fn () => DB::table('stock_balances')->update(['on_hand' => '-1']))->toThrow(QueryException::class);
    });

    it('lets the reconciler notice a balance changed behind the ledger\'s back', function () {
        invReceive($this->item, $this->wh, '5', '10');
        DB::table('stock_balances')->update(['on_hand' => '6']);
        DB::table('item_costs')->update(['value' => '51']);

        $problems = app(StockReconciler::class)->discrepancies();

        expect($problems)->toHaveCount(2)->and($problems[0])->toContain('ledger says 5')->and($problems[1])->toContain('Cost of item');
        $this->artisan('inventory:reconcile')->assertFailed();
    });

    it('reconciles cleanly on an empty system and says so', function () {
        $this->artisan('inventory:reconcile')->expectsOutputToContain('agrees')->assertSuccessful();
    });
});

it('throws inventory errors as business errors with a machine code and a field', function () {
    try {
        invReceive($this->item, $this->wh, '0', '10');
    } catch (InventoryException $e) {
        expect($e->errorCode)->toBe('inventory.qty_invalid')->and($e->status)->toBe(422)->and($e->details[0]['field'])->toBe('lines.0.qty');

        return;
    }
    test()->fail('expected an exception');
});
