<?php

namespace App\Modules\Finance;

use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Core\DTOs\MenuItem;
use App\Modules\Core\Events\IntegrationEvent;
use App\Modules\Finance\Approvals\ManualJournalPost;
use App\Modules\Finance\Approvals\SupplierPaymentPost;
use App\Modules\Finance\Console\ReconcileFinance;
use App\Modules\Finance\Contracts\CreditNotePoster;
use App\Modules\Finance\Contracts\DebitNotePoster;
use App\Modules\Finance\Contracts\FinanceMetrics;
use App\Modules\Finance\Contracts\JournalPoster;
use App\Modules\Finance\Contracts\OpenItemPoster;
use App\Modules\Finance\Contracts\ReceivablePosition;
use App\Modules\Finance\Listeners\StockJournalListener;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\DatabaseFinanceMetrics;
use App\Modules\Finance\Services\DatabaseReceivablePosition;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Finance\Services\SupplierDebitNoteService;
use App\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

class FinanceServiceProvider extends ModuleServiceProvider
{
    protected function moduleKey(): string
    {
        return 'finance';
    }

    protected function permissions(): array
    {
        return [
            'finance.view', 'finance.account.manage', 'finance.mapping.manage', 'finance.bank.manage',
            'finance.period.manage', 'finance.period.lock',
            'finance.journal.post', 'finance.reverse',
            'finance.ar.view', 'finance.ar.receive', 'finance.ap.view', 'finance.ap.pay',
            'finance.report.view',
        ];
    }

    protected function menu(): array
    {
        return [
            new MenuItem('finance::ui.menu.accounts', 'finance.accounts.index', 'finance.view', 'book', 300, group: 'finance'),
            new MenuItem('finance::ui.menu.journals', 'finance.journals.index', 'finance.view', 'swap', 310, group: 'finance'),
            new MenuItem('finance::ui.menu.receivables', 'finance.receivables.index', 'finance.ar.view', 'building', 320, group: 'finance'),
            new MenuItem('finance::ui.menu.payables', 'finance.payables.index', 'finance.ap.view', 'truck', 330, group: 'finance'),
            new MenuItem('finance::ui.menu.reports', 'finance.reports.index', 'finance.report.view', 'chart', 340, group: 'finance'),
            new MenuItem('finance::ui.menu.periods', 'finance.periods.index', 'finance.view', 'clock', 350, group: 'finance'),
            new MenuItem('finance::ui.menu.setup', 'finance.setup.index', 'finance.view', 'cog', 360, group: 'finance'),
        ];
    }

    public function register(): void
    {
        $this->app->singleton(FinanceMetrics::class, DatabaseFinanceMetrics::class);
        $this->app->singleton(JournalPostingService::class);
        $this->app->singleton(JournalPoster::class, JournalPostingService::class);
        $this->app->singleton(OpenItemPoster::class, OpenItemService::class);
        $this->app->singleton(CreditNotePoster::class, CreditNoteService::class);
        $this->app->singleton(DebitNotePoster::class, SupplierDebitNoteService::class);
        $this->app->singleton(ReceivablePosition::class, DatabaseReceivablePosition::class);
    }

    public function boot(): void
    {
        parent::boot();

        $approvals = $this->app->make(ApprovalService::class);
        $approvals->registerAction(ManualJournalPost::class);
        $approvals->registerAction(SupplierPaymentPost::class);

        // Perpetual inventory: journals from stock movements, built from the event alone.
        Event::listen(IntegrationEvent::class, [StockJournalListener::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileFinance::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('finance:reconcile')->dailyAt('02:45')->withoutOverlapping(30);
        });
    }
}
