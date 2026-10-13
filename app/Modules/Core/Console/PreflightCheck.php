<?php

namespace App\Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * "Is this deployment safe to start?" — run by the container's entrypoint before the app starts (fail fast), and by hand after
 * changing settings. Errors stop the start (exit 1); warnings are printed and do not. Prints setting NAMES only, never values.
 *
 * Outside production the same checks run but only tell what would be wrong (always exit 0 unless --strict).
 */
class PreflightCheck extends Command
{
    protected $signature = 'app:preflight {--strict : Treat the checks as production checks whatever APP_ENV says} {--skip-services : Do not connect to the database, Redis or storage} {--allow-pending-migrations : The migrate step itself: migrations still to run are expected}';

    protected $description = 'Check that the settings and services of this deployment are safe for production';

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    public function handle(): int
    {
        $this->errors = $this->warnings = [];
        $production = $this->option('strict') || app()->environment('production');

        $this->settings();
        if (! $this->option('skip-services')) {
            $this->services();
        }

        foreach ($this->warnings as $w) {
            $this->warn('WARNING  '.$w);
        }
        foreach ($this->errors as $e) {
            $this->error('PROBLEM  '.$e);
        }

        if ($this->errors === []) {
            $this->info('Preflight passed'.($this->warnings === [] ? '.' : ' with '.count($this->warnings).' warning(s).'));

            return self::SUCCESS;
        }

        if (! $production) {
            $this->line('(Not production: these would stop a production start.)');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    private function settings(): void
    {
        $this->must(app()->environment('production'), 'APP_ENV must be "production".');
        $this->must(! config('app.debug'), 'APP_DEBUG must be false: debug pages show secrets and code.');
        $this->must(filled(config('app.key')), 'APP_KEY is not set.');
        $this->must(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL must start with https://.');
        $this->must((bool) config('session.secure'), 'SESSION_SECURE_COOKIE must be true (cookies only over https).');
        $this->must((bool) config('session.http_only'), 'SESSION_HTTP_ONLY must be true.');
        $this->must(config('queue.default') === 'redis', 'QUEUE_CONNECTION must be "redis" (approvals, messages and push run on the queue).');
        $this->must(config('cache.default') === 'redis', 'CACHE_STORE must be "redis" (rate limits and lockouts live in the cache).');
        $this->must(! in_array(config('mail.default'), ['log', 'array'], true), 'MAIL_MAILER sends nowhere ("'.config('mail.default').'"): set an SMTP mailer.');
        $this->must((string) config('database.default') === 'pgsql', 'DB_CONNECTION must be pgsql.');
        $this->must(config('database.connections.pgsql.sslmode', 'prefer') !== 'disable' || $this->local((string) config('database.connections.pgsql.host')), 'The database is not on this host and SSL is disabled (DB_SSLMODE).');
        $this->must((int) config('core.security.hsts_seconds') > 0, 'HSTS_SECONDS is 0: browsers are not told to insist on https.');
        $this->must(trim((string) config('core.security.trusted_proxies', '')) !== '', 'TRUSTED_PROXIES is empty: behind the proxy every visitor would look like one address (rate limits, audit log).');
        $this->must(config('licensing.enforce') !== false, 'LICENSING_ENFORCE is switched off.');

        $this->should(config('core.attachments.disk') !== 'attachments', 'ATTACHMENTS_DISK is the local disk: use object storage (s3) so a rebuilt container loses nothing.');
        $this->should((bool) config('core.attachments.clamav.enabled'), 'CLAMAV_ENABLED is false: uploaded files are not virus-scanned.');
        $this->should((bool) config('core.auth.check_breached'), 'PASSWORD_CHECK_BREACHED is off: known-breached passwords are accepted.');
        $this->should(filled(config('licensing.public_key')) || config('licensing.enforce') === false, 'LICENCE_PUBLIC_KEY is not set.');
        $this->should(blank(config('communications.whatsapp.token')) || filled(config('communications.whatsapp.app_secret')), 'WhatsApp is set up without WHATSAPP_APP_SECRET: webhooks cannot be verified.');
        $this->should(! config('app.debug') || ! app()->environment('production'), 'Debug is on in production.');
    }

    private function services(): void
    {
        try {
            DB::connection()->select('select 1');
            $reachable = true;
        } catch (Throwable $e) {
            $reachable = false;
            $this->errors[] = 'The database cannot be reached ('.class_basename($e).').';
        }
        if ($reachable && ! $this->option('allow-pending-migrations')) {
            $pending = $this->pendingMigrations();
            $this->must($pending === 0, "{$pending} migration(s) have not been run: php artisan migrate --force.");
        }

        if (config('queue.default') === 'redis' || config('cache.default') === 'redis') {
            try {
                Redis::connection()->ping();
            } catch (Throwable $e) {
                $this->errors[] = 'Redis cannot be reached ('.class_basename($e).').';
            }
        }
    }

    private function pendingMigrations(): int
    {
        $migrator = app('migrator');
        $paths = array_merge([database_path('migrations')], $migrator->paths());
        $files = array_keys($migrator->getMigrationFiles($paths));
        // A brand-new database has no migrations table yet: everything is still to run.
        $ran = $migrator->getRepository()->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        return count(array_diff($files, $ran));
    }

    private function must(bool $ok, string $message): void
    {
        if (! $ok) {
            $this->errors[] = $message;
        }
    }

    private function should(bool $ok, string $message): void
    {
        if (! $ok) {
            $this->warnings[] = $message;
        }
    }

    private function local(string $host): bool
    {
        return in_array($host, ['127.0.0.1', 'localhost', '::1', 'postgres', 'db'], true);
    }
}
