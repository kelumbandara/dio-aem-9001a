@php $r = $side === 'receivable' ? 'finance.receivables.' : 'finance.payables.'; @endphp
<x-core::layouts.app :title="$party->code.' — '.$party->name">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <x-core::input name="from" type="date" :label="__('finance::ui.common.from')" :value="$from" />
            <x-core::input name="to" type="date" :label="__('finance::ui.common.to')" :value="$to" />
            <x-core::button type="submit" variant="secondary">{{ __('finance::ui.common.filter') }}</x-core::button>
        </form>
        <a href="{{ route($r.'index') }}" class="text-sm text-indigo-600 hover:underline">← {{ __('finance::ui.common.back') }}</a>
    </div>
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
            <tr class="bg-slate-50"><td colspan="5" class="px-4 py-2">{{ __('finance::ui.reports.opening') }}</td><td class="px-4 py-2 text-end"><x-core::money :value="$ledger['opening']" /></td></tr>
            @foreach ($ledger['lines'] as $l)
                <tr>
                    <td class="px-4 py-2">{{ $l['date'] }}</td>
                    <td class="px-4 py-2 font-mono text-xs"><a href="{{ route('finance.journals.show', $l['journal_id']) }}" class="text-indigo-600 hover:underline">{{ $l['journal_no'] }}</a></td>
                    <td class="px-4 py-2">{{ $l['description'] }}</td>
                    <td class="px-4 py-2 text-end"><x-core::money :value="$l['debit']" /></td>
                    <td class="px-4 py-2 text-end"><x-core::money :value="$l['credit']" /></td>
                    <td class="px-4 py-2 text-end"><x-core::money :value="$l['balance']" /></td>
                </tr>
            @endforeach
            <tr class="bg-slate-50 font-semibold"><td colspan="5" class="px-4 py-2">{{ __('finance::ui.reports.closing') }}</td><td class="px-4 py-2 text-end"><x-core::money :value="$ledger['closing']" /></td></tr>
        </tbody>
    </x-core::table>
</x-core::layouts.app>
