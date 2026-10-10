<?php

namespace App\Modules\Core;

use App\Modules\Core\Console\MaintainAuditLog;
use App\Modules\Core\Console\PruneIdempotencyKeys;
use App\Modules\Core\Console\PurgeAttachments;
use App\Modules\Core\Console\RelayOutbox;
use App\Modules\Core\Contracts\AttachmentScanner;
use App\Modules\Core\Contracts\AttachmentStore;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Core\Contracts\Notifier;
use App\Modules\Core\Contracts\NumberingService;
use App\Modules\Core\Contracts\SettingsService;
use App\Modules\Core\Contracts\UserDirectory;
use App\Modules\Core\DTOs\MenuItem;
use App\Modules\Core\Http\Middleware\EnforceMfaEnrollment;
use App\Modules\Core\Http\Middleware\EnsureModuleEnabled;
use App\Modules\Core\Http\Middleware\RequireIdempotencyKey;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AttachmentService;
use App\Modules\Core\Services\DatabaseAuditLogger;
use App\Modules\Core\Services\DatabaseNotifier;
use App\Modules\Core\Services\DatabaseNumberingService;
use App\Modules\Core\Services\DatabaseSettingsService;
use App\Modules\Core\Services\EloquentUserDirectory;
use App\Modules\Core\Services\NullAttachmentScanner;
use App\Modules\Core\Services\OutboxRecorder;
use App\Support\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

class CoreServiceProvider extends ModuleServiceProvider
{
    public const SUPER_ADMIN_ROLE = 'super-admin';

    protected function moduleKey(): string
    {
        return 'core';
    }

    protected function permissions(): array
    {
        return [
            'core.branch.view',
            'core.branch.create',
            'core.branch.update',
            'core.branch.delete',
            'core.module.manage',
            'core.audit.view',
        ];
    }

    protected function menu(): array
    {
        return [
            new MenuItem('core::ui.menu.dashboard', 'dashboard', null, 'home', 10),
            new MenuItem('core::ui.menu.modules', 'modules.index', 'core.module.manage', 'puzzle', 900),
            new MenuItem('core::ui.menu.audit_log', 'audit-log.index', 'core.audit.view', 'shield', 910),
        ];
    }

    public function register(): void
    {
        $this->app->singleton(AuditLogger::class, DatabaseAuditLogger::class);
        $this->app->singleton(SettingsService::class, DatabaseSettingsService::class);
        $this->app->singleton(NumberingService::class, DatabaseNumberingService::class);
        $this->app->singleton(UserDirectory::class, EloquentUserDirectory::class);
        $this->app->singleton(Notifier::class, DatabaseNotifier::class);
        $this->app->singleton(EventOutbox::class, OutboxRecorder::class);
        $this->app->singleton(AttachmentStore::class, AttachmentService::class);
        // Replace with a real scanner (e.g. ClamAV) to turn on virus scanning; see AttachmentScanner.
        $this->app->singleton(AttachmentScanner::class, NullAttachmentScanner::class);
    }

    public function boot(): void
    {
        parent::boot();

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('module', EnsureModuleEnabled::class);
        $router->aliasMiddleware('mfa.enrolled', EnforceMfaEnrollment::class);
        $router->aliasMiddleware('idempotent', RequireIdempotencyKey::class);

        Blade::anonymousComponentPath(__DIR__.'/Views/components', 'core');

        // Super admins pass every gate; everyone else goes through normal permission checks.
        Gate::before(fn (User $user) => $user->hasRole(self::SUPER_ADMIN_ROLE) ? true : null);

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute((int) config('core.auth.login_attempts_per_minute'))
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute((int) config('core.auth.api_requests_per_minute'))
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneIdempotencyKeys::class,
                MaintainAuditLog::class,
                RelayOutbox::class,
                PurgeAttachments::class,
            ]);
        }
    }
}
