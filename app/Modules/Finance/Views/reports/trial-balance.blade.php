@php $tone = $tb['total_debit'] === $tb['total_credit'] ? 'text-emerald-700' : 'text-red-700'; @endphp
<x-core::layouts.app :title="__('finance::ui.reports.trial_balance')">
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
        <x-core::input name="from" type="date" :label="__('finance::ui.common.from')" :value="$from" />
        <x-core::input name="to" type="date" :label="__('finance::ui.common.to')" :value="$to" />
        <x-core::button type="submit" variant="secondary">{{ __('finance::ui.common.filter') }}</x-core::button>
    </form>
    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.journals.account') }}</th>
                @if ($from) <th class="px-4 py-3 text-end font-medium" colspan="2">{{ __('finance::ui.reports.opening') }}</th> <th class="px-4 py-3 text-end font-medium" colspan="2">{{ __('finance::ui.reports.movement') }}</th> @endif
                <th class="px-4 py-3 text-end font-medium">{{ __('finance::ui.journals.debit') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('finance::ui.journals.credit') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($tb['rows'] as $r)
                <tr>
                    <td class="px-4 py-2"><a href="{{ route('finance.reports.general-ledger', ['account' => $r['code'], 'from' => $from, 'to' => $to]) }}" class="font-mono text-xs text-indigo-600 hover:underline">{{ $r['code'] }}</a> {{ $r['name'] }}</td>
                    @if ($from)
                        <td class="px-4 py-2 text-end"><x-core::money :value="$r['opening_debit']" /></td><td class="px-4 py-2 text-end"><x-core::money :value="$r['opening_credit']" /></td>
                        <td class="px-4 py-2 text-end"><x-core::money :value="$r['debit']" /></td><td class="px-4 py-2 text-end"><x-core::money :value="$r['credit']" /></td>
                    @endif
                    <td class="px-4 py-2 text-end"><x-core::money :value="$r['closing_debit']" /></td>
                    <td class="px-4 py-2 text-end"><x-core::money :value="$r['closing_credit']" /></td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">{{ __('finance::ui.reports.empty') }}</td></tr>
            @endforelse
            <tr class="bg-slate-50 font-semibold {{ $tone }}"><td class="px-4 py-2" @if ($from) colspan="5" @endif>{{ __('finance::ui.common.total') }}</td><td class="px-4 py-2 text-end"><x-core::money :value="$tb['total_debit']" /></td><td class="px-4 py-2 text-end"><x-core::money :value="$tb['total_credit']" /></td></tr>
        </tbody>
    </x-core::table>
</x-core::layouts.app>
