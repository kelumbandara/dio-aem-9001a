<?php

use App\Modules\Core\Models\OutboxEvent;
use App\Modules\Inventory\Actions\SaveReorderRule;
use App\Modules\Inventory\Actions\SaveWarehouse;
use App\Modules\Inventory\Contracts\StockAvailability;
use App\Modules\Inventory\Contracts\StockPoster;
use App\Modules\Inventory\Contracts\StockReserver;
use App\Modules\Inventory\Models\ReorderRule;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\StockReconciler;
use App\Support\Exceptions\BusinessException;

function expectError(Closure $fn, string $code): void
{
    try {
        $fn();
    } catch (BusinessException $e) {
        expect($e->errorCode)->toBe($code);

        return;
    }
    test()->fail("Expected business error {$code}.");
}

beforeEach(function () {
    $this->wh = invWarehouse(['code' => 'MAIN']);
    $this->shop = invWarehouse(['code' => 'SHOP']);
    $this->item = mdItem(['name' => 'Widget']);
    $this->reserver = app(StockReserver::class);
    $this->level = fn (?int $wh = null) => app(StockAvailability::class)->level($this->item->id, $wh);
});

describe('reservations', function () {
    it('hold stock back without moving it', function () {
        invReceive($this->item, $this->wh, '10', '5');

        $this->reserver->reserve($this->item->id, $this->wh->id, '8', 'sales_order', 'SO-1');

        expect(($this->level)()->onHand)->toBe('10.000000')->and(($this->level)()->reserved)->toBe('8.000000')->and(($this->level)()->available)->toBe('2.000000');
        expect(($this->level)($this->shop->id)->available)->toBe('0.000000');
    });

    it('cannot promise more than is available, per warehouse', function () {
        invReceive($this->item, $this->wh, '10', '5');
        $this->reserver->reserve($this->item->id, $this->wh->id, '8', 'sales_order', 'SO-1');

        expectError(fn () => $this->reserver->reserve($this->item->id, $this->wh->id, '3', 'sales_order', 'SO-2'), 'inventory.insufficient_available');
        expectError(fn () => $this->reserver->reserve($this->item->id, $this->shop->id, '1', 'sales_order', 'SO-3'), 'inventory.insufficient_available');
        expectError(fn () => $this->reserver->reserve($this->item->id, $this->wh->id, '0', 'sales_order', 'SO-4'), 'inventory.qty_invalid');
    });

    it('protect their stock from other issues, transfers and adjustments', function () {
        invReceive($this->item, $this->wh, '10', '5');
        $this->reserver->reserve($this->item->id, $this->wh->id, '8', 'sales_order', 'SO-1');

        expectError(fn () => invPost('issue', [invLine($this->item, '3', ['warehouseId' => $this->wh->id])]), 'inventory.reserved_stock');
        expectError(fn () => invPost('transfer', [invLine($this->item, '3', ['warehouseId' => $this->wh->id, 'toWarehouseId' => $this->shop->id])]), 'inventory.reserved_stock');
        expectError(fn () => invPost('adjustment', [invLine($this->item, '3', ['warehouseId' => $this->wh->id, 'direction' => 'out'])], ['reason' => 'loss']), 'inventory.reserved_stock');

        invPost('issue', [invLine($this->item, '2', ['warehouseId' => $this->wh->id])]);   // what is left over is free to use
        expect(invOnHand($this->item))->toBe('8.000000');
    });

    it('are used up by the issue made against them', function () {
        invReceive($this->item, $this->wh, '10', '5');
        $id = $this->reserver->reserve($this->item->id, $this->wh->id, '8', 'sales_order', 'SO-1');

        invPost('issue', [invLine($this->item, '5', ['warehouseId' => $this->wh->id, 'reservationId' => $id])]);

        $r = StockReservation::find($id);
        expect($r->consumed_qty)->toBe('5.000000')->and($r->status)->toBe('active')->and(($this->level)()->reserved)->toBe('3.000000')->and(($this->level)()->available)->toBe('2.000000');

        invPost('issue', [invLine($this->item, '3', ['warehouseId' => $this->wh->id, 'reservationId' => $id])]);
        expect(StockReservation::find($id)->status)->toBe('consumed')->and(($this->level)()->reserved)->toBe('0.000000');
        expect(app(StockReconciler::class)->discrepancies())->toBe([]);
    });

    it('can only be used for their own item and warehouse, within what they cover, and while active', function () {
        invReceive($this->item, $this->wh, '10', '5');
        invReceive($this->item, $this->shop, '10', '5');
        $other = mdItem(['name' => 'Other']);
        invReceive($other, $this->wh, '10', '5');
        $id = $this->reserver->reserve($this->item->id, $this->wh->id, '4', 'sales_order', 'SO-1');

        expectError(fn () => invPost('issue', [invLine($this->item, '1', ['warehouseId' => $this->shop->id, 'reservationId' => $id])]), 'inventory.reservation_invalid');
        expectError(fn () => invPost('issue', [invLine($other, '1', ['warehouseId' => $this->wh->id, 'reservationId' => $id])]), 'inventory.reservation_invalid');
        expectError(fn () => invPost('issue', [invLine($this->item, '5', ['warehouseId' => $this->wh->id, 'reservationId' => $id])]), 'inventory.reservation_insufficient');
        expectError(fn () => invPost('receipt', [invLine($this->item, '1', ['warehouseId' => $this->wh->id, 'unitCost' => '1', 'reservationId' => $id])]), 'inventory.reservation_invalid');

        $this->reserver->release($id);
        expectError(fn () => invPost('issue', [invLine($this->item, '1', ['warehouseId' => $this->wh->id, 'reservationId' => $id])]), 'inventory.reservation_invalid');
    });

    it('can be released, singly or by source, and releasing twice is harmless', function () {
        invReceive($this->item, $this->wh, '10', '5');
        $a = $this->reserver->reserve($this->item->id, $this->wh->id, '3', 'sales_order', 'SO-1');
        $this->reserver->reserve($this->item->id, $this->wh->id, '2', 'sales_order', 'SO-1');
        $this->reserver->reserve($this->item->id, $this->wh->id, '1', 'sales_order', 'SO-2');

        $this->reserver->release($a);
        $this->reserver->release($a);
        expect(($this->level)()->reserved)->toBe('3.000000');

        expect($this->reserver->releaseBySource('sales_order', 'SO-1'))->toBe(1)->and($this->reserver->releaseBySource('sales_order', 'SO-1'))->toBe(0)
            ->and(($this->level)()->reserved)->toBe('1.000000');
    });

    it('also stop a reversal from taking reserved stock away', function () {
        $posted = invReceive($this->item, $this->wh, '10', '5');
        $this->reserver->reserve($this->item->id, $this->wh->id, '4', 'sales_order', 'SO-1');

        expectError(fn () => app(StockPoster::class)->reverse($posted->documentId), 'inventory.reserved_stock');
    });

    it('refuse non-stock items and inactive warehouses', function () {
        $service = mdItem(['name' => 'Service', 'item_type' => 'service']);
        $closed = invWarehouse(['code' => 'OLD']);
        app(SaveWarehouse::class)(['is_active' => false], $closed);

        expectError(fn () => $this->reserver->reserve($service->id, $this->wh->id, '1', 'x', '1'), 'inventory.item_not_stocked');
        expectError(fn () => $this->reserver->reserve($this->item->id, $closed->id, '1', 'x', '1'), 'inventory.warehouse_inactive');
    });
});

