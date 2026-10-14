<?php

namespace App\Modules\Core\Console;

use App\Modules\Core\Services\DatabaseBackupService;
use App\Modules\Core\Services\DatabaseRestoreService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

class RestoreDatabase extends Command
{
    protected $signature = 'database:restore {name? : File name of a saved copy (omit to list them)} {--force : Do not ask for confirmation}';

    protected $description = 'Put a saved database copy back as the live database (a safety copy is made first; the old database is kept)';

    public function handle(DatabaseBackupService $backups, DatabaseRestoreService $restore): int
    {
        $name = $this->argument('name');
        if ($name === null) {
            foreach ($backups->list() as $copy) {
                $this->line(sprintf('%s  %s  %s%s', $copy['made_at']->format('Y-m-d H:i'), str_pad(number_format($copy['bytes'] / 1024).' KB', 10), $copy['name'], $copy['safety'] ? '  (made before a restore)' : ''));
            }

            return self::SUCCESS;
        }

        $this->warn('Everything entered after this copy was made will be gone from the live system (it stays in the kept previous database).');
        if (! $this->option('force') && ! confirm("Restore {$name} now? Everyone will be signed out.", false)) {
            $this->info('Nothing was changed.');

            return self::SUCCESS;
        }

        try {
            $result = $restore->restore($name, null, 'console');
        } catch (BusinessException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Restored {$result['restored']}. Safety copy of what was live: {$result['safety_copy']}. Previous database kept as: {$result['previous_database']}.");

        return self::SUCCESS;
    }
}
