<?php

use App\Modules\Core\Jobs\WorkerHeartbeat;
use App\Modules\Core\Services\Heartbeat;
use Illuminate\Support\Facades\Schedule;

// Run by `php artisan schedule:work` (dev) or the scheduler container (production).
Schedule::command('outbox:relay')->everyMinute()->withoutOverlapping(5)->runInBackground();
Schedule::command('idempotency:prune')->daily();
Schedule::command('audit:maintain')->dailyAt('02:00');
Schedule::command('attachments:purge')->dailyAt('02:30');
// A fresh downloadable database copy every Monday morning, and a reminder to the administrators to download it.
Schedule::command('database:backup --remind')->weeklyOn(1, '08:00')->timezone(config('core.display_timezone'))->withoutOverlapping(30);
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Proof of life, read by /api/v1/health/ready (a dead worker or scheduler is otherwise silent).
Schedule::call(fn () => app(Heartbeat::class)->stamp('scheduler'))->name('heartbeat:scheduler')->everyMinute();
Schedule::job(new WorkerHeartbeat)->name('heartbeat:worker')->everyMinute();
