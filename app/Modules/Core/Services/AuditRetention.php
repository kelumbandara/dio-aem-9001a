<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\AuditLogger;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Audit retention without ever UPDATE/DELETE-ing audit rows (the append-only trigger forbids it):
 * on PostgreSQL the table is partitioned by month and expired months are dropped as whole
 * partitions. Granularity is a month, so entries live between N and N+1 months.
 *
 * Other engines have no trigger and no partitions; there expired rows are simply deleted.
 */
final class AuditRetention
{
    private const PARTITION_PATTERN = '/^audit_logs_y(\d{4})m(\d{2})$/';

    public function __construct(private readonly AuditLogger $audit) {}

    public function retentionMonths(): int
    {
        return max(1, (int) config('core.audit.retention_months', 3));
    }

    /**
     * Create the monthly partitions from this month through $monthsAhead months from now.
     *
     * @return list<string> names of partitions that were created
     */
    public function ensurePartitions(int $monthsAhead = 3, ?CarbonInterface $now = null): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        $created = [];
        $month = CarbonImmutable::instance($now ?? now())->utc()->startOfMonth();
        for ($i = 0; $i <= $monthsAhead; $i++) {
            if ($this->createPartitionFor($month->addMonths($i))) {
                $created[] = $this->partitionName($month->addMonths($i));
            }
        }

        return $created;
    }

    /** Create the partition holding $date's month; false when it already exists. */
    public function createPartitionFor(CarbonInterface $date): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        $start = CarbonImmutable::instance($date)->utc()->startOfMonth();
        $name = $this->partitionName($start);

        if (DB::selectOne('select to_regclass(?) as r', [$name])->r !== null) {
            return false;
        }

        DB::unprepared(sprintf(
            "CREATE TABLE %s PARTITION OF audit_logs FOR VALUES FROM ('%s') TO ('%s')",
            $name,
            $start->format('Y-m-d'),
            $start->addMonth()->format('Y-m-d'),
        ));

        return true;
    }

    /**
     * Remove audit data older than the retention period.
     *
     * @return list<string> dropped partitions (PostgreSQL) or a single "rows:<n>" marker
     */
    public function prune(?CarbonInterface $now = null): array
    {
        $cutoff = CarbonImmutable::instance($now ?? now())->utc()->subMonths($this->retentionMonths());

        if (DB::getDriverName() !== 'pgsql') {
            $deleted = DB::table('audit_logs')->where('occurred_at', '<', $cutoff)->delete();

            return $deleted > 0 ? ["rows:{$deleted}"] : [];
        }

        $dropped = [];
        foreach ($this->partitions() as $name) {
            if (preg_match(self::PARTITION_PATTERN, $name, $m) !== 1) {
                continue; // the default partition is never dropped
            }

            $upperBound = CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, 'UTC')->addMonth();
            if ($upperBound <= $cutoff) {
                DB::unprepared("DROP TABLE {$name}");
                $dropped[] = $name;
            }
        }

        if ($dropped !== []) {
            $this->audit->record('audit.partitions_dropped', null, [], [], [
                'partitions' => $dropped,
                'retention_months' => $this->retentionMonths(),
            ]);
        }

        return $dropped;
    }

    /** @return list<string> */
    private function partitions(): array
    {
        $rows = DB::select(<<<'SQL'
            select c.relname from pg_inherits i
            join pg_class c on c.oid = i.inhrelid
            join pg_class p on p.oid = i.inhparent
            where p.relname = 'audit_logs'
            order by c.relname
        SQL);

        return array_map(fn ($row) => $row->relname, $rows);
    }

    private function partitionName(CarbonInterface $month): string
    {
        return sprintf('audit_logs_y%04dm%02d', $month->year, $month->month);
    }
}
