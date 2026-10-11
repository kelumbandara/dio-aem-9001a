<?php

namespace App\Modules\Finance\Http\Controllers\Web;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FiscalYear;
use App\Modules\Finance\Services\FinancialReports;
use App\Support\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function __construct(private readonly FinancialReports $reports) {}

    public function index(): View
    {
        $this->authorize('finance.report.view');

        return view('finance::reports.index');
    }

    public function trialBalance(Request $request): View
    {
        $this->authorize('finance.report.view');
        $v = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $to = $v['to'] ?? $this->today();

        return view('finance::reports.trial-balance', ['tb' => $this->reports->trialBalance($v['from'] ?? null, $to), 'from' => $v['from'] ?? null, 'to' => $to]);
    }

    public function generalLedger(Request $request): View
    {
        $this->authorize('finance.report.view');
        $v = $request->validate(['account' => ['nullable', 'string', 'max:12'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $to = $v['to'] ?? $this->today();
        $account = ! empty($v['account']) ? Account::query()->where('code', $v['account'])->where('is_group', false)->first() : null;

        return view('finance::reports.general-ledger', [
            'account' => $account, 'gl' => $account ? $this->reports->generalLedger($account, $v['from'] ?? null, $to) : null,
            'accounts' => Account::query()->where('is_group', false)->orderBy('code')->get(['code', 'name']), 'from' => $v['from'] ?? null, 'to' => $to, 'code' => $v['account'] ?? '',
        ]);
    }

    public function profitAndLoss(Request $request): View
    {
        $this->authorize('finance.report.view');
        $v = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $to = $v['to'] ?? $this->today();
        $from = $v['from'] ?? FiscalYear::query()->where('starts_on', '<=', $to)->where('ends_on', '>=', $to)->first()?->starts_on->toDateString() ?? substr($to, 0, 4).'-01-01';

        return view('finance::reports.profit-and-loss', ['pl' => $this->reports->profitAndLoss((string) $from, $to), 'from' => (string) $from, 'to' => $to]);
    }

    public function balanceSheet(Request $request): View
    {
        $this->authorize('finance.report.view');
        $v = $request->validate(['as_at' => ['nullable', 'date_format:Y-m-d']]);
        $asAt = $v['as_at'] ?? $this->today();

        return view('finance::reports.balance-sheet', ['bs' => $this->reports->balanceSheet($asAt), 'asAt' => $asAt]);
    }

    private function today(): string
    {
        return now((string) config('core.display_timezone'))->toDateString();
    }
}
