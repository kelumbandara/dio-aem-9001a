<?php

namespace App\Modules\Licensing\Console;

use App\Modules\Core\Contracts\Notifier;
use App\Modules\Core\Contracts\SettingsService;
use App\Modules\Core\Contracts\UserDirectory;
use App\Modules\Core\DTOs\NotificationMessage;
use App\Modules\Licensing\Services\LicenceService;
use Illuminate\Console\Command;

/** Daily: tells the administrators when the licence is about to end and when each stricter phase begins (once per change). */
class LicenceCheck extends Command
{
    protected $signature = 'licence:check';

    protected $description = 'Notify administrators about the licence ending or having ended';

    public function handle(LicenceService $licence, SettingsService $settings, Notifier $notifier, UserDirectory $users): int
    {
        if (! $licence->enforcing()) {
            $this->info('Licensing is not enforced here.');

            return self::SUCCESS;
        }

        $s = $licence->status();
        $warn = (int) config('licensing.warn_days');
        $kind = match (true) {
            $s->state !== 'active' => $s->state,
            $s->daysLeft <= $warn && in_array($s->daysLeft, [$warn, 3, 1, 0], true) => 'ending',
            default => null,
        };
        $marker = $kind === null ? null : "{$kind}|{$s->validUntil}|".($kind === 'ending' ? $s->daysLeft : '');
        if ($kind === null || $settings->get('licensing.notified') === $marker) {
            $this->info('Nothing new to tell anyone.');

            return self::SUCCESS;
        }

        $admins = array_values(array_unique([...$users->userIdsWithRole('admin'), ...$users->userIdsWithRole('super-admin')]));
        $notifier->notify($admins, new NotificationMessage(
            "licensing::notifications.{$kind}", ['date' => $s->validUntil, 'days' => $s->daysLeft, 'locked' => $s->lockedFrom, 'readonly' => $s->readonlyFrom],
            route('licence.show', absolute: false), in_array($kind, ['readonly', 'locked'], true) ? 'danger' : 'warning',
        ));
        $settings->set('licensing.notified', $marker);
        $this->info('Told '.count($admins)." administrator(s): {$kind}.");

        return self::SUCCESS;
    }
}
