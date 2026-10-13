@php $r = $side === 'receivable' ? 'finance.receivables.' : 'finance.payables.'; $k = $side === 'receivable' ? 'receivables' : 'payables'; @endphp
<x-core::layouts.app :title="__('finance::ui.'.$k.'.title')">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <nav class="flex gap-2 text-sm">
            <a href="{{ route($r.'index') }}" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-white">{{ __('finance::ui.sub.open') }}</a>
            <a href="{{ route($r.'settlements.index') }}" class="rounded-lg bg-white px-3 py-1.5 text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50">{{ __('finance::ui.'.$k.'.settlements') }}</a>
        </nav>
        @if ($canPost)
            <a href="{{ route($r.'settlements.create') }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">{{ __('finance::ui.'.$k.'.new_settlement') }}</a>
        @endif
    </div>

    <section class="mb-6 grid max-w-4xl grid-cols-2 gap-4 rounded-xl border border-slate-200 bg-white p-5 text-sm shadow-sm sm:grid-cols-4">
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('finance::ui.sub.total_open') }}</dt><dd class="mt-0.5 text-lg font-semibold"><x-core::money :value="$ageing['totals']['total']" /></dd></div>
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('finance::ui.sub.on_account') }}</dt><dd class="mt-0.5 text-lg font-semibold"><x-core::money :value="collect($unallocated)->reduce(fn ($c, $u) => App\Support\Decimal::add($c, $u['unallocated']), '0')" /></dd></div>
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('finance::ui.sub.control') }}</dt><dd class="mt-0.5 text-lg font-semibold"><x-core::money :value="$tie['control']" /></dd></div>
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('finance::ui.sub.difference') }}</dt><dd class="mt-0.5 text-lg font-semibold {{ $tie['difference'] === '0.0000' ? 'text-emerald-700' : 'text-red-700' }}"><x-core::money :value="$tie['difference']" /></dd></div>
    </section>

    <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('finance::ui.sub.ageing') }}</h2>
    <x-core::table class="mb-8">
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.'.$k.'.party') }}</th>
                @foreach (['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'total'] as $b) <th class="px-4 py-3 text-end font-medium">{{ __("finance::ui.sub.bucket_{$b}") }}</th> @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($ageing['rows'] as $row)
                <tr>
                    <td class="px-4 py-2"><a href="{{ route($r.'ledger', explode(' — ', $row['party'])[0]) }}" class="text-indigo-600 hover:underline">{{ $row['party'] }}</a></td>
                    @foreach (['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'total'] as $b) <td class="px-4 py-2 text-end">{!! \App\Support\MoneyText::cell($row[$b]) !!}</td> @endforeach
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-6 text-center text-slate-500">{{ __('finance::ui.sub.nothing_owed') }}</td></tr>
            @endforelse
            @if ($ageing['rows'])
                <tr class="bg-slate-50 font-semibold"><td class="px-4 py-2">{{ __('finance::ui.common.total') }}</td>@foreach (['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'total'] as $b) <td class="px-4 py-2 text-end"><x-core::money :value="$ageing['totals'][$b]" /></td> @endforeach</tr>
            @endif
        </tbody>
    </x-core::table>

    <div class="mb-2 flex flex-wrap items-end justify-between gap-3">
        <h2 class="text-base font-semibold text-slate-900">{{ __('finance::ui.'.$k.'.documents') }}</h2>
        <form method="GET" class="flex items-end gap-2">
            <x-core::input name="party" :label="__('finance::ui.'.$k.'.party_code')" :value="$party" class="w-40" />
            <x-core::button type="submit" variant="secondary">{{ __('finance::ui.common.filter') }}</x-core::button>
        </form>
    </div>
    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.sub.document') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.'.$k.'.party') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.common.date') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('finance::ui.sub.due') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('finance::ui.common.amount') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('finance::ui.sub.open_amount') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($items as $i)
                <tr>
                    <td class="px-4 py-2 font-mono text-xs">{{ $i['document_no'] }}</td>
                    <td class="px-4 py-2">{{ $i['party'] }}</td>
                    <td class="px-4 py-2">{{ $i['document_date'] }}</td>
                    <td class="px-4 py-2">{{ $i['due_date'] }}@if ($i['days_overdue'] > 0) <x-core::status-badge tone="danger">{{ __('finance::ui.sub.overdue', ['days' => $i['days_overdue']]) }}</x-core::status-badge>@endif</td>
                    <td class="px-4 py-2 text-end"><x-core::money :value="$i['amount']" /></td>
                    <td class="px-4 py-2 text-end font-medium"><x-core::money :value="$i['open_amount']" /></td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-6 text-center text-slate-500">{{ __('finance::ui.sub.nothing_owed') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
    <div class="mt-4">{{ $items->links() }}</div>
</x-core::layouts.app>
