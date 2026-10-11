@php $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600'; @endphp
<x-core::layouts.app :title="__('finance::ui.setup.title')">
    <section class="mb-8 max-w-4xl">
        <h2 class="text-base font-semibold text-slate-900">{{ __('finance::ui.setup.mapping') }}</h2>
        <p class="mb-3 mt-1 text-sm text-slate-600">{{ __('finance::ui.setup.mapping_hint') }}</p>
        <x-core::table>
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr><th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.setup.used_for') }}</th><th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.journals.account') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($keys as $key)
                    <tr>
                        <td class="px-4 py-2"><div class="font-medium">{{ __("finance::ui.mapping.{$key}") }}</div><div class="font-mono text-xs text-slate-500">{{ $key }}</div></td>
                        <td class="px-4 py-2">
                            @if ($canMap)
                                <form method="POST" action="{{ route('finance.setup.mappings.save', $key) }}" class="flex items-center gap-2">
                                    @csrf
                                    <select name="account" class="{{ $field }}" aria-label="{{ $key }}">
                                        @foreach ($accounts->whereIn('class', $classes[$key]) as $a) <option value="{{ $a->code }}" @selected(($current[$key]->account->code ?? null) === $a->code)>{{ $a->code }} — {{ $a->name }}</option> @endforeach
                                    </select>
                                    <button type="submit" class="text-sm text-indigo-600 hover:underline">{{ __('finance::ui.common.save') }}</button>
                                </form>
                            @else
                                {{ ($current[$key]->account->code ?? '—').' — '.($current[$key]->account->name ?? '') }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-core::table>
    </section>

    <section class="max-w-4xl">
        <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('finance::ui.setup.banks') }}</h2>
        <x-core::table>
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.common.name') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.journals.account') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.setup.account_no') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.setup.currency') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.common.status') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($banks as $b)
                    <tr>
                        <td class="px-4 py-2">{{ $b->name }}<div class="text-xs text-slate-500">{{ __("finance::ui.setup.kind_{$b->kind}") }}{{ $b->bank_name ? ' · '.$b->bank_name : '' }}</div></td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $b->account->code }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $b->maskedAccountNo() ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $b->currency }}@if ($b->currency !== 'LKR') <div class="text-xs text-amber-700">{{ __('finance::ui.setup.foreign_note') }}</div>@endif</td>
                        <td class="px-4 py-2"><x-core::status-badge :tone="$b->is_active ? 'success' : 'neutral'">{{ __($b->is_active ? 'finance::ui.common.active' : 'finance::ui.common.inactive') }}</x-core::status-badge></td>
                        <td class="px-4 py-2 text-end">@if ($canBank)<form method="POST" action="{{ route('finance.setup.banks.toggle', $b) }}">@csrf<button type="submit" class="text-sm text-indigo-600 hover:underline">{{ __($b->is_active ? 'finance::ui.setup.deactivate' : 'finance::ui.setup.activate') }}</button></form>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </x-core::table>

        @if ($canBank)
            <form method="POST" action="{{ route('finance.setup.banks.store') }}" class="mt-4 grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-3">
                @csrf
                <x-core::input name="account" :label="__('finance::ui.setup.ledger_account')" :value="old('account')" required maxlength="12" :hint="__('finance::ui.setup.ledger_hint')" />
                <x-core::input name="name" :label="__('finance::ui.common.name')" :value="old('name')" required maxlength="150" />
                <div>
                    <label for="f-kind" class="mb-1 block text-sm font-medium text-slate-700">{{ __('finance::ui.setup.kind') }}</label>
                    <select id="f-kind" name="kind" class="{{ $field }}"><option value="bank">{{ __('finance::ui.setup.kind_bank') }}</option><option value="cash">{{ __('finance::ui.setup.kind_cash') }}</option></select>
                </div>
                <x-core::input name="bank_name" :label="__('finance::ui.setup.bank_name')" :value="old('bank_name')" maxlength="150" />
                <x-core::input name="account_no" :label="__('finance::ui.setup.account_no')" :value="old('account_no')" maxlength="50" autocomplete="off" />
                <x-core::input name="currency" :label="__('finance::ui.setup.currency')" :value="old('currency', 'LKR')" required maxlength="3" />
                <div class="sm:col-span-3"><x-core::button type="submit">{{ __('finance::ui.setup.add_bank') }}</x-core::button></div>
            </form>
        @endif
    </section>
</x-core::layouts.app>
