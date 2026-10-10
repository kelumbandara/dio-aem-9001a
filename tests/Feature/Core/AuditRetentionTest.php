<?php

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditRetention;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function partitionNames(): array
{
    return collect(DB::select("select c.relname from pg_inherits i join pg_class c on c.oid = i.inhrelid join pg_class p on p.oid = i.inhparent where p.relname = 'audit_logs' order by 1"))
        ->pluck('relname')->all();
}

function auditRowAt(string $when, string $event = 'old.thing'): void
{
    DB::table('audit_logs')->insert(['occurred_at' => $when, 'event' => $event, 'channel' => 'system']);
}

beforeEach(function () {
    $this->retention = app(AuditRetention::class);
});

it('keeps the audit table partitioned by month with a default partition', function () {
    $names = partitionNames();
    $thisMonth = sprintf('audit_logs_y%04dm%02d', now()->utc()->year, now()->utc()->month);

    expect($names)->toContain('audit_logs_default')->toContain($thisMonth);
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'partitioning is PostgreSQL-only');

it('creates upcoming partitions once and leaves existing ones alone', function () {
    $now = CarbonImmutable::parse('2031-01-15 10:00:00', 'UTC');

    $created = $this->retention->ensurePartitions(2, $now);

    expect($created)->toBe(['audit_logs_y2031m01', 'audit_logs_y2031m02', 'audit_logs_y2031m03'])
        ->and($this->retention->ensurePartitions(2, $now))->toBe([])
        ->and(partitionNames())->toContain('audit_logs_y2031m02');

    // a row for that month lands in its own partition, not in default
    auditRowAt('2031-02-10 12:00:00', 'future.thing');
    expect(DB::table('audit_logs_y2031m02')->count())->toBe(1);
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'partitioning is PostgreSQL-only');

it('drops whole months older than the retention period and keeps the rest', function () {
    $now = CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC');   // retention 3 months -> cutoff 2026-07-10
    foreach (['2026-05-20', '2026-06-30', '2026-07-05', '2026-08-15', '2026-10-01'] as $day) {
        $this->retention->createPartitionFor(CarbonImmutable::parse($day));
        auditRowAt("{$day} 09:00:00", "row.{$day}");
    }

    $dropped = $this->retention->prune($now);

    expect($dropped)->toBe(['audit_logs_y2026m05', 'audit_logs_y2026m06'])
        ->and(partitionNames())->toContain('audit_logs_y2026m07')->toContain('audit_logs_y2026m08')->toContain('audit_logs_y2026m10')
        ->and(partitionNames())->not->toContain('audit_logs_y2026m05')
        ->and(DB::table('audit_logs')->where('event', 'like', 'row.%')->orderBy('event')->pluck('event')->all())
        ->toBe(['row.2026-07-05', 'row.2026-08-15', 'row.2026-10-01']);   // July is kept in full: it ends after the cutoff
    expect(AuditLog::where('event', 'audit.partitions_dropped')->sole()->metadata['partitions'])->toBe($dropped);
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'partitioning is PostgreSQL-only');

it('follows the configured retention', function () {
    config(['core.audit.retention_months' => 1]);
    $now = CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC');
    $this->retention->createPartitionFor(CarbonImmutable::parse('2026-08-10'));
    $this->retention->createPartitionFor(CarbonImmutable::parse('2026-09-10'));

    expect($this->retention->prune($now))->toBe(['audit_logs_y2026m08'])->and(partitionNames())->toContain('audit_logs_y2026m09');
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'partitioning is PostgreSQL-only');

it('never drops the default partition', function () {
    auditRowAt('2020-01-01 00:00:00', 'ancient');   // no partition for it: goes to default

    $dropped = $this->retention->prune(CarbonImmutable::parse('2030-01-01'));   // every dated partition is long expired

    expect($dropped)->not->toContain('audit_logs_default')
        ->and(partitionNames())->toBe(['audit_logs_default'])
        ->and(DB::table('audit_logs')->where('event', 'ancient')->count())->toBe(1);
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'partitioning is PostgreSQL-only');

it('still refuses UPDATE and DELETE on rows in new partitions', function () {
    $this->retention->createPartitionFor(CarbonImmutable::parse('2029-03-01'));
    auditRowAt('2029-03-02 00:00:00', 'protected');

    // each attempt in its own savepoint: PostgreSQL aborts the surrounding transaction after an error
    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('event', 'protected')->update(['event' => 'changed'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->where('event', 'protected')->delete()))->toThrow(QueryException::class);
    expect(DB::table('audit_logs')->where('event', 'protected')->count())->toBe(1);
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'append-only trigger is PostgreSQL-specific');

it('keeps writing audit entries through the application after partitioning', function () {
    $branch = Branch::factory()->create();

    expect(AuditLog::where('auditable_id', $branch->id)->where('event', 'created')->count())->toBe(1)
        ->and(AuditLog::latest('id')->first()->id)->toBeInt();
});

it('runs from the maintenance command', function () {
    $this->artisan('audit:maintain')
        ->expectsOutputToContain('retention 3 months')
        ->assertSuccessful();
});
