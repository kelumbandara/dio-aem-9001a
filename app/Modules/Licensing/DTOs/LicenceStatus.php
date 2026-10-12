<?php

namespace App\Modules\Licensing\DTOs;

/**
 * Where the installation stands today.
 *
 * state: active (licence or trial running) | grace (ended, everything still works with a warning) | readonly (look and
 * print only) | locked (only the licence screen and sign-out).
 * source: licence | trial.
 */
final readonly class LicenceStatus
{
    /** @param  list<string>|null  $modules */
    public function __construct(
        public string $state,
        public string $source,
        public string $validUntil,
        public int $daysLeft,       // days until the last day (0 on the last day); 0 once it has ended
        public int $daysOver,       // days since the last day; 0 while running
        public ?string $plan,
        public ?string $company,
        public ?string $serial,
        public ?int $maxUsers,
        public ?array $modules,
        public string $readonlyFrom,
        public string $lockedFrom,
    ) {}

    public function restricts(): bool
    {
        return in_array($this->state, ['readonly', 'locked'], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
