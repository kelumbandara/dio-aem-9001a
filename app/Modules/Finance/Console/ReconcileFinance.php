<?php

namespace App\Modules\Finance\Console;

use App\Modules\Finance\Services\FinanceReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileFinance extends Command
{
    protected $signature = 'finance:reconcile';

    protected $description = 'Check that the ledger balances and agrees with receivables and payables';

    public function handle(FinanceReconciler $reconciler): int
    {
        $problems = $reconciler->discrepancies();

        if ($problems === []) {
            $this->info('The books agree.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }
        Log::critical('Finance reconciliation found '.count($problems).' discrepancy(ies).', ['first' => array_slice($problems, 0, 5)]);

        return self::FAILURE;
    }
}
