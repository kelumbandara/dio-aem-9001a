<?php

namespace App\Modules\Manufacturing;

use App\Modules\Core\DTOs\MenuItem;
use App\Support\ModuleServiceProvider;

class ManufacturingServiceProvider extends ModuleServiceProvider
{
    protected function moduleKey(): string
    {
        return 'manufacturing';
    }

    protected function permissions(): array
    {
        return [
            'manufacturing.view',
            'manufacturing.setup.manage',
            'manufacturing.bom.manage', 'manufacturing.bom.activate',
            'manufacturing.order.manage', 'manufacturing.order.release',
            'manufacturing.issue.post', 'manufacturing.issue.over',
            'manufacturing.output.report', 'manufacturing.output.void', 'manufacturing.downtime.report',
            'manufacturing.qc.inspect', 'manufacturing.receipt.post', 'manufacturing.order.complete',
        ];
    }

    protected function menu(): array
    {
        return [
            new MenuItem('manufacturing::ui.menu.report', 'manufacturing.report.index', 'manufacturing.output.report', 'check-circle', 395, group: 'manufacturing'),
            new MenuItem('manufacturing::ui.menu.orders', 'manufacturing.orders.index', 'manufacturing.view', 'cog', 400, group: 'manufacturing'),
            new MenuItem('manufacturing::ui.menu.boms', 'manufacturing.boms.index', 'manufacturing.view', 'book', 410, group: 'manufacturing'),
            new MenuItem('manufacturing::ui.menu.shifts', 'manufacturing.shifts.report', 'manufacturing.view', 'clock', 415, group: 'manufacturing'),
            new MenuItem('manufacturing::ui.menu.setup', 'manufacturing.setup.index', 'manufacturing.view', 'building', 420, group: 'manufacturing'),
        ];
    }
}
