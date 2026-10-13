<?php

namespace App\Modules\Core\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Proof of life for the background processes. The scheduler stamps "scheduler" every minute and queues a job that stamps "worker"
 * every minute; the readiness endpoint reports "unavailable" when a stamp is older than five minutes, so a dead worker or
 * scheduler (approvals, messages and reminders silently stop) is seen by the uptime monitor instead of by a customer.
 */
final class Heartbeat
{
    public const KINDS = ['scheduler', 'worker'];

    /** Seconds without a stamp after which a process counts as dead. */
    public const STALE_AFTER = 300;

    public function stamp(string $kind): void
    {
        Cache::put("heartbeat:{$kind}", now()->getTimestamp(), 3600);
    }

    /**
     * Which processes look dead. A process that has never stamped is forgiven for the first five minutes after this method
     * first noticed (so a fresh start is not reported as down before its first minute has passed).
     *
     * @return list<string>
     */
    public function dead(): array
    {
        $dead = [];
        $now = now()->getTimestamp();
        foreach (self::KINDS as $kind) {
            $last = Cache::get("heartbeat:{$kind}");
            if ($last === null) {
                $first = Cache::add("heartbeat:first-check:{$kind}", $now, 3600) ? $now : (int) Cache::get("heartbeat:first-check:{$kind}", $now);
                $last = $first;
            }
            if ($now - (int) $last > self::STALE_AFTER) {
                $dead[] = $kind;
            }
        }

        return $dead;
    }
}
