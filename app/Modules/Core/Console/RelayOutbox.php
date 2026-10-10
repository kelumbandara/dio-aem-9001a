<?php

namespace App\Modules\Core\Console;

use App\Modules\Core\Services\OutboxRelay;
use Illuminate\Console\Command;

class RelayOutbox extends Command
{
    protected $signature = 'outbox:relay {--limit= : Maximum events to publish in this run}';

    protected $description = 'Publish pending outbox events (at least once)';

    public function handle(OutboxRelay $relay): int
    {
        $result = $relay->run($this->option('limit') !== null ? (int) $this->option('limit') : null);

        $this->info("Outbox: {$result['published']} published, {$result['failed']} to retry, {$result['parked']} parked.");

        return $result['parked'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
