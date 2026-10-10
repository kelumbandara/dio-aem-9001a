<?php

use Illuminate\Support\Facades\Schedule;

// Run by `php artisan schedule:work` (dev) or the scheduler container (production).
Schedule::command('outbox:relay')->everyMinute()->withoutOverlapping(5)->runInBackground();
Schedule::command('idempotency:prune')->daily();
Schedule::command('audit:maintain')->dailyAt('02:00');
Schedule::command('attachments:purge')->dailyAt('02:30');
