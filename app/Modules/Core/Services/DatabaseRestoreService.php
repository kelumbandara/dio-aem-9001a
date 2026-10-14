<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\Process\Process;

/**
 * Puts a saved database copy back as the live database, in a way that can be undone.
 *
 *  1. A "before restore" safety copy of the live database is saved (it is itself a downloadable, restorable copy).
 *  2. The chosen copy is loaded into a NEW scratch database and checked; the live database is not touched yet.
 *  3. The system is put in maintenance mode, connections are shut out, and the two databases swap names in one step
 *     (live -> `<name>_before_restore_<time>`, scratch -> live). The previous live database is kept, whole.
 *  4. The schema is brought up to date (a copy from an older release is migrated forward), roles and permissions are
 *     re-applied, caches are cleared and maintenance mode ends.
 * If anything fails before the swap, nothing changed; if the migration fails after it, the swap is reversed.
 * Everyone is signed out afterwards (the sessions table is part of the database).
 */
class DatabaseRestoreService
{
    public function __construct(private readonly DatabaseBackupService $backups, private readonly AuditLogger $audit) {}

    /**
     * @return array{restored: string, safety_copy: string, previous_database: string}
     *
     * @throws BusinessException
     */
    public function restore(string $name, ?int $by = null, ?string $byLabel = null): array
    {
        set_time_limit(0);
        $file = $this->backups->absolutePath($name);   // 404 unless it is one of our copies
        $live = $this->liveName();
        $stamp = now('UTC')->format('YmdHis');
        $scratch = "{$live}_restore_{$stamp}";
        $previous = "{$live}_before_restore_{$stamp}";

        $this->assertCompatible($file);
        $safety = $this->backups->create($by, safety: true);   // BusinessException core.backup_failed if it cannot be made

        try {
            $this->createDatabase($scratch);
            $this->loadInto($scratch, $file);
            $this->assertLooksRight($scratch);
        } catch (\Throwable $e) {
            $this->tryDrop($scratch);
            report($e);
            throw $e instanceof BusinessException ? $e : new BusinessException('core.restore_failed', 'The backup could not be loaded. Nothing was changed; see the server log.', 500);
        }

        $this->goDown();
        try {
            $this->swap($live, $scratch, $previous);
            try {
                $this->bringUpToDate();
            } catch (\Throwable $e) {
                report($e);
                $this->swap($live, $previous, $scratch);   // reverse: the previous database becomes live again
                throw new BusinessException('core.restore_failed', 'The backup was loaded but could not be brought up to date, so the restore was reversed. Nothing was changed; see the server log.', 500);
            }
        } finally {
            $this->goUp();
        }

        $this->audit->record('core.backup_restored', null, [], [], ['file' => $name, 'safety_copy' => $safety, 'previous_database' => $previous, 'by' => $by, 'by_name' => $byLabel]);
        $this->dropOldDatabases($live);

        return ['restored' => $name, 'safety_copy' => $safety, 'previous_database' => $previous];
    }

    /** A copy made by a NEWER release than this code cannot be loaded: it has changes this code does not know. */
    protected function assertCompatible(string $file): void
    {
        $known = DB::table('migrations')->pluck('migration')->all();
        $unknown = array_values(array_diff($this->migrationsIn($file), $known));
        if ($unknown !== []) {
            throw new BusinessException('core.restore_incompatible', 'That backup was made by a newer version of the system than the one running now ('.count($unknown).' unknown change(s)). Deploy the newer version first. Nothing was changed.', 422);
        }
    }

    /** @return list<string> names recorded in the copy's migrations table */
    protected function migrationsIn(string $file): array
    {
        $out = $this->run([(string) config('core.backups.pg_restore', 'pg_restore'), '--data-only', '--table=migrations', '--file=-', $file]);
        $names = [];
        $inside = false;
        foreach (preg_split('/\R/', $out) as $line) {
            if (str_starts_with($line, 'COPY ') && str_contains($line, 'migrations')) {
                $inside = true;

                continue;
            }
            if ($inside && $line === '\.') {
                break;
            }
            if ($inside) {
                $cols = explode("\t", $line);
                if (count($cols) >= 2) {
                    $names[] = $cols[1];
                }
            }
        }
        if ($names === []) {
            throw new BusinessException('core.restore_invalid', 'That file is not a copy of this system\'s database.', 422);
        }

        return $names;
    }

    protected function createDatabase(string $name): void
    {
        $this->admin()->exec('CREATE DATABASE '.$this->ident($name));
    }

    protected function loadInto(string $database, string $file): void
    {
        $c = $this->connection();
        $this->run([(string) config('core.backups.pg_restore', 'pg_restore'), '--no-owner', '--no-privileges', '--exit-on-error',
            '--host', (string) $c['host'], '--port', (string) $c['port'], '--username', (string) $c['username'], '--dbname', $database, $file]);
    }

