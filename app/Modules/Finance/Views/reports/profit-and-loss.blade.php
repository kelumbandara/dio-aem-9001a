<x-core::layouts.app :title="__('finance::ui.reports.profit_loss')">
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
        <x-core::input name="from" type="date" :label="__('finance::ui.common.from')" :value="$from" />
        <x-core::input name="to" type="date" :label="__('finance::ui.common.to')" :value="$to" />
        <x-core::button type="submit" variant="secondary">{{ __('finance::ui.common.filter') }}</x-core::button>
    </form>
    <div class="max-w-3xl space-y-4">
        @foreach (['income', 'expense'] as $class)
            <h2 class="text-base font-semibold text-slate-900">{{ __("finance::ui.classes.{$class}") }}</h2>
            @foreach (collect($pl['sections'])->where('class', $class) as $section)
                <x-core::table>
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-2 text-start font-medium" colspan="2">{{ $section['group'] }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($section['rows'] as $row)
                            <tr><td class="px-4 py-2"><a href="{{ route('finance.reports.general-ledger', ['account' => $row['code'], 'from' => $from, 'to' => $to]) }}" class="font-mono text-xs text-indigo-600 hover:underline">{{ $row['code'] }}</a> {{ $row['name'] }}</td><td class="px-4 py-2 text-end"><x-core::money :value="$row['amount']" /></td></tr>
                        @endforeach
                        <tr class="bg-slate-50 font-semibold"><td class="px-4 py-2">{{ __('finance::ui.common.total') }}</td><td class="px-4 py-2 text-end"><x-core::money :value="$section['total']" /></td></tr>
                    </tbody>
                </x-core::table>
            @endforeach
            <p class="text-end text-sm font-semibold">{{ __("finance::ui.reports.total_{$class}") }}: <x-core::money :value="$pl[$class]" /></p>
        @endforeach
        <p class="rounded-xl border border-slate-200 bg-white p-4 text-end text-base font-semibold shadow-sm {{ str_starts_with($pl['net_profit'], '-') ? 'text-red-700' : 'text-emerald-700' }}">{{ str_starts_with($pl['net_profit'], '-') ? __('finance::ui.reports.net_loss') : __('finance::ui.reports.net_profit') }}: <x-core::money :value="$pl['net_profit']" /></p>
    </div>
</x-core::layouts.app>
