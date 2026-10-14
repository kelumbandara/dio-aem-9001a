<?php

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DatabaseBackupService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

/** A service whose "pg_dump" writes a tiny PostgreSQL-looking archive, or fails, so no database tool is needed to test the rest. */
function fakeDumper(bool $works = true, string $content = "PGDMP\x01fake-archive"): void
{
    app()->bind(DatabaseBackupService::class, fn () => new class(app(AuditLogger::class), $works, $content) extends DatabaseBackupService
    {
        public function __construct(AuditLogger $audit, private bool $works, private string $content)
        {
            parent::__construct($audit);
        }

        protected function dump(string $file): void
        {
            if (! $this->works) {
                file_put_contents($file, 'half written');
                throw new RuntimeException('pg_dump: connection refused');
            }
            file_put_contents($file, $this->content);
        }
    });
}

beforeEach(function () {
    Storage::fake('local');
    $this->manager = userWithPermissions('core.backup.manage');
});

describe('making and listing copies', function () {
    it('saves a copy with its checksum, lists it and writes an audit entry', function () {
        fakeDumper();
        $this->actingAs($this->manager)->post('/admin/backups')->assertRedirect()->assertSessionHas('status');

        $files = Storage::disk('local')->files('db-backups');
        expect($files)->toHaveCount(2);
        $dump = collect($files)->first(fn ($f) => str_ends_with($f, '.dump'));
        expect(Storage::disk('local')->get($dump.'.sha256'))->toBe(hash('sha256', "PGDMP\x01fake-archive")."\n");

        $this->actingAs($this->manager)->get('/admin/backups')->assertOk()->assertSee(basename($dump))->assertSee(__('core::ui.backups.warning'));
        expect(AuditLog::where('event', 'core.backup_created')->count())->toBe(1);
    });

    it('keeps nothing when the dump fails, and says so', function () {
        fakeDumper(works: false);
        $this->actingAs($this->manager)->post('/admin/backups')->assertSessionHasErrors('backup');

        expect(Storage::disk('local')->allFiles('db-backups'))->toBe([])->and(AuditLog::where('event', 'core.backup_created')->count())->toBe(0);
    });

    it('refuses a file that is not a PostgreSQL archive', function () {
        fakeDumper(content: 'not a dump');
        $this->actingAs($this->manager)->post('/admin/backups')->assertSessionHasErrors('backup');

        expect(Storage::disk('local')->allFiles('db-backups'))->toBe([]);
    });

    it('keeps only the newest copies', function () {
        config(['core.backups.keep' => 3]);
        fakeDumper();
        $disk = Storage::disk('local');
        foreach (range(1, 5) as $day) {
            $name = sprintf('db-backups/dio_db-2026100%dT080000Z.dump', $day);
            $disk->put($name, 'PGDMP');
            $disk->put($name.'.sha256', 'x');
            touch($disk->path($name), strtotime("2026-10-0{$day} 08:00:00 UTC"));
        }
        app(DatabaseBackupService::class)->prune();

        expect(collect(app(DatabaseBackupService::class)->list())->pluck('name')->all())->toBe(['dio_db-20261005T080000Z.dump', 'dio_db-20261004T080000Z.dump', 'dio_db-20261003T080000Z.dump'])
            ->and($disk->exists('db-backups/dio_db-20261001T080000Z.dump.sha256'))->toBeFalse();
    });
});

describe('downloading', function () {
    it('streams the file and records who took it', function () {
        fakeDumper();
        $name = app(DatabaseBackupService::class)->create($this->manager->id);

        $response = $this->actingAs($this->manager)->get("/admin/backups/{$name}/download")->assertOk();
        expect($response->streamedContent())->toBe("PGDMP\x01fake-archive")->and($response->headers->get('Content-Disposition'))->toContain($name);
        $entry = AuditLog::where('event', 'core.backup_downloaded')->sole();
        expect($entry->user_id)->toBe($this->manager->id)->and($entry->metadata['file'])->toBe($name);
    });

    it('serves only our own copies', function (string $name) {
        Storage::disk('local')->put('secret.txt', 'secret');
        $this->actingAs($this->manager)->get('/admin/backups/'.rawurlencode($name).'/download')->assertNotFound();
    })->with(['a path' => '..%2Fsecret.txt', 'another file' => 'secret.txt', 'a missing copy' => 'dio_db-20200101T000000Z.dump', 'the checksum file' => 'dio_db-20200101T000000Z.dump.sha256']);
});

describe('who may use it', function () {
    it('is for administrators with the permission only', function () {
        $nobody = userWithPermissions('core.audit.view');
        $this->actingAs($nobody)->get('/admin/backups')->assertForbidden();
        $this->actingAs($nobody)->post('/admin/backups')->assertForbidden();
        $this->actingAs($nobody)->get('/admin/backups/dio_db-20200101T000000Z.dump/download')->assertForbidden();
        $this->actingAs($this->manager)->get('/admin/backups')->assertOk();
    });

    it('is held by the admin role and the super-admin', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs(adminUser('admin'))->get('/admin/backups')->assertOk();
        $this->actingAs(adminUser('super-admin'))->get('/admin/backups')->assertOk();
        $this->actingAs(userWithRole('sales'))->get('/admin/backups')->assertForbidden();
    });
});

describe('every Monday', function () {
    it('is scheduled for Monday 08:00 Sri Lanka time', function () {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'database:backup'));

        expect($event->expression)->toBe('0 8 * * 1')->and($event->timezone)->toBe('Asia/Colombo');
    });

    it('makes a copy and tells the administrators', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        fakeDumper();
        $admin = User::factory()->withRole('admin')->create();
        $sales = userWithRole('sales');

        $this->artisan('database:backup --remind')->assertSuccessful();

        expect($admin->notifications)->toHaveCount(1)->and($sales->notifications)->toHaveCount(0)
            ->and($admin->notifications->first()->data['url'] ?? null)->toBe('/admin/backups');
    });

    it('tells the administrators when it fails', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        fakeDumper(works: false);
        $admin = User::factory()->withRole('admin')->create();

        $this->artisan('database:backup --remind')->assertFailed();

        expect($admin->notifications)->toHaveCount(1);
    });
});
