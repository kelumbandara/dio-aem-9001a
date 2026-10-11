<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Contracts\ReceivablePosition;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\OpenItem;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

final class DatabaseReceivablePosition implements ReceivablePosition
{
    public function __construct(private readonly PeriodService $periods) {}

    public function outstanding(int $customerId): string
    {
        $open = (string) OpenItem::query()->where('side', OpenItem::RECEIVABLE)->where('party_id', $customerId)->sum('open_amount');
        $unallocated = (string) (DB::selectOne(
            "SELECT COALESCE(SUM(s.amount - COALESCE((SELECT SUM(a.amount) FROM settlement_allocations a WHERE a.settlement_id = s.id), 0)), 0) AS u
             FROM settlements s WHERE s.side = 'receivable' AND s.party_id = ? AND s.status = 'posted'",
            [$customerId],
        )->u ?? '0');

        $net = Decimal::sub($open, $unallocated);

        return Decimal::compare($net, '0', 4) < 0 ? '0.0000' : Decimal::round($net, 4);
    }

    public function overdue(int $customerId): string
    {
        $late = (string) OpenItem::query()->where('side', OpenItem::RECEIVABLE)->where('party_id', $customerId)->where('due_date', '<', $this->periods->today()->toDateString())->sum('open_amount');

        return Decimal::round($late, 4);
    }

    public function invoiceBankAccounts(): array
    {
        return BankAccount::query()->where('is_active', true)->where('show_on_invoices', true)->where('currency', 'LKR')->orderBy('id')->get()
            ->map(fn (BankAccount $b) => ['name' => $b->name, 'bank_name' => $b->bank_name, 'account_no' => $b->account_no, 'currency' => $b->currency])->values()->all();
    }
}
