<?php

namespace App\Modules\Finance\Http\Controllers\Api;

use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\FinancialReports;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly FinancialReports $reports) {}

    public function trialBalance(Request $request): JsonResponse
    {
        $this->authorize('finance.report.view');
        $v = $request->validate(['from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'to' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->reports->trialBalance($v['from'] ?? null, $v['to'] ?? $this->today())]);
    }

    public function generalLedger(Request $request): JsonResponse
    {
        $this->authorize('finance.report.view');
        $v = $request->validate(['account' => ['required', 'string', 'max:12'], 'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'to' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);
        $account = Account::query()->where('code', $v['account'])->where('is_group', false)->first() ?? throw FinanceException::make('finance.unknown_code', 'Unknown account code.', 422, 'account');
        $gl = $this->reports->generalLedger($account, $v['from'] ?? null, $v['to'] ?? $this->today());
        $gl['account'] = ['code' => $account->code, 'name' => $account->name, 'class' => $account->class];

        return response()->json(['data' => $gl]);
    }

    public function profitAndLoss(Request $request): JsonResponse
    {
        $this->authorize('finance.report.view');
        $v = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->reports->profitAndLoss($v['from'], $v['to'] ?? $this->today())]);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $this->authorize('finance.report.view');
        $v = $request->validate(['as_at' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->reports->balanceSheet($v['as_at'] ?? $this->today())]);
    }

    private function today(): string
    {
        return now((string) config('core.display_timezone'))->toDateString();
    }
}
