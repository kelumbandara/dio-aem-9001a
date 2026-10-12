<?php

namespace App\Modules\Reporting\Support;

use Carbon\CarbonImmutable;

/**
 * A reporting period: Colombo calendar days, `from` and `to` both included. Comparisons are always made with a period of the
 * same length: the one immediately before, or the same dates a year earlier.
 */
final readonly class Period
{
    public function __construct(public string $from, public string $to) {}

    public function days(): int
    {
        return (int) CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) + 1;
    }

    /** The same number of days directly before this period. */
    public function previous(): self
    {
        $days = $this->days();

        return new self(CarbonImmutable::parse($this->from)->subDays($days)->toDateString(), CarbonImmutable::parse($this->from)->subDay()->toDateString());
    }

    /** The same dates one year earlier (29 February becomes 28 February). */
    public function lastYear(): self
    {
        return new self(CarbonImmutable::parse($this->from)->subYearNoOverflow()->toDateString(), CarbonImmutable::parse($this->to)->subYearNoOverflow()->toDateString());
    }

    /** @return array{from: string, to: string, days: int} */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'days' => $this->days()];
    }
}
