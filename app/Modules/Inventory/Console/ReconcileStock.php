<?php

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Services\StockReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileStock extends Command
{
    protected $signature = 'inventory:reconcile';

    protected $description = 'Check that stock balances and average costs agree with the stock ledger';

    public function handle(StockReconciler $reconciler): int
    {
        $problems = $reconciler->discrepancies();

        if ($problems === []) {
            $this->info('Stock agrees with the ledger.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }
        Log::critical('Stock reconciliation found '.count($problems).' discrepancy(ies).', ['first' => array_slice($problems, 0, 5)]);

        return self::FAILURE;
    }
}
