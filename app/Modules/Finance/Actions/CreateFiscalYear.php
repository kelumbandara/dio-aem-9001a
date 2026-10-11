<?php

namespace App\Modules\Finance\Actions;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\FiscalPeriod;
use App\Modules\Finance\Models\FiscalYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Creates a fiscal year of twelve monthly periods starting on the first of $startMonth (April for Sri Lanka by default). */
final class CreateFiscalYear
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(int $startYear, int $startMonth = 4): FiscalYear
    {
        if ($startYear < 2000 || $startYear > 2100 || $startMonth < 1 || $startMonth > 12) {
            throw FinanceException::make('finance.year_invalid', 'Enter a valid starting year and month.', 422, 'year');
        }

        return DB::transaction(function () use ($startYear, $startMonth): FiscalYear {
            $starts = CarbonImmutable::create($startYear, $startMonth, 1)->startOfDay();
            $ends = $starts->addYear()->subDay();

            if (FiscalYear::query()->where('starts_on', '<=', $ends->toDateString())->where('ends_on', '>=', $starts->toDateString())->exists()) {
                throw FinanceException::make('finance.year_overlaps', 'A fiscal year already covers part of that range.', 422, 'year');
            }

            $name = $startMonth === 1 ? (string) $startYear : $startYear.'/'.substr((string) ($startYear + 1), -2);
            $year = FiscalYear::query()->create(['name' => $name, 'starts_on' => $starts->toDateString(), 'ends_on' => $ends->toDateString()]);

            for ($i = 0; $i < 12; $i++) {
                $from = $starts->addMonthsNoOverflow($i);
                FiscalPeriod::query()->create([
                    'fiscal_year_id' => $year->id, 'name' => $from->format('M Y'), 'starts_on' => $from->toDateString(),
                    'ends_on' => $from->endOfMonth()->toDateString(), 'status' => FiscalPeriod::OPEN,
                ]);
            }

            $this->audit->record('finance.year_created', $year, [], ['name' => $name, 'starts_on' => $starts->toDateString(), 'ends_on' => $ends->toDateString()]);

            return $year;
        });
    }
}
