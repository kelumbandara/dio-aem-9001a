<?php

namespace App\Console\Commands;

use App\Services\InvoiceService;
use Illuminate\Console\Command;

class BillingInvoice extends Command
{
    protected $signature = 'billing:invoice';

    protected $description = 'Issue the monthly invoices that are due (scheduled daily)';

    public function handle(InvoiceService $invoices): int
    {
        $this->components->info($invoices->generateDue().' invoice(s) issued.');

        return self::SUCCESS;
    }
}
