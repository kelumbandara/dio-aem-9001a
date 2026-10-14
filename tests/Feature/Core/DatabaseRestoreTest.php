<?php

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DatabaseBackupService;
use App\Modules\Core\Services\DatabaseRestoreService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;

/** A backup service whose dump is a tiny archive, so the restore can run without PostgreSQL's tools. */
function restoreFakeBackups(): void
{
    app()->bind(DatabaseBackupService::class, fn () => new class(app(AuditLogger::class)) extends DatabaseBackupService
    {
        protected function dump(string $file): void
        {
            file_put_contents($file, "PGDMP\x01live-now");
        }
    });
}

/**
 * A restore service that records every step instead of touching PostgreSQL. $fail names the step that should throw.
 *
 * @param  list<string>  $fail
 */
function restoreFakeService(array $fail = [], bool $newerCopy = false): object
{
    $service = new class(app(DatabaseBackupService::class), app(AuditLogger::class)) extends DatabaseRestoreService
    {
        /** @var list<string> */
        public array $steps = [];

        /** @var list<string> */
        public array $fail = [];

        public bool $newerCopy = false;

        private function step(string $name, string $detail = ''): void
        {
            $this->steps[] = trim($name.' '.$detail);
            if (in_array($name, $this->fail, true)) {
                throw new RuntimeException("{$name} failed");
            }
        }

        protected function migrationsIn(string $file): array
        {
            return [...DB::table('migrations')->pluck('migration')->all(), ...($this->newerCopy ? ['2099_01_01_000000_from_the_future'] : [])];
        }

        protected function createDatabase(string $name): void
        {
            $this->step('create', preg_replace('/\d{14}/', 'T', $name));
        }

        protected function loadInto(string $database, string $file): void
        {
            $this->step('load', preg_replace('/\d{14}/', 'T', $database));
        }

        protected function assertLooksRight(string $database): void
        {
            $this->step('check');
        }

        protected function swap(string $live, string $incoming, string $oldName): void
        {
            $this->step('swap', preg_replace('/\d{14}/', 'T', "{$live}<-{$incoming}, {$oldName}"));
        }

        protected function bringUpToDate(): void
        {
            $this->step('migrate');
        }

        protected function goDown(): void
        {
            $this->step('down');
        }

        protected function goUp(): void
        {
            $this->step('up');
        }

        protected function dropOldDatabases(string $live): void
        {
            $this->step('prune-old');
        }

        protected function tryDrop(string $database): void
        {
            $this->step('drop', preg_replace('/\d{14}/', 'T', $database));
        }
    };
    $service->fail = $fail;
    $service->newerCopy = $newerCopy;
    app()->instance(DatabaseRestoreService::class, $service);

    return $service;
}

beforeEach(function () {
    Storage::fake('local');
    restoreFakeBackups();
    $this->backups = app(DatabaseBackupService::class);
    $this->copy = $this->backups->create();   // the copy we will restore
    $this->live = (string) config('database.connections.'.config('database.default').'.database');
});

describe('putting a copy back', function () {
    it('saves a safety copy, loads and checks a scratch database, swaps it in, brings it up to date and ends maintenance', function () {
        $service = restoreFakeService();
        $result = $service->restore($this->copy, 7, 'Kelum');

        expect($service->steps)->toBe([
            'create '.$this->live.'_restore_T', 'load '.$this->live.'_restore_T', 'check', 'down',
            'swap '.$this->live.'<-'.$this->live.'_restore_T, '.$this->live.'_before_restore_T', 'migrate', 'up', 'prune-old',
        ]);
        expect($result['restored'])->toBe($this->copy)->and($result['safety_copy'])->toEndWith('-before-restore.dump')
            ->and(Storage::disk('local')->exists('db-backups/'.$result['safety_copy']))->toBeTrue();
        $entry = AuditLog::where('event', 'core.backup_restored')->sole();
        expect($entry->metadata['file'])->toBe($this->copy)->and($entry->metadata['safety_copy'])->toBe($result['safety_copy'])->and($entry->metadata['by_name'])->toBe('Kelum');
    });

    it('refuses a copy from a newer release before touching anything', function () {
        $service = restoreFakeService(newerCopy: true);

        expect(fn () => $service->restore($this->copy))->toThrow(BusinessException::class, 'newer version');
        expect($service->steps)->toBe([])->and(collect($this->backups->list())->where('safety', true))->toHaveCount(0);
    });

    it('serves only our own copies', function (string $name) {
        $service = restoreFakeService();

        expect(fn () => $service->restore($name))->toThrow(BusinessException::class, 'does not exist');
        expect($service->steps)->toBe([]);
    })->with(['a path' => '../.env', 'a missing copy' => 'dio_db-20200101T000000Z.dump']);

    it('changes nothing when the copy cannot be loaded, and removes the scratch database', function () {
        $service = restoreFakeService(['load']);

        expect(fn () => $service->restore($this->copy))->toThrow(BusinessException::class, 'Nothing was changed');
        expect($service->steps)->toBe(['create '.$this->live.'_restore_T', 'load '.$this->live.'_restore_T', 'drop '.$this->live.'_restore_T']);
        expect(AuditLog::where('event', 'core.backup_restored')->count())->toBe(0);
    });

    it('does not keep the system down when the swap fails', function () {
        $service = restoreFakeService(['swap']);

        expect(fn () => $service->restore($this->copy))->toThrow(RuntimeException::class);
        expect($service->steps)->toContain('down')->and(array_slice($service->steps, -1))->toBe(['up']);
        expect(AuditLog::where('event', 'core.backup_restored')->count())->toBe(0);
    });

    it('reverses the swap when the new database cannot be brought up to date', function () {
        $service = restoreFakeService(['migrate']);

        expect(fn () => $service->restore($this->copy))->toThrow(BusinessException::class, 'reversed');
        $swaps = array_values(array_filter($service->steps, fn ($s) => str_starts_with($s, 'swap')));
        expect($swaps)->toHaveCount(2)
            ->and($swaps[1])->toBe('swap '.$this->live.'<-'.$this->live.'_before_restore_T, '.$this->live.'_restore_T')   // the previous database becomes live again
            ->and(array_slice($service->steps, -1))->toBe(['up']);
        expect(AuditLog::where('event', 'core.backup_restored')->count())->toBe(0);
    });
});

