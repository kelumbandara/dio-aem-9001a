<?php

namespace App\Modules\Finance\Actions;

use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\FiscalPeriod;
use Illuminate\Support\Facades\DB;

/**
 * open → closed (can be reopened) → locked (final: not even the database accepts a change).
 * Closing needs finance.period.manage; locking needs finance.period.lock (checked by the caller).
 */
final class ChangePeriodStatus
{
    private const ALLOWED = [
        'close' => [FiscalPeriod::OPEN, FiscalPeriod::CLOSED],
        'reopen' => [FiscalPeriod::CLOSED, FiscalPeriod::OPEN],
        'lock' => [FiscalPeriod::CLOSED, FiscalPeriod::LOCKED],
    ];

    public function __invoke(FiscalPeriod $period, string $action, ?int $by = null): FiscalPeriod
    {
        [$from, $to] = self::ALLOWED[$action] ?? throw FinanceException::make('finance.action_invalid', 'Unknown action.', 422);

        return DB::transaction(function () use ($period, $from, $to, $by, $action): FiscalPeriod {
            $period = FiscalPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($period->status !== $from) {
                $hint = $action === 'lock' ? ' (close it first)' : '';
                throw FinanceException::make('finance.period_state', "The period {$period->name} is {$period->status}, so it cannot be changed this way{$hint}.", 422);
            }

            $period->forceFill([
                'status' => $to, 'closed_at' => $to === FiscalPeriod::OPEN ? null : now(), 'closed_by' => $to === FiscalPeriod::OPEN ? null : $by,
            ])->save();

            return $period;
        });
    }
}
