<?php

namespace App\Modules\Licensing;

use App\Modules\Core\Contracts\LicenceGate;
use App\Modules\Core\DTOs\MenuItem;
use App\Modules\Licensing\Console\LicenceCheck;
use App\Modules\Licensing\Console\LicenceIssue;
use App\Modules\Licensing\Console\LicenceKeys;
use App\Modules\Licensing\Http\Middleware\EnforceLicence;
use App\Modules\Licensing\Services\LicenceService;
use App\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;

class LicensingServiceProvider extends ModuleServiceProvider
{
    protected function moduleKey(): string
    {
        return 'licensing';
    }

    protected function permissions(): array
    {
        return ['licensing.manage'];
    }

    protected function menu(): array
    {
        return [new MenuItem('licensing::ui.menu.licence', 'licence.show', 'licensing.manage', 'key', 920)];
    }

    public function register(): void
    {
        $this->app->singleton(LicenceService::class);
        // Replaces the open gate Core installs, so warnings and plan limits take effect.
        $this->app->singleton(LicenceGate::class, LicenceService::class);
    }

    public function boot(): void
    {
        parent::boot();

        $router = $this->app->make(Router::class);
        $router->pushMiddlewareToGroup('web', EnforceLicence::class);
        $router->pushMiddlewareToGroup('api', EnforceLicence::class);

        if ($this->app->runningInConsole()) {
            $this->commands([LicenceKeys::class, LicenceIssue::class, LicenceCheck::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('licence:check')->dailyAt('06:00')->withoutOverlapping(30);
        });
    }
}
