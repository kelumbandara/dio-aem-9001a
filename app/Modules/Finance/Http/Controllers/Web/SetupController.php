<?php

namespace App\Modules\Finance\Http\Controllers\Web;

use App\Modules\Finance\Actions\ChangePeriodStatus;
use App\Modules\Finance\Actions\CreateFiscalYear;
use App\Modules\Finance\Actions\SaveBankAccount;
use App\Modules\Finance\Actions\SaveMapping;
use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\AccountMapping;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\FiscalPeriod;
use App\Modules\Finance\Models\FiscalYear;
use App\Modules\Finance\Services\AccountMap;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Fiscal years and periods; account mapping (posting rules); bank and cash accounts. */
class SetupController extends Controller
{
    use ReportsBusinessErrors;

    public function periods(Request $request): View
    {
        $this->authorize('finance.view');
        $years = FiscalYear::query()->with('periods')->orderByDesc('starts_on')->get();
        $next = $years->isEmpty() ? (int) now((string) config('core.display_timezone'))->year : $years->first()->starts_on->year + 1;

        return view('finance::setup.periods', ['years' => $years, 'nextYear' => $next, 'canManage' => $request->user()->can('finance.period.manage'), 'canLock' => $request->user()->can('finance.period.lock')]);
    }

    public function createYear(Request $request, CreateFiscalYear $create): RedirectResponse
    {
        $this->authorize('finance.period.manage');
        $data = $request->validate(['start_year' => ['required', 'integer', 'between:2000,2100']]);

        return $this->attempt(function () use ($create, $data) {
            $create((int) $data['start_year'], 4);

            return redirect()->route('finance.periods.index')->with('status', __('finance::ui.flash.saved'));
        });
    }

    public function changePeriod(Request $request, FiscalPeriod $period, string $action, ChangePeriodStatus $change): RedirectResponse
    {
        $this->authorize($action === 'lock' ? 'finance.period.lock' : 'finance.period.manage');

        return $this->attempt(function () use ($request, $change, $period, $action) {
            $change($period, $action, $request->user()->id);

            return redirect()->route('finance.periods.index')->with('status', __("finance::ui.periods.done_{$action}", ['period' => $period->name]));
        });
    }

    public function index(Request $request): View
    {
        $this->authorize('finance.view');
        $current = AccountMapping::query()->with('account:id,code,name')->get()->keyBy('key');

        return view('finance::setup.index', [
            'keys' => AccountMap::keys(), 'current' => $current, 'classes' => SaveMapping::CLASSES,
            'accounts' => Account::query()->where('is_group', false)->where('is_active', true)->orderBy('code')->get(['code', 'name', 'class']),
            'banks' => BankAccount::query()->with('account:id,code,name')->orderBy('id')->get(),
            'canMap' => $request->user()->can('finance.mapping.manage'), 'canBank' => $request->user()->can('finance.bank.manage'),
        ]);
    }

    public function saveMapping(Request $request, string $key, SaveMapping $save): RedirectResponse
    {
        $this->authorize('finance.mapping.manage');
        $data = $request->validate(['account' => ['required', 'string', 'max:12']]);

        return $this->attempt(function () use ($save, $key, $data) {
            $account = Account::query()->where('code', $data['account'])->first() ?? throw FinanceException::make('finance.unknown_code', 'Unknown account code.', 422, 'account');
            $save($key, $account->id);

            return redirect()->route('finance.setup.index')->with('status', __('finance::ui.flash.saved'));
        });
    }

    public function storeBank(Request $request, SaveBankAccount $save): RedirectResponse
    {
        $this->authorize('finance.bank.manage');
        $data = $request->validate([
            'account' => ['required', 'string', 'max:12'], 'name' => ['required', 'string', 'max:150'], 'kind' => ['required', 'in:bank,cash'],
            'bank_name' => ['nullable', 'string', 'max:150'], 'account_no' => ['nullable', 'string', 'max:50'], 'currency' => ['required', 'string', 'size:3'],
        ]);

        return $this->attempt(function () use ($save, $data) {
            $account = Account::query()->where('code', $data['account'])->first() ?? throw FinanceException::make('finance.unknown_code', 'Unknown account code.', 422, 'account');
            $save(['account_id' => $account->id] + $data);

            return redirect()->route('finance.setup.index')->with('status', __('finance::ui.flash.saved'));
        });
    }

    public function toggleBank(Request $request, BankAccount $bank, SaveBankAccount $save): RedirectResponse
    {
        $this->authorize('finance.bank.manage');

        return $this->attempt(function () use ($save, $bank) {
            $save(['is_active' => ! $bank->is_active], $bank);

            return redirect()->route('finance.setup.index')->with('status', __('finance::ui.flash.saved'));
        });
    }
}
