<?php

use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Core\Events\IntegrationEvent;
use App\Modules\Core\Models\OutboxEvent;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\OutboxRelay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/** @var list<IntegrationEvent> $seen */
beforeEach(function () {
    $this->seen = [];
    Event::listen(IntegrationEvent::class, function (IntegrationEvent $e) {
        $this->seen[] = $e;
    });
    $this->outbox = app(EventOutbox::class);
});

it('publishes pending events as envelopes and marks them processed', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->outbox->record('thing.happened', ['id' => 7], 'thing', 7, branchId: 3);

    $result = app(OutboxRelay::class)->run();

    $event = $this->seen[0];
    expect($result)->toBe(['published' => 1, 'failed' => 0, 'parked' => 0])
        ->and($event->type)->toBe('thing.happened')
        ->and($event->version)->toBe(1)
        ->and($event->data)->toBe(['id' => 7])
        ->and($event->actorType)->toBe('user')->and($event->actorId)->toBe($user->id)
        ->and($event->branchId)->toBe(3)
        ->and($event->aggregateType)->toBe('thing')->and($event->aggregateId)->toBe('7')
        ->and($event->id)->toMatch('/^[0-9a-f-]{36}$/')
        ->and(OutboxEvent::sole()->processed_at)->not->toBeNull();

    // nothing left to publish
    expect(app(OutboxRelay::class)->run()['published'])->toBe(0)->and($this->seen)->toHaveCount(1);
});

it('publishes oldest first and respects the batch limit', function () {
    foreach (range(1, 5) as $i) {
        $this->outbox->record('n.'.$i, []);
    }

    app(OutboxRelay::class)->run(3);
    expect(array_map(fn ($e) => $e->type, $this->seen))->toBe(['n.1', 'n.2', 'n.3']);

    app(OutboxRelay::class)->run(3);
    expect(array_map(fn ($e) => $e->type, $this->seen))->toBe(['n.1', 'n.2', 'n.3', 'n.4', 'n.5']);
});

it('does not publish events scheduled for later', function () {
    $this->outbox->record('later', []);
    OutboxEvent::query()->update(['available_at' => now()->addHour()]);

    expect(app(OutboxRelay::class)->run()['published'])->toBe(0);
});

it('retries a failing listener with growing backoff and never loses the event', function () {
    Event::forget(IntegrationEvent::class);
    $attempts = 0;
    Event::listen(IntegrationEvent::class, function () use (&$attempts) {
        $attempts++;
        throw new RuntimeException('listener is down');
    });
    $this->outbox->record('flaky', []);

    $first = app(OutboxRelay::class)->run();
    $row = OutboxEvent::sole();
    expect($first)->toBe(['published' => 0, 'failed' => 1, 'parked' => 0])
        ->and($row->attempts)->toBe(1)->and($row->processed_at)->toBeNull()->and($row->failed_at)->toBeNull()
        ->and($row->last_error)->toContain('listener is down')
        ->and($row->available_at->gt(now()->addMinute()))->toBeTrue();

    // within the backoff window nothing is retried
    app(OutboxRelay::class)->run();
    expect($attempts)->toBe(1);

    $this->travel(3)->minutes();
    app(OutboxRelay::class)->run();
    expect($attempts)->toBe(2)->and(OutboxEvent::sole()->available_at->gt(now()->addMinutes(3)))->toBeTrue();
});

it('parks an event after the maximum attempts and reports it through the command exit code', function () {
    config(['core.outbox.max_attempts' => 2]);
    Event::forget(IntegrationEvent::class);
    Event::listen(IntegrationEvent::class, fn () => throw new RuntimeException('always fails'));
    $this->outbox->record('doomed', []);

    app(OutboxRelay::class)->run();
    $this->travel(10)->minutes();
    $this->artisan('outbox:relay')->expectsOutputToContain('1 parked')->assertFailed();

    $row = OutboxEvent::sole();
    expect($row->failed_at)->not->toBeNull()->and($row->attempts)->toBe(2)->and($row->processed_at)->toBeNull();

    $this->travel(2)->hours();
    $this->artisan('outbox:relay')->expectsOutputToContain('0 parked')->assertSuccessful();   // parked rows are left alone
    expect(OutboxEvent::sole()->attempts)->toBe(2);
});

it('keeps order within an aggregate: a failing event holds back later events of the same aggregate only', function () {
    Event::forget(IntegrationEvent::class);
    Event::listen(IntegrationEvent::class, function (IntegrationEvent $e) {
        if ($e->type === 'a.first') {
            throw new RuntimeException('nope');
        }
        $this->seen[] = $e;
    });
    $this->outbox->record('a.first', [], 'order', 1);
    $this->outbox->record('a.second', [], 'order', 1);
    $this->outbox->record('b.first', [], 'order', 2);
    $this->outbox->record('free', []);   // no aggregate: never held back

    app(OutboxRelay::class)->run();

    expect(array_map(fn ($e) => $e->type, $this->seen))->toBe(['b.first', 'free'])
        ->and(OutboxEvent::whereNull('processed_at')->orderBy('id')->pluck('event_type')->all())->toBe(['a.first', 'a.second']);
});

it('rolls back what a failing listener wrote and does not let a database error stop other events', function () {
    Event::forget(IntegrationEvent::class);
    Event::listen(IntegrationEvent::class, function (IntegrationEvent $e) {
        if ($e->type === 'bad') {
            DB::table('settings')->insert(['key' => 'leak', 'scope' => 'global', 'scope_id' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::select('select * from table_that_does_not_exist');   // aborts the PostgreSQL transaction state
        }
        $this->seen[] = $e;
    });
    $this->outbox->record('bad', []);
    $this->outbox->record('good', []);

    $result = app(OutboxRelay::class)->run();

    expect($result)->toBe(['published' => 1, 'failed' => 1, 'parked' => 0])
        ->and(array_map(fn ($e) => $e->type, $this->seen))->toBe(['good'])
        ->and(DB::table('settings')->where('key', 'leak')->exists())->toBeFalse()
        ->and(OutboxEvent::where('event_type', 'bad')->sole()->attempts)->toBe(1);
});

it('is safe to run twice: published events are not delivered again', function () {
    $this->outbox->record('once', []);

    app(OutboxRelay::class)->run();
    $this->artisan('outbox:relay')->expectsOutputToContain('0 published')->assertSuccessful();

    expect($this->seen)->toHaveCount(1);
});

it('writes to the outbox only if the business transaction commits', function () {
    try {
        DB::transaction(function () {
            $this->outbox->record('rolled.back', []);
            throw new RuntimeException('business failure');
        });
    } catch (RuntimeException) {
    }

    expect(OutboxEvent::count())->toBe(0);
});
