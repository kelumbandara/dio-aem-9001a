<?php

use Illuminate\Console\Scheduling\Schedule;

it('schedules every maintenance command that Sprint 2 relies on', function () {
    $scheduled = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode("\n");

    foreach (['outbox:relay', 'idempotency:prune', 'audit:maintain', 'attachments:purge', 'approvals:expire', 'approvals:remind'] as $command) {
        expect($scheduled)->toContain($command);
    }
});

it('runs the relay every minute and never overlaps with itself', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'outbox:relay'));

    expect($event->expression)->toBe('* * * * *')->and($event->withoutOverlapping)->toBeTrue();
});

it('only schedules commands that exist', function () {
    $registered = array_keys(Artisan::all());

    foreach (app(Schedule::class)->events() as $event) {
        preg_match('/artisan[\'"]? (\S+)/', $event->command, $m);
        expect($registered)->toContain(trim($m[1], "'\""));
    }
});
