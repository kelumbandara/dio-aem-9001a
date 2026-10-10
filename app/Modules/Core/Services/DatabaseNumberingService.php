<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\NumberingService;
use App\Modules\Core\Exceptions\NumberingException;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\CompanyProfile;
use App\Modules\Core\Models\NumberSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DatabaseNumberingService implements NumberingService
{
    private const RESET_POLICIES = ['never', 'yearly', 'fiscal_yearly', 'monthly'];

    public function define(
        string $key,
        string $format = '{SEQ}',
        int $padding = 5,
        string $resetPolicy = 'never',
        bool $perBranch = false,
    ): void {
        if (! in_array($resetPolicy, self::RESET_POLICIES, true)) {
            throw new InvalidArgumentException("Unknown reset policy '{$resetPolicy}'.");
        }

        NumberSequence::query()->firstOrCreate(
            ['key' => $key],
            ['format' => $format, 'padding' => $padding, 'reset_policy' => $resetPolicy, 'per_branch' => $perBranch],
        );
    }

    public function next(string $key, ?int $branchId = null, ?CarbonInterface $date = null): string
    {
        $date = ($date ?? now())->copy()->setTimezone(config('core.display_timezone'));

        return DB::transaction(function () use ($key, $branchId, $date): string {
            $sequence = NumberSequence::query()->where('key', $key)->where('is_active', true)->first()
                ?? throw NumberingException::unknownSequence($key);

            $bucket = $this->bucket($sequence, $branchId, $date);

            // Make sure the counter row exists, then take the row lock that serialises allocation.
            DB::table('number_sequence_counters')->insertOrIgnore([
                'sequence_id' => $sequence->id,
                'bucket' => $bucket,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $counter = DB::table('number_sequence_counters')
                ->where('sequence_id', $sequence->id)
                ->where('bucket', $bucket)
                ->lockForUpdate()
                ->first();

            $number = $counter->last_number + 1;

            DB::table('number_sequence_counters')
                ->where('id', $counter->id)
                ->update(['last_number' => $number, 'updated_at' => now()]);

            return $this->render($sequence, $number, $branchId, $date);
        });
    }

    private function bucket(NumberSequence $sequence, ?int $branchId, CarbonInterface $date): string
    {
        $period = match ($sequence->reset_policy) {
            'yearly' => 'Y'.$date->format('Y'),
            'fiscal_yearly' => 'FY'.$this->fiscalYearLabel($date),
            'monthly' => 'M'.$date->format('Y-m'),
            default => 'all',
        };

        return $sequence->per_branch ? $period.'|B'.($branchId ?? 0) : $period;
    }

    private function render(NumberSequence $sequence, int $number, ?int $branchId, CarbonInterface $date): string
    {
        return strtr($sequence->format, [
            '{SEQ}' => str_pad((string) $number, $sequence->padding, '0', STR_PAD_LEFT),
            '{YYYY}' => $date->format('Y'),
            '{YY}' => $date->format('y'),
            '{MM}' => $date->format('m'),
            '{FY}' => $this->fiscalYearLabel($date),
            '{BR}' => $branchId !== null ? (string) Branch::withTrashed()->whereKey($branchId)->value('code') : '',
        ]);
    }

    /** "2026-27" for an April–March year, plain "2026" when the fiscal year starts in January. */
    private function fiscalYearLabel(CarbonInterface $date): string
    {
        $start = CompanyProfile::current()->fiscal_year_start_month ?? 4;
        $startYear = $date->month >= $start ? $date->year : $date->year - 1;

        return $start === 1
            ? (string) $startYear
            : $startYear.'-'.str_pad((string) (($startYear + 1) % 100), 2, '0', STR_PAD_LEFT);
    }
}
