<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\FiscalPeriod;
use Carbon\CarbonImmutable;

/** Which fiscal period a date belongs to, and whether it may still be posted into. */
final class PeriodService
{
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('core.display_timezone'))->startOfDay();
    }

    public function find(string $date): ?FiscalPeriod
    {
        return FiscalPeriod::query()->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->first();
    }

    /**
     * The open period to post into for this date.
     *
     * @throws FinanceException finance.no_period (no fiscal year covers the date) | finance.period_closed
     */
    public function openPeriodFor(string $date): FiscalPeriod
    {
        $period = $this->find($date) ?? throw FinanceException::make('finance.no_period', "No fiscal year covers {$date}. Create the fiscal year first.", 422, 'entry_date');

        if ($period->status !== FiscalPeriod::OPEN) {
            throw FinanceException::make('finance.period_closed', "The period {$period->name} is {$period->status}; nothing can be posted into it.", 422, 'entry_date');
        }

        return $period;
    }

    /**
     * For postings made by the system on behalf of a business document: the document's own date if its period is
     * open, otherwise today (if that period is open).
     *
     * @return array{0: FiscalPeriod, 1: string} period and the date actually used
     */
    public function openPeriodRollingForward(string $date): array
    {
        try {
            return [$this->openPeriodFor($date), $date];
        } catch (FinanceException $e) {
            $today = $this->today()->toDateString();
            if ($today === $date) {
                throw $e;
            }

            return [$this->openPeriodFor($today), $today];
        }
    }
}
