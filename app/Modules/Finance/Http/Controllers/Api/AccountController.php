<?php

namespace App\Modules\Finance\Http\Controllers\Api;

use App\Modules\Finance\Actions\SaveAccount;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\FinancialReports;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function index(Request $request, FinancialReports $reports): JsonResponse
    {
        $this->authorize('finance.view');
        $request->validate([
            'filter.q' => ['sometimes', 'string', 'max:100'], 'filter.class' => ['sometimes', Rule::in(Account::CLASSES)],
            'filter.is_active' => ['sometimes', 'in:true,false,1,0'], 'filter.groups' => ['sometimes', 'in:true,false,1,0'], 'with_balance' => ['sometimes', 'in:true,false,1,0'],
        ]);
        $f = (array) $request->input('filter', []);

        $accounts = Account::query()->with('parent:id,code,name')
            ->when($f['class'] ?? null, fn ($q, $v) => $q->where('class', $v))
            ->when(isset($f['is_active']), fn ($q) => $q->where('is_active', filter_var($f['is_active'], FILTER_VALIDATE_BOOLEAN)))
            ->when(! filter_var($f['groups'] ?? true, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->where('is_group', false))
            ->when($f['q'] ?? null, function ($q, $v) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(code) like ?', [$term])->orWhereRaw('lower(name) like ?', [$term]));
            })
            ->orderBy('code')->get();

        $withBalance = $request->boolean('with_balance');

        return response()->json(['data' => $accounts->map(fn (Account $a) => $this->present($a, $withBalance && ! $a->is_group ? $reports->balanceOf($a->id) : null))->values()]);
    }

    public function show(Account $account, FinancialReports $reports): JsonResponse
    {
        $this->authorize('finance.view');

        return response()->json(['data' => $this->present($account->load('parent:id,code,name'), $account->is_group ? null : $reports->balanceOf($account->id))]);
    }

    public function store(Request $request, SaveAccount $save): JsonResponse
    {
        $this->authorize('finance.account.manage');
        $account = $save($this->data($request, true));

        return response()->json(['data' => $this->present($account->load('parent:id,code,name'), null)], 201)->header('Location', route('api.v1.accounts.show', $account));
    }

    public function update(Request $request, Account $account, SaveAccount $save): JsonResponse
    {
        $this->authorize('finance.account.manage');
        $data = $this->data($request, false);
        $updated = $save($data, $account, (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version']);

        return response()->json(['data' => $this->present($updated->load('parent:id,code,name'), null)]);
    }

    /** @return array<string, mixed> */
    private function data(Request $request, bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';
        $v = $request->validate([
            'code' => [$required, 'string', 'max:12'], 'name' => [$required, 'string', 'max:150'], 'class' => [$required, Rule::in(Account::CLASSES)],
            'parent' => ['sometimes', 'nullable', 'string', 'max:12'], 'is_group' => ['sometimes', 'boolean'], 'subtype' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'], 'notes' => ['sometimes', 'nullable', 'string', 'max:255'], 'version' => $create ? ['prohibited'] : ['required', 'integer', 'min:1'],
        ]);
        unset($v['version']);
        if (array_key_exists('parent', $v)) {
            $v['parent_id'] = $v['parent'] === null ? null : (Account::query()->where('code', $v['parent'])->value('id') ?? abort(422, 'Unknown parent group code.'));
            unset($v['parent']);
        }

        return $v;
    }

    /** @return array<string, mixed> */
    private function present(Account $a, ?string $balance): array
    {
        return [
            'id' => $a->public_id, 'code' => $a->code, 'name' => $a->name, 'class' => $a->class, 'is_group' => $a->is_group, 'parent' => $a->parent?->code,
            'subtype' => $a->subtype, 'is_active' => $a->is_active, 'notes' => $a->notes, 'version' => $a->version,
            ...($balance !== null ? ['balance' => $balance] : []),
        ];
    }
}
