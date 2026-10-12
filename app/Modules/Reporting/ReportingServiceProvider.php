<?php

namespace App\Modules\Reporting;

use App\Modules\Core\DTOs\MenuItem;
use App\Support\ModuleServiceProvider;

class ReportingServiceProvider extends ModuleServiceProvider
{
    protected function moduleKey(): string
    {
        return 'reporting';
    }

    protected function permissions(): array
    {
        return ['reporting.executive'];
    }

    protected function menu(): array
    {
        return [
            new MenuItem('reporting::ui.menu.dashboards', 'reporting.index', null, 'chart', 500, group: 'reporting'),
        ];
    }
}
