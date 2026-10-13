<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/** A deployment that satisfies every production check (services are skipped: the tests have their own database). */
function goodProductionSettings(): void
{
    config([
        'app.env' => 'production', 'app.debug' => false, 'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.url' => 'https://erp.example.lk',
        'session.secure' => true, 'session.http_only' => true, 'queue.default' => 'redis', 'cache.default' => 'redis', 'mail.default' => 'smtp',
        'database.default' => 'pgsql', 'database.connections.pgsql.sslmode' => 'require', 'core.security.hsts_seconds' => 31536000,
        'licensing.enforce' => true, 'licensing.public_key' => 'abc', 'core.attachments.disk' => 's3', 'core.attachments.clamav.enabled' => true,
        'core.auth.check_breached' => true,
    ]);
    config(['core.security.trusted_proxies' => '*']);
    app()->detectEnvironment(fn () => 'production');
}

function preflight(array $options = []): array
{
    $code = Artisan::call('app:preflight', ['--skip-services' => true] + $options);

    return [$code, Artisan::output()];
}

it('passes a well-configured production deployment without warnings', function () {
    goodProductionSettings();
    [$code, $out] = preflight();
    expect($code)->toBe(0)->and($out)->toContain('Preflight passed.')->and($out)->not->toContain('WARNING')->and($out)->not->toContain('PROBLEM');
});

it('refuses to start production for each unsafe setting, naming the setting and never its value', function () {
    $cases = [
        'APP_DEBUG' => ['app.debug' => true], 'APP_KEY' => ['app.key' => ''], 'APP_URL' => ['app.url' => 'http://erp.example.lk'],
        'SESSION_SECURE_COOKIE' => ['session.secure' => false], 'QUEUE_CONNECTION' => ['queue.default' => 'sync'], 'CACHE_STORE' => ['cache.default' => 'array'],
        'MAIL_MAILER' => ['mail.default' => 'log'], 'DB_CONNECTION' => ['database.default' => 'mysql'], 'HSTS_SECONDS' => ['core.security.hsts_seconds' => 0],
        'LICENSING_ENFORCE' => ['licensing.enforce' => false],
    ];
    foreach ($cases as $name => $override) {
        goodProductionSettings();
        config($override);
        [$code, $out] = preflight();
        expect($code)->toBe(1, "{$name} should stop the start")->and($out)->toContain($name);
    }
});

it('refuses a remote database without SSL, but accepts a local one', function () {
    goodProductionSettings();
    config(['database.connections.pgsql.sslmode' => 'disable', 'database.connections.pgsql.host' => 'db.example.com']);
    expect(preflight()[0])->toBe(1);
    config(['database.connections.pgsql.host' => 'postgres']);
    expect(preflight()[0])->toBe(0);
});

it('refuses proxies that were not declared', function () {
    goodProductionSettings();
    config(['core.security.trusted_proxies' => '']);
    [$code, $out] = preflight();
    expect($code)->toBe(1)->and($out)->toContain('TRUSTED_PROXIES');
});

it('only warns about the things that weaken but do not break: local file storage, no virus scan, no breach check', function () {
    goodProductionSettings();
    config(['core.attachments.disk' => 'attachments', 'core.attachments.clamav.enabled' => false, 'core.auth.check_breached' => false]);
    [$code, $out] = preflight();
    expect($code)->toBe(0)->and($out)->toContain('ATTACHMENTS_DISK')->and($out)->toContain('CLAMAV_ENABLED')->and($out)->toContain('PASSWORD_CHECK_BREACHED');
});

it('does not stop a development machine, but --strict makes it behave like production', function () {
    [$code, $out] = preflight();
    expect($code)->toBe(0)->and($out)->toContain('would stop a production start');
    expect(preflight(['--strict' => true])[0])->toBe(1);
});

it('never prints a secret', function () {
    goodProductionSettings();
    config(['app.key' => 'base64:SUPERSECRETKEYVALUE', 'mail.default' => 'log', 'database.connections.pgsql.password' => 'db-pass-123']);
    [, $out] = preflight();
    expect($out)->not->toContain('SUPERSECRETKEYVALUE')->and($out)->not->toContain('db-pass-123');
});

it('looks at the real database too: it can reach it and sees no pending migrations', function () {
    goodProductionSettings();
    config(['queue.default' => 'database', 'cache.default' => 'database']);      // so the test does not need Redis
    $code = Artisan::call('app:preflight', ['--strict' => true]);
    $out = Artisan::output();
    expect($out)->not->toContain('database cannot be reached')->and($out)->not->toContain('migration(s) have not been run');
});

it('treats a brand-new database (no migrations table yet) as "migrations still to run", not as unreachable, and lets the migrate step through', function () {
    goodProductionSettings();
    config(['queue.default' => 'database', 'cache.default' => 'database']);
    Schema::drop('migrations');

    Artisan::call('app:preflight', ['--strict' => true]);
    $out = Artisan::output();
    expect($out)->toContain('migration(s) have not been run')->and($out)->not->toContain('database cannot be reached');

    Artisan::call('app:preflight', ['--strict' => true, '--allow-pending-migrations' => true]);
    expect(Artisan::output())->not->toContain('migration(s) have not been run');
});