    /** The scratch database must hold the system's own tables and at least one user. */
    protected function assertLooksRight(string $database): void
    {
        $pdo = $this->connect($database);
        $users = (int) $pdo->query('select count(*) from users')->fetchColumn();
        $migrations = (int) $pdo->query('select count(*) from migrations')->fetchColumn();
        if ($users < 1 || $migrations < 1) {
            throw new BusinessException('core.restore_invalid', 'That backup holds no users or no migration history, so it was not used.', 422);
        }
    }

    /** live -> $oldName, $incoming -> live, with connections shut out meanwhile. */
    protected function swap(string $live, string $incoming, string $oldName): void
    {
        DB::disconnect();
        $admin = $this->admin();
        $admin->exec('ALTER DATABASE '.$this->ident($live).' ALLOW_CONNECTIONS false');
        try {
            for ($attempt = 1; ; $attempt++) {
                $admin->prepare('select pg_terminate_backend(pid) from pg_stat_activity where datname in (?, ?) and pid <> pg_backend_pid()')->execute([$live, $incoming]);
                try {
                    // Both renames or neither: never a moment when the live name points nowhere for good.
                    $admin->beginTransaction();
                    $admin->exec('ALTER DATABASE '.$this->ident($live).' RENAME TO '.$this->ident($oldName));
                    $admin->exec('ALTER DATABASE '.$this->ident($incoming).' RENAME TO '.$this->ident($live));
                    $admin->commit();
                    break;
                } catch (\PDOException $e) {
                    if ($admin->inTransaction()) {
                        $admin->rollBack();
                    }
                    if ($attempt >= 5) {
                        throw $e;
                    }
                    usleep(500_000);
                }
            }
        } catch (\Throwable $e) {
            $this->tryAllowConnections($admin, $live);
            throw $e;
        }
        $this->tryAllowConnections($admin, $live);
        DB::purge();
    }

    protected function bringUpToDate(): void
    {
        DB::purge();
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('db:seed', ['--class' => 'App\\Modules\\Core\\Database\\Seeders\\RolesAndPermissionsSeeder', '--force' => true]);
        $this->artisan('permission:cache-reset');
        $this->artisan('cache:clear');
    }

    protected function goDown(): void
    {
        $this->artisan('down', ['--retry' => 60]);
    }

    protected function goUp(): void
    {
        $this->artisan('up');
    }

    /** The two newest "before restore" databases are kept (so a restore can be undone by hand); older ones are dropped. */
    protected function dropOldDatabases(string $live): void
    {
        $stmt = $this->admin()->prepare('select datname from pg_database where datname like ? order by datname desc');
        $stmt->execute([str_replace('_', '\_', $live).'\_before\_restore\_%']);
        foreach (array_slice($stmt->fetchAll(PDO::FETCH_COLUMN), 2) as $old) {
            $this->tryDrop((string) $old);
        }
    }

    protected function tryDrop(string $database): void
    {
        try {
            $admin = $this->admin();
            $admin->prepare('select pg_terminate_backend(pid) from pg_stat_activity where datname = ? and pid <> pg_backend_pid()')->execute([$database]);
            $admin->exec('DROP DATABASE IF EXISTS '.$this->ident($database));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function tryAllowConnections(PDO $admin, string $database): void
    {
        try {
            $admin->exec('ALTER DATABASE '.$this->ident($database).' ALLOW_CONNECTIONS true');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @param  array<string, mixed>  $arguments */
    private function artisan(string $command, array $arguments = []): void
    {
        $code = Artisan::call($command, $arguments);
        if ($code !== 0) {
            throw new \RuntimeException("artisan {$command} failed: ".trim(Artisan::output()));
        }
    }

    /** @param  list<string>  $command */
    private function run(array $command): string
    {
        $c = $this->connection();
        $process = new Process($command, null, ['PGPASSWORD' => (string) $c['password'], 'PGSSLMODE' => (string) ($c['sslmode'] ?? 'prefer')], null, (float) config('core.backups.timeout', 600) * 3);
        $process->mustRun();

        return $process->getOutput();
    }

    /** @return array<string, mixed> */
    private function connection(): array
    {
        return (array) config('database.connections.'.config('database.default'));
    }

    private function liveName(): string
    {
        $name = (string) $this->connection()['database'];
        if (preg_match('/^[A-Za-z0-9_]{1,40}$/', $name) !== 1) {
            throw new BusinessException('core.restore_failed', 'The database name is not one this tool can handle safely.', 500);
        }

        return $name;
    }

    /** A connection to the server's maintenance database (the live one cannot be renamed from inside itself). */
    protected function admin(): PDO
    {
        return $this->connect('postgres');
    }

    protected function connect(string $database): PDO
    {
        $c = $this->connection();
        $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$database};sslmode=".($c['sslmode'] ?? 'prefer'), (string) $c['username'], (string) $c['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function ident(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,63}$/', $name) !== 1) {
            throw new \InvalidArgumentException('Unsafe database name.');
        }

        return '"'.$name.'"';
    }
}