describe('copies made by a newer PostgreSQL client', function () {
    it('drops only the setting an older server does not know', function () {
        $file = tempnam(sys_get_temp_dir(), 'strip-');
        file_put_contents($file, "SET statement_timeout = 0;\nSET transaction_timeout = 0;\nSET lock_timeout = 0;\nCREATE TABLE t (transaction_timeout int);\n-- SET transaction_timeout = 5;\n");

        restoreFakeService()->stripUnsupportedSettings($file);

        expect(file_get_contents($file))->toBe("SET statement_timeout = 0;\nSET lock_timeout = 0;\nCREATE TABLE t (transaction_timeout int);\n-- SET transaction_timeout = 5;\n");
        unlink($file);
    });
});

describe('on the screen', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->service = restoreFakeService();
        $this->boss = User::factory()->withRole('super-admin')->create();
        $this->secret = enrolMfa($this->boss);
        $this->password = 'a-long-test-password-1';
        $this->boss->forceFill(['password' => $this->password])->save();
        $this->go = fn (array $o = []) => $this->actingAs($this->boss)->post("/admin/backups/{$this->copy}/restore", array_merge([
            'confirm' => 'RESTORE', 'password' => $this->password, 'code' => (new Google2FA)->getCurrentOtp($this->secret),
        ], $o));
    });

    it('offers the button to the super-admin only, never to the admin', function () {
        $this->actingAs($this->boss)->get('/admin/backups')->assertOk()->assertSee(__('core::ui.backups.restore_title'));
        $this->actingAs(adminUser('admin'))->get('/admin/backups')->assertOk()->assertDontSee(__('core::ui.backups.restore_title'));
    });

    it('refuses everyone else outright', function () {
        $this->actingAs(adminUser('admin'))->post("/admin/backups/{$this->copy}/restore", ['confirm' => 'RESTORE', 'password' => 'x', 'code' => '123456'])->assertForbidden();
        expect($this->service->steps)->toBe([]);
    });

    it('needs the word, the password and a good authenticator code, and does nothing until all three are right', function (array $override, string $field) {
        ($this->go)($override)->assertSessionHasErrors($field);
        expect($this->service->steps)->toBe([]);
    })->with([
        'a wrong word' => [['confirm' => 'restore it'], 'confirm'],
        'a wrong password' => [['password' => 'not-my-password'], 'password'],
        'a wrong code' => [['code' => '000000'], 'code'],
        'no code' => [['code' => ''], 'code'],
    ]);

    it('restores, signs everyone out and sends the person to the sign-in page', function () {
        ($this->go)()->assertRedirect('/login')->assertSessionHas('status');

        expect($this->service->steps)->toContain('swap '.$this->live.'<-'.$this->live.'_restore_T, '.$this->live.'_before_restore_T');
        $this->assertGuest();
    });

    it('shows a business failure as an error and keeps the person signed in', function () {
        $this->service->fail = ['load'];
        ($this->go)()->assertSessionHasErrors('backup');

        $this->assertAuthenticated();
    });
});

describe('from the command line', function () {
    it('lists the copies, asks before it restores, and restores with --force', function () {
        $service = restoreFakeService();

        $this->artisan('database:restore')->expectsOutputToContain($this->copy)->assertSuccessful();
        $this->artisan('database:restore', ['name' => $this->copy])->expectsConfirmation('Restore '.$this->copy.' now? Everyone will be signed out.', 'no')->expectsOutputToContain('Nothing was changed')->assertSuccessful();
        expect($service->steps)->toBe([]);

        $this->artisan('database:restore', ['name' => $this->copy, '--force' => true])->expectsOutputToContain('Restored')->assertSuccessful();
        expect($service->steps)->toContain('migrate');
    });

    it('reports a refusal as a failure', function () {
        restoreFakeService(newerCopy: true);

        $this->artisan('database:restore', ['name' => $this->copy, '--force' => true])->assertFailed();
    });
});
