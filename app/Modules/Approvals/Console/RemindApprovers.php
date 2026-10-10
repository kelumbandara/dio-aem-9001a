<?php

namespace App\Modules\Approvals\Console;

use App\Modules\Approvals\Services\ApprovalEngine;
use Illuminate\Console\Command;

class RemindApprovers extends Command
{
    protected $signature = 'approvals:remind';

    protected $description = 'Remind approvers of requests that have been waiting too long';

    public function handle(ApprovalEngine $engine): int
    {
        $this->info('Sent '.$engine->remindDue().' reminder(s).');

        return self::SUCCESS;
    }
}
