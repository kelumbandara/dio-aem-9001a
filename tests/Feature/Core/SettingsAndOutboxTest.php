<?php

use App\Modules\Core\Contracts\SettingsService;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\OutboxEvent;
use App\Modules\Core\Services\OutboxRecorder;

it('falls back from branch to global to configured default to caller default', function () {
    $settings = app(SettingsService::class);

    expect($settings->get('core.date_format'))->toBe('Y-m-d')          // configured default
        ->and($settings->get('x.unset', 'fallback'))->toBe('fallback'); // caller default

    $settings->set('x.limit', 10);
    expect($settings->get('x.limit', 0, 'branch', 7))->toBe(10);      // global applies to a branch

    $settings->set('x.limit', 25, 'branch', 7);
    expect($settings->get('x.limit', 0, 'branch', 7))->toBe(25)
        ->and($settings->get('x.limit', 0, 'branch', 8))->toBe(10)
        ->and($settings->get('x.limit'))->toBe(10);
});

it('stores typed values and can forget them', function () {
    $settings = app(SettingsService::class);

    $settings->set('x.flag', false);
    $settings->set('x.list', ['a', 'b']);
    $settings->set('x.nothing', null);

    expect($settings->get('x.flag', true))->toBeFalse()
        ->and($settings->get('x.list'))->toBe(['a', 'b'])
        ->and($settings->get('x.nothing', 'default'))->toBeNull();

    $settings->forget('x.flag');
    expect($settings->get('x.flag', 'gone'))->toBe('gone');
});

it('sees changes immediately even though settings are cached', function () {
    $settings = app(SettingsService::class);
    $settings->set('x.v', 1);
    $settings->get('x.v');
    $settings->set('x.v', 2);

    expect($settings->get('x.v'))->toBe(2);
});

it('audits setting changes', function () {
    app(SettingsService::class)->set('x.v', 1);
    app(SettingsService::class)->set('x.v', 2);

    $entry = AuditLog::where('event', 'setting.updated')->latest('id')->first();
    expect($entry->old_values)->toBe(['value' => 1])
        ->and($entry->new_values)->toBe(['value' => 2])
        ->and($entry->metadata['key'])->toBe('x.v');
});

it('writes outbox events in the caller transaction', function () {
    $recorder = app(OutboxRecorder::class);

    DB::transaction(fn () => $recorder->record('test.happened', ['id' => 5], 'Thing', 5));
    try {
        DB::transaction(function () use ($recorder) {
            $recorder->record('test.rolled_back', ['id' => 6]);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect(OutboxEvent::pluck('event_type')->all())->toBe(['test.happened'])
        ->and(OutboxEvent::first()->payload)->toBe(['id' => 5])
        ->and(OutboxEvent::first()->processed_at)->toBeNull();
});

it('deduplicates inbound messages by source and message id', function () {
    DB::table('inbox_events')->insert(['source' => 'whatsapp', 'message_id' => 'm1', 'created_at' => now(), 'updated_at' => now()]);

    $inserted = DB::table('inbox_events')->insertOrIgnore(['source' => 'whatsapp', 'message_id' => 'm1', 'created_at' => now(), 'updated_at' => now()]);

    expect($inserted)->toBe(0)->and(DB::table('inbox_events')->count())->toBe(1);
});

it('prunes only expired idempotency keys', function () {
    $row = fn (string $key, $expires) => [
        'scope' => 'user:1', 'key' => $key, 'method' => 'POST', 'path' => 'x', 'request_hash' => str_repeat('a', 64),
        'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('idempotency_keys')->insert([$row('old', now()->subDay()), $row('fresh', now()->addDay())]);

    $this->artisan('idempotency:prune')->assertSuccessful();

    expect(DB::table('idempotency_keys')->pluck('key')->all())->toBe(['fresh']);
});
