<?php

namespace App\Modules\MasterData\Http\Controllers\Web;

use App\Modules\MasterData\Actions\RequestSupplierBankAccountChange;
use App\Modules\MasterData\Actions\SaveSupplier;
use App\Modules\MasterData\Http\Requests\BankAccountRequest;
use App\Modules\MasterData\Http\Requests\SaveSupplierRequest;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Models\SupplierBankAccount;
use App\Modules\MasterData\Support\CodeLookup;
use App\Modules\MasterData\Support\InputMapper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SupplierController extends PartyController
{
    protected function model(): string
    {
        return Supplier::class;
    }

    protected function slug(): string
    {
        return 'suppliers';
    }

    protected function priceListType(): string
    {
        return PriceList::PURCHASE;
    }

    protected function relations(): array
    {
        return [...parent::relations(), 'bankAccounts'];
    }

    protected function showExtras(Model $party): array
    {
        return ['canSeeBank' => auth()->user()->can('viewBankDetails', $party), 'canManageBank' => auth()->user()->can('manageBankAccounts', $party)];
    }

    public function show(Supplier $supplier): View
    {
        return $this->renderShow($supplier);
    }

    public function edit(Supplier $supplier): View
    {
        return $this->renderEdit($supplier);
    }

    public function store(SaveSupplierRequest $request, SaveSupplier $save, InputMapper $map): RedirectResponse
    {
        return $this->attempt(fn () => $this->saved($save($map->supplier($this->normalise($request->validated())))));
    }

    public function update(SaveSupplierRequest $request, Supplier $supplier, SaveSupplier $save, InputMapper $map): RedirectResponse
    {
        return $this->attempt(function () use ($request, $supplier, $save, $map) {
            $data = $this->normalise($request->validated());

            return $this->saved($save($map->supplier($data, $supplier), $supplier, (int) $data['version']));
        });
    }

    public function addBankAccount(BankAccountRequest $request, Supplier $supplier, RequestSupplierBankAccountChange $change, CodeLookup $codes): RedirectResponse
    {
        return $this->bankChange($request, $supplier, $change, $codes, 'create');
    }

    public function deactivateBankAccount(BankAccountRequest $request, Supplier $supplier, SupplierBankAccount $account, RequestSupplierBankAccountChange $change, CodeLookup $codes): RedirectResponse
    {
        return $this->bankChange($request, $supplier, $change, $codes, 'deactivate', $account);
    }

    private function bankChange(BankAccountRequest $request, Supplier $supplier, RequestSupplierBankAccountChange $change, CodeLookup $codes, string $op, ?SupplierBankAccount $account = null): RedirectResponse
    {
        abort_if($account !== null && $account->supplier_id !== $supplier->id, 404);

        return $this->attempt(function () use ($request, $supplier, $change, $codes, $op, $account) {
            $in = $request->validated();
            $outcome = $change([
                'op' => $op,
                'supplier_id' => $supplier->id,
                'account_id' => $account?->id,
                ...$in,
                'currency_id' => $codes->currency($in['currency'] ?? null),
            ], $request->user()->id, 'web');

            return redirect()->route('suppliers.show', $supplier)->with('status', $outcome->applied
                ? __('masterdata::ui.flash.saved')
                : __('masterdata::ui.flash.awaiting_approval', ['document' => $outcome->documentNo]));
        });
    }
}
