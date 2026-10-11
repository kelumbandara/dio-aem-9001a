@php $isSupplier = $slug === 'suppliers'; $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600'; @endphp
<x-core::layouts.app :title="$party->code.' — '.$party->name">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-core::status-badge :tone="$party->is_active ? 'success' : 'neutral'">{{ __($party->is_active ? 'masterdata::ui.common.active' : 'masterdata::ui.common.inactive') }}</x-core::status-badge>
        <div class="flex gap-2">
            <a href="{{ route($slug.'.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('masterdata::ui.common.back') }}</a>
            @can('update', $party)
                <a href="{{ route($slug.'.edit', $party) }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">{{ __('masterdata::ui.common.edit') }}</a>
            @endcan
        </div>
    </div>

    <section class="max-w-4xl rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
            @foreach ([
                'legal_name' => $party->legal_name, 'kind' => __("masterdata::ui.parties.kinds.{$party->kind}"), 'tax_id' => $party->tax_id,
                'business_reg_no' => $party->business_reg_no, 'phone' => $party->phone, 'email' => $party->email, 'website' => $party->website,
                'currency' => $party->currency?->code, 'payment_terms' => $party->paymentTerms ? $party->paymentTerms->name : null,
                'price_list' => $party->priceList ? $party->priceList->code.' — '.$party->priceList->name : null,
                $isSupplier ? 'lead_time' : 'credit_limit' => $isSupplier ? ($party->lead_time_days !== null ? __('masterdata::ui.items.days', ['count' => $party->lead_time_days]) : null) : $party->credit_limit,
            ] as $label => $value)
                <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __("masterdata::ui.parties.{$label}") }}</dt>
                    <dd class="mt-0.5 text-slate-900">
                        @if ($label === 'credit_limit' && $value !== null) <x-core::money :value="$value" /> @else {{ $value ?: '—' }} @endif
                    </dd></div>
            @endforeach
        </dl>
        @if ($party->notes) <p class="mt-4 whitespace-pre-line text-sm text-slate-700">{{ $party->notes }}</p> @endif
    </section>

    <div class="mt-6 grid max-w-4xl grid-cols-1 gap-6 md:grid-cols-2">
        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">{{ __('masterdata::ui.parties.contacts') }}</h2>
            <ul class="mt-3 space-y-3 text-sm">
                @forelse ($party->contacts as $c)
                    <li><span class="font-medium">{{ $c->name }}</span> @if ($c->is_primary)<x-core::status-badge tone="info">{{ __('masterdata::ui.parties.primary') }}</x-core::status-badge>@endif
                        <div class="text-slate-600">{{ collect([$c->job_title, $c->phone, $c->email])->filter()->implode(' · ') }}</div></li>
                @empty <li class="text-slate-500">{{ __('masterdata::ui.parties.no_contacts') }}</li> @endforelse
            </ul>
        </section>
        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">{{ __('masterdata::ui.parties.addresses') }}</h2>
            <ul class="mt-3 space-y-3 text-sm">
                @forelse ($party->addresses as $a)
                    <li><span class="font-medium">{{ __("masterdata::ui.parties.address_types.{$a->type}") }}</span> @if ($a->is_default)<x-core::status-badge tone="info">{{ __('masterdata::ui.parties.default') }}</x-core::status-badge>@endif
                        <div class="text-slate-600">{{ collect([$a->line1, $a->line2, $a->city, $a->district, $a->postal_code])->filter()->implode(', ') }}</div></li>
                @empty <li class="text-slate-500">{{ __('masterdata::ui.parties.no_addresses') }}</li> @endforelse
            </ul>
        </section>
    </div>

    @if ($isSupplier && $canSeeBank)
        <section class="mt-6 max-w-4xl rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">{{ __('masterdata::ui.bank.title') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('masterdata::ui.bank.intro') }}</p>
            <x-core::table class="mt-3">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.bank.bank') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.bank.account_name') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.bank.account_no') }}</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('masterdata::ui.common.status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($party->bankAccounts as $b)
                        <tr>
                            <td class="px-4 py-3">{{ $b->bank_name }}@if ($b->branch_name)<div class="text-xs text-slate-500">{{ $b->branch_name }}</div>@endif</td>
                            <td class="px-4 py-3">{{ $b->account_name }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $b->maskedAccountNo() }}</td>
                            <td class="px-4 py-3">
                                <x-core::status-badge :tone="$b->is_active ? 'success' : 'neutral'">{{ __($b->is_active ? 'masterdata::ui.common.active' : 'masterdata::ui.common.inactive') }}</x-core::status-badge>
                                @if ($b->is_default) <x-core::status-badge tone="info">{{ __('masterdata::ui.parties.default') }}</x-core::status-badge> @endif
                            </td>
                            <td class="px-4 py-3 text-end">
                                @if ($canManageBank && $b->is_active)
                                    <form method="POST" action="{{ route('suppliers.bank-accounts.deactivate', [$party, $b]) }}">@csrf @method('DELETE')<button type="submit" class="text-sm text-red-600 hover:underline">{{ __('masterdata::ui.bank.deactivate') }}</button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">{{ __('masterdata::ui.bank.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </x-core::table>

            @if ($canManageBank && $party->is_active)
                <form method="POST" action="{{ route('suppliers.bank-accounts.store', $party) }}" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    @csrf
                    <x-core::input name="bank_name" :label="__('masterdata::ui.bank.bank')" :value="old('bank_name')" required maxlength="150" />
                    <x-core::input name="branch_name" :label="__('masterdata::ui.bank.branch')" :value="old('branch_name')" maxlength="150" />
                    <x-core::input name="account_name" :label="__('masterdata::ui.bank.account_name')" :value="old('account_name')" required maxlength="150" />
                    <x-core::input name="account_no" :label="__('masterdata::ui.bank.account_no')" :value="old('account_no')" required maxlength="50" autocomplete="off" />
                    <div>
                        <label for="f-bank-currency" class="mb-1 block text-sm font-medium text-slate-700">{{ __('masterdata::ui.parties.currency') }}</label>
                        <select id="f-bank-currency" name="currency" class="{{ $field }}"><option value="">—</option>
                            @foreach (App\Modules\MasterData\Models\Currency::where('is_active', true)->orderBy('code')->pluck('code') as $code) <option value="{{ $code }}" @selected(old('currency', 'LKR') === $code)>{{ $code }}</option> @endforeach
                        </select>
                    </div>
                    <div class="flex items-end gap-3">
                        <input type="hidden" name="is_default" value="0">
                        <label class="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" name="is_default" value="1" class="rounded border-slate-300 text-indigo-600"> {{ __('masterdata::ui.parties.default') }}</label>
                        <x-core::button type="submit">{{ __('masterdata::ui.bank.add') }}</x-core::button>
                    </div>
                </form>
            @endif
        </section>
    @endif
</x-core::layouts.app>
