<?php

namespace App\Modules\Manufacturing\Services;

use App\Modules\Manufacturing\Exceptions\ManufacturingException;
use App\Modules\Manufacturing\Models\Shift;
use Carbon\CarbonImmutable;

/**
 * Which shift is running, and which shift (and shift date) an entry belongs to. Output and downtime are always reported
 * for a shift. A night shift that runs past midnight belongs, all of it, to the day it started.
 */
final class ShiftService
{
    /**
     * The shift a person is working right now and its date, or null when none is running (or none is set up).
     *
     * @return array{shift: Shift, date: CarbonImmutable}|null
     */
    public function current(?CarbonImmutable $now = null): ?array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(config('core.display_timezone'));
        $time = $now->format('H:i:s');
        $today = $now->startOfDay();

        foreach (Shift::query()->where('is_active', true)->orderBy('start_time')->get() as $shift) {
            $start = (string) $shift->start_time;
            $end = (string) $shift->end_time;
            if ($start < $end) {
                if ($time >= $start && $time < $end && $this->works($shift, $today)) {
                    return ['shift' => $shift, 'date' => $today];
                }

                continue;
            }
            // ends the next day
            if ($time >= $start && $this->works($shift, $today)) {
                return ['shift' => $shift, 'date' => $today];
            }
            if ($time < $end && $this->works($shift, $today->subDay())) {
                return ['shift' => $shift, 'date' => $today->subDay()];
            }
        }

        return null;
    }

    /**
     * The shift named in an entry (by code or id); it must be in use.
     *
     * @param  array<string, mixed>  $data
     */
    public function find(array $data, string $field = 'shift'): Shift
    {
        $shift = isset($data["{$field}_id"]) && $data["{$field}_id"] !== ''
            ? Shift::query()->find((int) $data["{$field}_id"])
            : Shift::query()->whereRaw('lower(code) = ?', [strtolower(trim((string) ($data[$field] ?? '')))])->first();
        if ($shift === null || ! $shift->is_active) {
            throw ManufacturingException::make('manufacturing.shift_required', 'Choose the shift this was done in.', 422, $field);
        }

        return $shift;
    }

    /**
     * The shift date of an entry: today's shift date when none is given, never in the future, never further back than the setting allows.
     */
    public function date(Shift $shift, mixed $given, string $field = 'date'): CarbonImmutable
    {
        $tz = config('core.display_timezone');
        $now = CarbonImmutable::now($tz);
        $v = trim((string) $given);
        if ($v === '') {
            $current = $this->current($now);

            return $current !== null && $current['shift']->is($shift) ? $current['date'] : $now->startOfDay();
        }
        $d = CarbonImmutable::createFromFormat('!Y-m-d', $v, $tz);
        if ($d === null || $d->toDateString() !== $v) {
            throw ManufacturingException::make('manufacturing.date_invalid', 'Use a date like 2026-11-15.', 422, $field);
        }
        if ($d->greaterThan($now->startOfDay())) {
            throw ManufacturingException::make('manufacturing.date_future', 'A shift that has not happened yet cannot be reported.', 422, $field);
        }
        if ($d->lessThan($now->startOfDay()->subDays((int) config('manufacturing.report_backdate_days')))) {
            throw ManufacturingException::make('manufacturing.date_too_old', 'That is further back than reports may be entered. Ask a supervisor.', 422, $field);
        }

        return $d;
    }

    public function works(Shift $shift, CarbonImmutable $day): bool
    {
        return ($shift->days[$day->dayOfWeekIso - 1] ?? '0') === '1';
    }
}
