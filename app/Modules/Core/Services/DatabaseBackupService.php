<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Support\Exceptions\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Plain (not encrypted) copies of the database that an administrator can download, kept on the server's own disk.
 * Made with `pg_dump` in PostgreSQL's compressed custom format (restore with `pg_restore`, see docs/runbooks/backup-restore.md).
 * These sit beside, and do not replace, the nightly encrypted backups of the backup container.
 *
 * A copy is either a normal one (weekly, or made by hand) or a "before restore" safety copy, made automatically
 * just before a restore so that the restore itself can be undone. Each kind is pruned on its own.
 */
class DatabaseBackupService
{
    public const FOLDER = 'db-backups';

    public const SAFETY_SUFFIX = '-before-restore';

    private const NAME = '/^dio_db-\d{8}T\d{6}Z(-before-restore)?\.dump$/';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return list<array{name: string, bytes: int, made_at: CarbonImmutable, sha256: ?string, safety: bool}> newest first
     */
    public function list(): array
    {
        $disk = Storage::disk($this->disk());
        $rows = [];
        foreach ($disk->files(self::FOLDER) as $path) {
            if (preg_match(self::NAME, basename($path)) !== 1) {
                continue;
            }
            $rows[] = [
                'name' => basename($path),
                'bytes' => (int) $disk->size($path),
                'made_at' => CarbonImmutable::createFromTimestampUTC((int) $disk->lastModified($path)),
                'sha256' => $disk->exists($path.'.sha256') ? trim((string) $disk->get($path.'.sha256')) : null,
                'safety' => str_contains(basename($path), self::SAFETY_SUFFIX),
            ];
        }
        usort($rows, fn (array $a, array $b) => [$b['made_at'], $b['name']] <=> [$a['made_at'], $a['name']]);

        return $rows;
    }

    /** @throws BusinessException when the dump fails; nothing half-made is kept */
    public function create(?int $by = null, bool $safety = false): string
    {
        $disk = Storage::disk($this->disk());
        $disk->makeDirectory(self::FOLDER);

        $name = 'dio_db-'.now('UTC')->format('Ymd\THis\Z').($safety ? self::SAFETY_SUFFIX : '').'.dump';
        $final = self::FOLDER.'/'.$name;
        $partial = $final.'.partial';
        $partialFile = $disk->path($partial);

        try {
            $this->dump($partialFile);
            $this->assertDump($partialFile);
            $sha256 = hash_file('sha256', $partialFile);
            $disk->move($partial, $final);
            $disk->put($final.'.sha256', $sha256."\n");
        } catch (\Throwable $e) {
            $disk->delete($partial);
            report($e);
            throw new BusinessException('core.backup_failed', 'The backup could not be made. Nothing was saved; see the server log.', 500);
        }

        $this->audit->record('core.backup_created', null, [], [], ['file' => $name, 'bytes' => (int) $disk->size($final), 'sha256' => $sha256, 'by' => $by]);
        $this->prune();

        return $name;
    }

    /** The path of a stored copy; the name must be exactly one of ours (no folders, no guessing). */
    public function path(string $name): string
    {
        if (preg_match(self::NAME, $name) !== 1 || ! Storage::disk($this->disk())->exists(self::FOLDER.'/'.$name)) {
            throw new BusinessException('core.backup_not_found', 'That backup does not exist.', 404);
        }

        return self::FOLDER.'/'.$name;
    }

    /** The real file on disk, for the restore tools. */
    public function absolutePath(string $name): string
    {
        return Storage::disk($this->disk())->path($this->path($name));
    }

    public function disk(): string
    {
        return (string) config('core.backups.disk', 'local');
    }

    /** Keep the newest copies of each kind only. */
    public function prune(): int
    {
        $disk = Storage::disk($this->disk());
        $removed = 0;
        foreach ([false => (int) config('core.backups.keep', 8), true => (int) config('core.backups.keep_safety', 5)] as $safety => $keep) {
            $copies = array_values(array_filter($this->list(), fn (array $c) => $c['safety'] === (bool) $safety));
            foreach (array_slice($copies, $keep) as $old) {
                $disk->delete([self::FOLDER.'/'.$old['name'], self::FOLDER.'/'.$old['name'].'.sha256']);
                $removed++;
            }
        }

        return $removed;
    }

    /** Writes the dump to $file. Overridden in tests. */
    protected function dump(string $file): void
    {
        $c = config('database.connections.'.config('database.default'));
        $process = new Process(
            [(string) config('core.backups.pg_dump', 'pg_dump'), '--format=custom', '--no-owner', '--no-privileges', '--file', $file,
                '--host', (string) $c['host'], '--port', (string) $c['port'], '--username', (string) $c['username'], (string) $c['database']],
            null,
            ['PGPASSWORD' => (string) $c['password'], 'PGSSLMODE' => (string) ($c['sslmode'] ?? 'prefer')],
            null,
            (float) config('core.backups.timeout', 600),
        );
        $process->mustRun();
    }

    private function assertDump(string $file): void
    {
        $head = is_file($file) ? (string) file_get_contents($file, false, null, 0, 5) : '';
        if ($head !== 'PGDMP') {
            throw new \RuntimeException('pg_dump did not produce a PostgreSQL archive.');
        }
    }
}
