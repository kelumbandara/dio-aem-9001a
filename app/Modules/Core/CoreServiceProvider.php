<?php

namespace App\Modules\Core;

use App\Modules\Core\Console\CreateAdmin;
use App\Modules\Core\Console\MaintainAuditLog;
use App\Modules\Core\Console\PreflightCheck;
use App\Modules\Core\Console\PruneIdempotencyKeys;
use App\Modules\Core\Console\PurgeAttachments;
use App\Modules\Core\Console\RelayOutbox;
use App\Modules\Core\Contracts\Appearance;
use App\Modules\Core\Contracts\AttachmentScanner;
use App\Modules\Core\Contracts\AttachmentStore;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\BranchDirectory;
use App\Modules\Core\Contracts\CompanyInfo;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Core\Contracts\LicenceGate;
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
use App\Modules\Core\Services\ClamdAttachmentScanner;
use App\Modules\Core\Services\DatabaseAuditLogger;
use App\Modules\Core\Services\DatabaseNotifier;
use App\Modules\Core\Services\DatabaseNumberingService;
use App\Modules\Core\Services\DatabaseSettingsService;
use App\Modules\Core\Services\EloquentBranchDirectory;
use App\Modules\Core\Services\EloquentCompanyInfo;
use App\Modules\Core\Services\EloquentUserDirectory;
use App\Modules\Core\Services\NullAttachmentScanner;
use App\Modules\Core\Services\OpenLicenceGate;
use App\Modules\Core\Services\OutboxRecorder;
use App\Modules\Core\Services\SettingsAppearance;
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
            'core.company.manage',
            'core.user.view',
            'core.user.manage',
            'core.appearance.manage',
            'core.appearance.vendor', // held by nobody: only the super-admin (the vendor) passes
        ];
    }

    protected function menu(): array
    {
        return [
            new MenuItem('core::ui.menu.dashboard', 'dashboard', null, 'home', 10, group: 'main'),
            new MenuItem('core::ui.menu.password', 'account.password', null, 'shield', 990, group: 'main'),
            new MenuItem('core::ui.menu.modules', 'modules.index', 'core.module.manage', 'puzzle', 900, group: 'admin'),
            new MenuItem('core::ui.menu.company', 'company.edit', 'core.company.manage', 'building', 905, group: 'admin'),
            new MenuItem('core::ui.menu.appearance', 'appearance.edit', 'core.appearance.manage', 'palette', 906, group: 'admin'),
            new MenuItem('core::ui.menu.users', 'users.index', 'core.user.view', 'users', 903, group: 'admin'),
            new MenuItem('core::ui.menu.audit_log', 'audit-log.index', 'core.audit.view', 'shield', 910, group: 'admin'),
        ];
    }

    public function register(): void
    {
        $this->app->singleton(AuditLogger::class, DatabaseAuditLogger::class);
        $this->app->singleton(SettingsService::class, DatabaseSettingsService::class);
        $this->app->singleton(NumberingService::class, DatabaseNumberingService::class);
        $this->app->singleton(UserDirectory::class, EloquentUserDirectory::class);
        $this->app->singleton(BranchDirectory::class, EloquentBranchDirectory::class);
        $this->app->singleton(CompanyInfo::class, EloquentCompanyInfo::class);
        $this->app->singleton(SettingsAppearance::class);
        $this->app->singleton(Appearance::class, SettingsAppearance::class);
        // Replaced by the Licensing module when it is installed.
        $this->app->singleton(LicenceGate::class, OpenLicenceGate::class);
        $this->app->singleton(Notifier::class, DatabaseNotifier::class);
        $this->app->singleton(EventOutbox::class, OutboxRecorder::class);
        $this->app->singleton(AttachmentStore::class, AttachmentService::class);
        // ClamAV when CLAMAV_ENABLED=true (config core.attachments.clamav), otherwise scans are recorded as "skipped".
        $this->app->singleton(AttachmentScanner::class, fn () => config('core.attachments.clamav.enabled') ? new ClamdAttachmentScanner : new NullAttachmentScanner);
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
                PreflightCheck::class,
                CreateAdmin::class,
                MaintainAuditLog::class,
                RelayOutbox::class,
                PurgeAttachments::class,
            ]);
        }
    }
}
