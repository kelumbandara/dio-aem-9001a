<?php

namespace App\Modules\Approvals\Console;

use App\Modules\Approvals\Services\ApprovalEngine;
use Illuminate\Console\Command;

class ExpireApprovals extends Command
{
    protected $signature = 'approvals:expire';

    protected $description = 'Expire approval requests that passed their deadline';

    public function handle(ApprovalEngine $engine): int
    {
        $this->info('Expired '.$engine->expireDue().' approval request(s).');

        return self::SUCCESS;
    }
}
