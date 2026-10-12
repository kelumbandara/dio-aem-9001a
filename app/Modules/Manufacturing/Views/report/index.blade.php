<x-core::layouts.app :title="__('manufacturing::ui.report.title')">
    <p class="mb-4 max-w-3xl text-sm text-slate-600">
        {{ __('manufacturing::ui.report.intro') }}
        @if ($current) <b>{{ __('manufacturing::ui.report.now', ['shift' => $current['name'], 'date' => $current['date']]) }}</b> @else <span class="text-amber-700">{{ __('manufacturing::ui.report.no_shift') }}</span> @endif
    </p>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($rows as $r)
            <a href="{{ route('manufacturing.report.show', $r['id']) }}" class="block rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-indigo-400 hover:shadow">
                <div class="text-xs font-mono text-slate-500">{{ $r['order_no'] }}</div>
                <div class="mt-1 text-xl font-semibold text-slate-900">{{ $r['item_name'] }}</div>
                <div class="text-sm text-slate-500">{{ $r['item'] }}</div>
                <div class="mt-3 text-sm text-slate-600">{{ __('manufacturing::ui.orders.planned') }}: <b>{{ $r['planned_qty'] }} {{ $r['uom'] }}</b> · {{ $r['planned_start'] }} → {{ $r['planned_end'] }}</div>
                <div class="mt-4 inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-base font-medium text-white">{{ __('manufacturing::ui.report.open') }}</div>
            </a>
        @empty
            <div class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-slate-500 sm:col-span-2 xl:col-span-3">{{ __('manufacturing::ui.report.none_open') }}</div>
        @endforelse
    </div>
</x-core::layouts.app>
