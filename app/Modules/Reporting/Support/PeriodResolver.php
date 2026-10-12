<?php

namespace App\Modules\Reporting\Support;

use App\Modules\Reporting\Exceptions\ReportingException;
use Carbon\CarbonImmutable;

/** Turns "this month", "last financial year" or two typed dates into a Period, in Colombo time. */
final class PeriodResolver
{
    public const PRESETS = ['today', 'yesterday', 'this_week', 'last_week', 'last_7', 'last_30', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'custom'];

    public const COMPARE = ['previous', 'year', 'none'];

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('core.display_timezone'))->startOfDay();
    }

    /**
     * @param  string|null  $preset  one of PRESETS (default this_month); with "custom" the dates are used
     *
     * @throws ReportingException
     */
    public function resolve(?string $preset, ?string $from = null, ?string $to = null): Period
    {
        $preset = $preset === null || $preset === '' ? ($from !== null && $to !== null ? 'custom' : 'this_month') : $preset;
        if (! in_array($preset, self::PRESETS, true)) {
            throw ReportingException::make('reporting.period_invalid', 'Choose one of the listed periods.', 422, 'period');
        }
        $today = $this->today();

        $period = match ($preset) {
            'today' => new Period($today->toDateString(), $today->toDateString()),
            'yesterday' => new Period($today->subDay()->toDateString(), $today->subDay()->toDateString()),
            'this_week' => new Period($today->startOfWeek()->toDateString(), $today->toDateString()),
            'last_week' => new Period($today->subWeek()->startOfWeek()->toDateString(), $today->subWeek()->endOfWeek()->startOfDay()->toDateString()),
            'last_7' => new Period($today->subDays(6)->toDateString(), $today->toDateString()),
            'last_30' => new Period($today->subDays(29)->toDateString(), $today->toDateString()),
            'this_month' => new Period($today->startOfMonth()->toDateString(), $today->toDateString()),
            'last_month' => new Period($today->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()->toDateString()),
            'this_quarter' => $this->quarter($today, 0),
            'last_quarter' => $this->quarter($today, -1),
            'this_year' => $this->financialYear($today, 0),
            'last_year' => $this->financialYear($today, -1),
            'custom' => $this->custom($from, $to),
        };

        if ($period->days() > (int) config('reporting.max_range_days')) {
            throw ReportingException::make('reporting.period_too_long', 'Choose a period of at most '.config('reporting.max_range_days').' days.', 422, 'from');
        }

        return $period;
    }

    /** The period to compare with, or null for "none". */
    public function comparison(Period $period, ?string $mode): ?Period
    {
        $mode = $mode === null || $mode === '' ? 'previous' : $mode;
        if (! in_array($mode, self::COMPARE, true)) {
            throw ReportingException::make('reporting.compare_invalid', 'Compare with the previous period, the same dates last year, or nothing.', 422, 'compare');
        }

        return match ($mode) {
            'previous' => $period->previous(),
            'year' => $period->lastYear(),
            default => null,
        };
    }

    private function custom(?string $from, ?string $to): Period
    {
        $tz = config('core.display_timezone');
        foreach (['from' => $from, 'to' => $to] as $field => $v) {
            $d = $v === null || ! CarbonImmutable::canBeCreatedFromFormat($v, '!Y-m-d') ? null : CarbonImmutable::createFromFormat('!Y-m-d', $v, $tz);
            if ($d === null || $d->toDateString() !== $v) {
                throw ReportingException::make('reporting.date_invalid', 'Use dates like 2026-11-15.', 422, $field);
            }
        }
        if ($from > $to) {
            throw ReportingException::make('reporting.period_invalid', 'The period cannot end before it starts.', 422, 'to');
        }

        return new Period((string) $from, (string) $to);
    }

    /** Calendar quarters (January–March …), $offset = 0 this one, -1 the one before. */
    private function quarter(CarbonImmutable $today, int $offset): Period
    {
        $start = $today->startOfQuarter()->addMonthsNoOverflow(3 * $offset);

        return new Period($start->toDateString(), ($offset === 0 ? $today : $start->endOfQuarter()->startOfDay())->toDateString());
    }

    /** The financial year containing today (to date), or the one before it (whole). */
    private function financialYear(CarbonImmutable $today, int $offset): Period
    {
        $startMonth = max(1, min(12, (int) config('reporting.fiscal_year_start_month')));
        $year = $today->month >= $startMonth ? $today->year : $today->year - 1;
        $start = CarbonImmutable::create($year + $offset, $startMonth, 1, 0, 0, 0, config('core.display_timezone'));

        return new Period($start->toDateString(), ($offset === 0 ? $today : $start->addYear()->subDay())->toDateString());
    }
}