describe('reorder rules', function () {
    beforeEach(function () {
        app(SaveReorderRule::class)(['item_id' => $this->item->id, 'warehouse_id' => null, 'min_qty' => '5', 'reorder_qty' => '20']);
        $this->events = fn () => OutboxEvent::where('event_type', 'inventory.stock.below_reorder')->get();
    });

    it('announce the moment stock drops below the minimum, once', function () {
        invReceive($this->item, $this->wh, '10', '5');
        expect(($this->events)())->toHaveCount(0);

        invPost('issue', [invLine($this->item, '4', ['warehouseId' => $this->wh->id])]);   // 6 left: still fine
        expect(($this->events)())->toHaveCount(0);

        invPost('issue', [invLine($this->item, '2', ['warehouseId' => $this->wh->id])]);   // 4 left: crossed
        expect(($this->events)())->toHaveCount(1);
        $payload = ($this->events)()->first()->payload;
        expect($payload)->toMatchArray(['item_id' => $this->item->id, 'available' => '4.000000', 'min_qty' => '5.000000', 'reorder_qty' => '20.000000']);

        invPost('issue', [invLine($this->item, '1', ['warehouseId' => $this->wh->id])]);   // already below: no second event
        expect(($this->events)())->toHaveCount(1);

        invReceive($this->item, $this->wh, '20', '5');                                       // back above
        invPost('issue', [invLine($this->item, '20', ['warehouseId' => $this->wh->id])]);   // crosses again
        expect(($this->events)())->toHaveCount(2);
    });

    it('count reserved stock as unavailable', function () {
        invReceive($this->item, $this->wh, '10', '5');
        app(StockReserver::class)->reserve($this->item->id, $this->wh->id, '4', 'sales_order', 'SO-1');   // available 6

        invPost('issue', [invLine($this->item, '2', ['warehouseId' => $this->wh->id])]);                    // available 4: below 5
        expect(($this->events)())->toHaveCount(1);
    });

    it('can be limited to one warehouse, and ignore inactive rules', function () {
        OutboxEvent::query()->delete();
        ReorderRule::query()->delete();
        app(SaveReorderRule::class)(['item_id' => $this->item->id, 'warehouse_id' => $this->shop->id, 'min_qty' => '5']);
        invReceive($this->item, $this->wh, '20', '5');
        invReceive($this->item, $this->shop, '6', '5');

        invPost('issue', [invLine($this->item, '10', ['warehouseId' => $this->wh->id])]);   // other warehouse: no event
        expect(($this->events)())->toHaveCount(0);
        invPost('issue', [invLine($this->item, '2', ['warehouseId' => $this->shop->id])]);  // shop 4 < 5
        expect(($this->events)())->toHaveCount(1)->and(($this->events)()->first()->payload['warehouse_id'])->toBe($this->shop->id);

        ReorderRule::query()->update(['is_active' => false]);
        invReceive($this->item, $this->shop, '10', '5');
        invPost('issue', [invLine($this->item, '14', ['warehouseId' => $this->shop->id])]);
        expect(($this->events)())->toHaveCount(1);
    });
});
