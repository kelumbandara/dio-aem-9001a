<x-core::layouts.app :title="__('finance::ui.reports.balance_sheet')">
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
        <x-core::input name="as_at" type="date" :label="__('finance::ui.reports.as_at')" :value="$asAt" />
        <x-core::button type="submit" variant="secondary">{{ __('finance::ui.common.filter') }}</x-core::button>
    </form>
    @php $sides = ['assets' => ['assets', $bs['total_assets']], 'liabilities' => ['liabilities', $bs['total_liabilities']], 'equity' => ['equity', $bs['total_equity']]]; @endphp
    <div class="max-w-3xl space-y-6">
        @foreach ($sides as $key => [$name, $total])
            <section>
                <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __("finance::ui.reports.{$key}") }}</h2>
                @foreach ($bs[$name] as $section)
                    <x-core::table class="mb-3">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-2 text-start font-medium" colspan="2">{{ $section['group'] }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($section['rows'] as $row)
                                <tr><td class="px-4 py-2"><a href="{{ route('finance.reports.general-ledger', ['account' => $row['code'], 'to' => $asAt]) }}" class="font-mono text-xs text-indigo-600 hover:underline">{{ $row['code'] }}</a> {{ $row['name'] }}</td><td class="px-4 py-2 text-end"><x-core::money :value="$row['amount']" /></td></tr>
                            @endforeach
                            <tr class="bg-slate-50 font-semibold"><td class="px-4 py-2">{{ __('finance::ui.common.total') }}</td><td class="px-4 py-2 text-end"><x-core::money :value="$section['total']" /></td></tr>
                        </tbody>
                    </x-core::table>
                @endforeach
                @if ($key === 'equity')
                    <x-core::table class="mb-3">
                        <tbody class="divide-y divide-slate-100">
                            <tr><td class="px-4 py-2">{{ __('finance::ui.reports.earlier_profit') }}</td><td class="px-4 py-2 text-end"><x-core::money :value="$bs['earlier_profit']" /></td></tr>
                            <tr><td class="px-4 py-2">{{ __('finance::ui.reports.year_profit', ['from' => $bs['year_start']]) }}</td><td class="px-4 py-2 text-end"><x-core::money :value="$bs['year_profit']" /></td></tr>
                        </tbody>
                    </x-core::table>
                @endif
                <p class="text-end text-sm font-semibold">{{ __("finance::ui.reports.total_{$key}") }}: <x-core::money :value="$total" /></p>
            </section>
        @endforeach
        @php $diff = App\Support\Decimal::sub($bs['total_assets'], App\Support\Decimal::add($bs['total_liabilities'], $bs['total_equity'])); @endphp
        <p class="text-sm {{ App\Support\Decimal::compare($diff, '0', 4) === 0 ? 'text-emerald-700' : 'text-red-700' }}">{{ App\Support\Decimal::compare($diff, '0', 4) === 0 ? __('finance::ui.reports.balanced') : __('finance::ui.reports.out_of_balance') }}</p>
    </div>
</x-core::layouts.app>
