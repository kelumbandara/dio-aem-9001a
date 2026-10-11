@php $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600'; $code = $account?->code ?? ($code ?? ''); @endphp
<x-core::layouts.app :title="$account ? $account->code.' — '.$account->name : __('finance::ui.reports.general_ledger')">
    <form method="GET" action="{{ route('finance.reports.general-ledger') }}" class="mb-4 flex flex-wrap items-end gap-2">
        <div>
            <label for="f-account" class="mb-1 block text-sm font-medium text-slate-700">{{ __('finance::ui.journals.account') }}</label>
            <input id="f-account" name="account" value="{{ $code }}" list="gl-accounts" autocomplete="off" class="{{ $field }} w-40">
            <datalist id="gl-accounts">@foreach ($accounts as $a) <option value="{{ $a->code }}">{{ $a->name }}</option> @endforeach</datalist>
        </div>
        <x-core::input name="from" type="date" :label="__('finance::ui.common.from')" :value="$from" />
        <x-core::input name="to" type="date" :label="__('finance::ui.common.to')" :value="$to" />
        <x-core::button type="submit" variant="secondary">{{ __('finance::ui.common.filter') }}</x-core::button>
    </form>

    @if ($gl)
        <x-core::table>
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.common.date') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.journals.number') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.common.description') }}</th>
                    <th class="px-4 py-3 text-end font-medium">{{ __('finance::ui.journals.debit') }}</th>
                    <th class="px-4 py-3 text-end font-medium">{{ __('finance::ui.journals.credit') }}</th>
                    <th class="px-4 py-3 text-end font-medium">{{ __('finance::ui.sub.balance') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr class="bg-slate-50"><td colspan="5" class="px-4 py-2">{{ __('finance::ui.reports.opening') }}</td><td class="px-4 py-2 text-end"><x-core::money :value="$gl['opening']" /></td></tr>
                @foreach ($gl['lines'] as $l)
                    <tr>
                        <td class="px-4 py-2">{{ $l['date'] }}</td>
                        <td class="px-4 py-2 font-mono text-xs"><a href="{{ route('finance.journals.show', $l['journal_id']) }}" class="text-indigo-600 hover:underline">{{ $l['journal_no'] }}</a></td>
                        <td class="px-4 py-2">{{ $l['description'] }}</td>
                        <td class="px-4 py-2 text-end"><x-core::money :value="$l['debit']" /></td>
                        <td class="px-4 py-2 text-end"><x-core::money :value="$l['credit']" /></td>
                        <td class="px-4 py-2 text-end"><x-core::money :value="$l['balance']" /></td>
                    </tr>
                @endforeach
                <tr class="bg-slate-50 font-semibold"><td colspan="5" class="px-4 py-2">{{ __('finance::ui.reports.closing') }}</td><td class="px-4 py-2 text-end"><x-core::money :value="$gl['closing']" /></td></tr>
            </tbody>
        </x-core::table>
        <p class="mt-2 text-xs text-slate-500">{{ __('finance::ui.reports.balance_sign') }}</p>
    @else
        <p class="text-sm text-slate-600">{{ __('finance::ui.reports.pick_account') }}</p>
    @endif
</x-core::layouts.app>
