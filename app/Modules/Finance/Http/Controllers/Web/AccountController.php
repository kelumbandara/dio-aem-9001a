<?php

namespace App\Modules\Finance\Http\Controllers\Web;

use App\Modules\Finance\Actions\SaveAccount;
use App\Modules\Finance\Http\Controllers\Web\Concerns\ReportsBusinessErrors;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\FinancialReports;
use App\Support\Decimal;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountController extends Controller
{
    use ReportsBusinessErrors;

    public function index(Request $request): View
    {
        $this->authorize('finance.view');
        $show = $request->query('show', 'active');

        $net = DB::table('journal_lines')->selectRaw('account_id, sum(debit) as d, sum(credit) as c')->groupBy('account_id')->get()->keyBy('account_id');
        $groups = Account::query()->where('is_group', true)->orderBy('code')->get();
        $accounts = Account::query()->where('is_group', false)->when($show === 'active', fn ($q) => $q->where('is_active', true))->orderBy('code')->get()->groupBy('parent_id');

        $classes = [];
        foreach ($groups as $g) {
            $rows = ($accounts[$g->id] ?? collect())->map(function (Account $a) use ($net) {
                $n = $net[$a->id] ?? null;
                $bal = $n === null ? '0' : Decimal::sub((string) $n->d, (string) $n->c);

                return ['account' => $a, 'balance' => Decimal::round($bal, 4)];
            });
            $classes[$g->class][] = ['group' => $g, 'rows' => $rows];
        }

        return view('finance::accounts.index', ['classes' => $classes, 'show' => $show]);
    }

    public function create(): View
    {
        $this->authorize('finance.account.manage');

        return view('finance::accounts.form', ['account' => null, 'groups' => Account::query()->where('is_group', true)->orderBy('code')->get(['id', 'code', 'name', 'class'])]);
    }

    public function store(Request $request, SaveAccount $save): RedirectResponse
    {
        $this->authorize('finance.account.manage');
        $data = $this->validated($request, true);

        return $this->attempt(function () use ($save, $data) {
            $save($data);

            return redirect()->route('finance.accounts.index')->with('status', __('finance::ui.flash.saved'));
        });
    }

    public function edit(Account $account): View
    {
        $this->authorize('finance.account.manage');

        return view('finance::accounts.form', ['account' => $account, 'groups' => Account::query()->where('is_group', true)->orderBy('code')->get(['id', 'code', 'name', 'class'])]);
    }

    public function update(Request $request, Account $account, SaveAccount $save): RedirectResponse
    {
        $this->authorize('finance.account.manage');
        $data = $this->validated($request, false);

        return $this->attempt(function () use ($save, $data, $account, $request) {
            $save($data, $account, (int) $request->input('version'));

            return redirect()->route('finance.accounts.index')->with('status', __('finance::ui.flash.saved'));
        });
    }

    public function ledger(Account $account, Request $request, FinancialReports $reports): View
    {
        $this->authorize('finance.report.view');

        return view('finance::reports.general-ledger', $this->glData($account, $request->query('from') ?: null, (string) ($request->query('to') ?: now((string) config('core.display_timezone'))->toDateString()), $reports));
    }

    /** @return array<string, mixed> */
    private function glData(Account $account, ?string $from, string $to, FinancialReports $reports): array
    {
        return ['account' => $account, 'gl' => $reports->generalLedger($account, $from, $to), 'accounts' => Account::query()->where('is_group', false)->orderBy('code')->get(['code', 'name']), 'from' => $from, 'to' => $to];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $create): array
    {
        $v = $request->validate([
            'code' => $create ? ['required', 'string', 'max:12'] : ['sometimes', 'string', 'max:12'], 'name' => ['required', 'string', 'max:150'],
            'class' => ['required', Rule::in(Account::CLASSES)], 'parent_id' => ['nullable', 'integer'], 'is_group' => ['sometimes', 'boolean'],
            'subtype' => ['nullable', 'string', 'max:20'], 'is_active' => ['sometimes', 'boolean'], 'notes' => ['nullable', 'string', 'max:255'],
            'version' => $create ? ['prohibited'] : ['required', 'integer', 'min:1'],
        ]);
        unset($v['version']);

        return $v;
    }
}
