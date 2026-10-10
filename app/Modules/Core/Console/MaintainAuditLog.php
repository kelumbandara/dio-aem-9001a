<?php

namespace App\Modules\Core\Console;

use App\Modules\Core\Services\AuditRetention;
use Illuminate\Console\Command;

class MaintainAuditLog extends Command
{
    protected $signature = 'audit:maintain';

    protected $description = 'Create upcoming audit-log partitions and drop those past the retention period';

    public function handle(AuditRetention $retention): int
    {
        $created = $retention->ensurePartitions();
        $dropped = $retention->prune();

        $this->info(sprintf(
            'Audit log: %d partition(s) created, %d dropped (retention %d months).',
            count($created),
            count($dropped),
            $retention->retentionMonths(),
        ));

        return self::SUCCESS;
    }
}
