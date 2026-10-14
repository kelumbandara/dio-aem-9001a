<?php

namespace App\Modules\Core\Console;

use App\Modules\Core\Contracts\Notifier;
use App\Modules\Core\Contracts\UserDirectory;
use App\Modules\Core\DTOs\NotificationMessage;
use App\Modules\Core\Services\DatabaseBackupService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature = 'database:backup {--remind : Tell the administrators that a fresh copy is ready to download}';

    protected $description = 'Make a downloadable (not encrypted) copy of the database on the server';

    public function handle(DatabaseBackupService $backups, Notifier $notifier, UserDirectory $users): int
    {
        try {
            $name = $backups->create();
        } catch (BusinessException $e) {
            $this->error($e->getMessage());
            $this->tellAdmins($notifier, $users, 'backup_failed', 'danger');

            return self::FAILURE;
        }
        $this->info("Saved {$name}.");

        if ($this->option('remind')) {
            $this->tellAdmins($notifier, $users, 'backup_ready', 'info', ['file' => $name]);
        }

        return self::SUCCESS;
    }

    /** @param  array<string, scalar>  $params */
    private function tellAdmins(Notifier $notifier, UserDirectory $users, string $kind, string $level, array $params = []): void
    {
        $admins = array_values(array_unique([...$users->userIdsWithRole('admin'), ...$users->userIdsWithRole('super-admin')]));
        $notifier->notify($admins, new NotificationMessage("core::notifications.{$kind}", $params, route('backups.index', absolute: false), $level));
    }
}
