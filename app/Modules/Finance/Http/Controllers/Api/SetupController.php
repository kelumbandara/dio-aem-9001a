<?php

namespace App\Modules\Finance\Http\Controllers\Api;

use App\Modules\Finance\Actions\ChangePeriodStatus;
use App\Modules\Finance\Actions\CreateFiscalYear;
use App\Modules\Finance\Actions\SaveBankAccount;
use App\Modules\Finance\Actions\SaveMapping;
use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\AccountMapping;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\FiscalPeriod;
use App\Modules\Finance\Models\FiscalYear;
use App\Modules\Finance\Services\AccountMap;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Account mapping (posting rules), bank/cash accounts, fiscal years and periods. */
class SetupController extends Controller
{
    public function mappings(): JsonResponse
    {
        $this->authorize('finance.view');
        $current = AccountMapping::query()->with('account:id,code,name')->get()->keyBy('key');

        return response()->json(['data' => array_map(fn (string $key) => [
            'key' => $key, 'account' => $current[$key]->account->code ?? null, 'account_name' => $current[$key]->account->name ?? null,
            'classes' => SaveMapping::CLASSES[$key],
        ], AccountMap::keys())]);
    }

    public function setMapping(Request $request, string $key, SaveMapping $save): JsonResponse
    {
        $this->authorize('finance.mapping.manage');
        $data = $request->validate(['account' => ['required', 'string', 'max:12']]);
        $account = Account::query()->where('code', $data['account'])->first() ?? throw FinanceException::make('finance.unknown_code', 'Unknown account code.', 422, 'account');
        $mapping = $save($key, $account->id);

        return response()->json(['data' => ['key' => $mapping->key, 'account' => $account->code]]);
    }

    public function bankAccounts(): JsonResponse
    {
        $this->authorize('finance.view');

        return response()->json(['data' => BankAccount::query()->with('account:id,code,name')->orderBy('id')->get()->map(fn (BankAccount $b) => $this->bank($b))->values()]);
    }

    public function storeBank(Request $request, SaveBankAccount $save): JsonResponse
    {
        $this->authorize('finance.bank.manage');
        $data = $request->validate([
            'account' => ['required', 'string', 'max:12'], 'name' => ['required', 'string', 'max:150'], 'kind' => ['sometimes', 'in:bank,cash'],
            'bank_name' => ['nullable', 'string', 'max:150'], 'account_no' => ['nullable', 'string', 'max:50'], 'currency' => ['sometimes', 'string', 'size:3'],
        ]);
        $account = Account::query()->where('code', $data['account'])->first() ?? throw FinanceException::make('finance.unknown_code', 'Unknown account code.', 422, 'account');
        $bank = $save(['account_id' => $account->id] + $data);

        return response()->json(['data' => $this->bank($bank->load('account:id,code,name'))], 201);
    }

    public function updateBank(Request $request, BankAccount $bank, SaveBankAccount $save): JsonResponse
    {
        $this->authorize('finance.bank.manage');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'], 'kind' => ['sometimes', 'in:bank,cash'], 'bank_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'account_no' => ['sometimes', 'nullable', 'string', 'max:50'], 'currency' => ['sometimes', 'string', 'size:3'], 'is_active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->bank($save($data, $bank)->load('account:id,code,name'))]);
    }

    public function years(): JsonResponse
    {
        $this->authorize('finance.view');

        return response()->json(['data' => FiscalYear::query()->with('periods')->orderByDesc('starts_on')->get()->map(fn (FiscalYear $y) => [
            'name' => $y->name, 'starts_on' => $y->starts_on->toDateString(), 'ends_on' => $y->ends_on->toDateString(),
            'periods' => $y->periods->map(fn (FiscalPeriod $p) => ['id' => $p->id, 'name' => $p->name, 'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on->toDateString(), 'status' => $p->status])->values(),
        ])->values()]);
    }

    public function createYear(Request $request, CreateFiscalYear $create): JsonResponse
    {
        $this->authorize('finance.period.manage');
        $data = $request->validate(['start_year' => ['required', 'integer', 'between:2000,2100'], 'start_month' => ['sometimes', 'integer', 'between:1,12']]);
        $year = $create((int) $data['start_year'], (int) ($data['start_month'] ?? 4));

        return response()->json(['data' => ['name' => $year->name, 'starts_on' => $year->starts_on->toDateString(), 'ends_on' => $year->ends_on->toDateString()]], 201);
    }

    public function changePeriod(Request $request, FiscalPeriod $period, string $action, ChangePeriodStatus $change): JsonResponse
    {
        $this->authorize($action === 'lock' ? 'finance.period.lock' : 'finance.period.manage');
        $period = $change($period, $action, $request->user()->id);

        return response()->json(['data' => ['id' => $period->id, 'name' => $period->name, 'status' => $period->status]]);
    }

    /** @return array<string, mixed> */
    private function bank(BankAccount $b): array
    {
        return [
            'id' => $b->public_id, 'name' => $b->name, 'kind' => $b->kind, 'account' => $b->account->code ?? null, 'bank_name' => $b->bank_name,
            'account_no' => $b->maskedAccountNo(), 'currency' => $b->currency, 'is_active' => $b->is_active,
        ];
    }
}
