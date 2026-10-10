<?php

namespace App\Modules\Approvals;

use App\Modules\Approvals\Console\ExpireApprovals;
use App\Modules\Approvals\Console\RemindApprovers;
use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Approvals\Demo\DemoExpense;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Services\ApprovableRegistry;
use App\Modules\Approvals\Services\ApprovalEngine;
use App\Modules\Approvals\Services\ApproverResolver;
use App\Modules\Approvals\Services\PayloadHasher;
use App\Modules\Approvals\Services\PolicyResolver;
use App\Modules\Core\DTOs\MenuItem;
use App\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;

class ApprovalsServiceProvider extends ModuleServiceProvider
{
    protected function moduleKey(): string
    {
        return 'approvals';
    }

    protected function permissions(): array
    {
        return [
            'approvals.policy.manage',
            'approvals.authority.manage',
            'approvals.request.view-all',
            'approvals.request.manage', // cancel any request, retry failed executions
        ];
    }

    protected function menu(): array
    {
        $demo = $this->app->environment('local')
            ? [new MenuItem('approvals::ui.menu.demo', 'approvals.demo', null, 'puzzle', 990)]
            : [];

        return [...$demo,
            new MenuItem('approvals::ui.menu.inbox', 'approvals.index', null, 'check-circle', 20),
            new MenuItem('approvals::ui.menu.delegations', 'approvals.delegations.index', null, 'users', 25),
            new MenuItem('approvals::ui.menu.policies', 'approvals.policies.index', 'approvals.policy.manage', 'cog', 880),
            new MenuItem('approvals::ui.menu.authority', 'approvals.authority.index', 'approvals.authority.manage', 'scale', 885),
        ];
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config.php', 'approvals');

        $this->app->singleton(ApprovableRegistry::class);
        $this->app->singleton(PayloadHasher::class);
        $this->app->singleton(PolicyResolver::class);
        $this->app->singleton(ApproverResolver::class);
        $this->app->singleton(ApprovalEngine::class);
        $this->app->singleton(ApprovalService::class, fn ($app) => $app->make(ApprovalEngine::class));
    }

    public function boot(): void
    {
        parent::boot();

        // The API's `attachable_type` for attachments on approval requests.
        Relation::morphMap(['approval_request' => ApprovalRequest::class]);

        if ($this->app->environment('local')) {
            $this->app->make(ApprovalService::class)->registerAction(DemoExpense::class); // dev demo only
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireApprovals::class, RemindApprovers::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('approvals:expire')->hourly()->withoutOverlapping(10);
            $schedule->command('approvals:remind')->hourly()->withoutOverlapping(10);
        });
    }
}
