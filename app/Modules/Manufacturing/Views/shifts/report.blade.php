@php
    $field = 'block rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
@endphp
<x-core::layouts.app :title="__('manufacturing::ui.shifts.title')">
    <p class="mb-4 max-w-3xl text-sm text-slate-600">{{ __('manufacturing::ui.shifts.intro') }}</p>
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
        <div><label for="from" class="mb-1 block text-xs font-medium text-slate-600">{{ __('manufacturing::ui.shifts.from') }}</label><input id="from" type="date" name="from" value="{{ $from }}" class="{{ $field }}"></div>
        <div><label for="to" class="mb-1 block text-xs font-medium text-slate-600">{{ __('manufacturing::ui.shifts.to') }}</label><input id="to" type="date" name="to" value="{{ $to }}" class="{{ $field }}"></div>
        <div><label for="shift" class="mb-1 block text-xs font-medium text-slate-600">{{ __('manufacturing::ui.report.shift') }}</label>
            <select id="shift" name="shift" class="{{ $field }}"><option value="">{{ __('manufacturing::ui.common.all') }}</option>@foreach ($shifts as $s) <option value="{{ $s->code }}" @selected($shift === $s->code)>{{ $s->name }}</option> @endforeach</select></div>
        <x-core::button type="submit" variant="secondary">{{ __('manufacturing::ui.common.filter') }}</x-core::button>
    </form>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.common.date') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.report.shift') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.report.produced') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.shifts.good') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.report.rejected') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.report.rework') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.shifts.rework_done') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.shifts.downtime') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $r)
                <tr>
                    <td class="px-4 py-3"><a class="text-indigo-600 hover:underline" href="{{ route('manufacturing.shifts.report', ['from' => $from, 'to' => $to, 'shift' => $r['shift'], 'day' => $r['date']]) }}">{{ $r['date'] }}</a></td>
                    <td class="px-4 py-3">{{ $r['shift_name'] }}</td>
                    <td class="px-4 py-3 text-end">{{ $r['produced'] }}</td><td class="px-4 py-3 text-end font-medium">{{ $r['good'] }}</td>
                    <td class="px-4 py-3 text-end">{{ $r['rejected'] }}</td><td class="px-4 py-3 text-end">{{ $r['rework'] }}</td><td class="px-4 py-3 text-end">{{ $r['rework_done'] }}</td>
                    <td class="px-4 py-3 text-end">{{ $r['downtime_minutes'] }} {{ __('manufacturing::ui.orders.min') }} <span class="text-xs text-slate-500">({{ $r['downtime_events'] }})</span></td>
                </tr>
                @foreach ($r['items'] as $i)
                    <tr class="bg-slate-50/60 text-xs">
                        <td></td>
                        <td class="px-4 py-1.5" colspan="2"><span class="font-mono">{{ $i['item'] }}</span> {{ $i['item_name'] }}</td>
                        <td class="px-4 py-1.5 text-end">{{ $i['good'] }} {{ $i['uom'] }}</td>
                        <td class="px-4 py-1.5 text-end" colspan="2">@if ($i['target'] !== null) {{ __('manufacturing::ui.targets.target') }} {{ $i['target'] }} @endif</td>
                        <td class="px-4 py-1.5 text-end" colspan="2">
                            @if ($i['achievement'] !== null)
                                <b @class(['text-emerald-700' => (float) $i['achievement'] >= 100, 'text-amber-700' => (float) $i['achievement'] >= 80 && (float) $i['achievement'] < 100, 'text-red-700' => (float) $i['achievement'] < 80])>{{ $i['achievement'] }} %</b>
                            @endif
                        </td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">{{ __('manufacturing::ui.shifts.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>

    @if ($detail)
        <h2 class="mb-2 mt-6 text-base font-semibold text-slate-900">{{ $day }} · {{ $shift }}</h2>
        <x-core::table>
            <tbody class="divide-y divide-slate-100">
                @foreach ($detail['reports'] as $r)
                    <tr @class(['text-slate-400 line-through' => $r['voided_at']])><td class="px-4 py-2 font-mono text-xs">{{ $r['order_no'] }} {{ $r['item'] }}</td><td class="px-4 py-2">{{ $r['kind'] === 'rework_done' ? '↺' : '' }} {{ \App\Support\Decimal::trim((string) $r['produced_qty']) }}</td>
                        <td class="px-4 py-2 text-xs">{{ __('manufacturing::ui.report.rejected') }} {{ \App\Support\Decimal::trim((string) $r['reject_qty']) }} · {{ __('manufacturing::ui.report.rework') }} {{ \App\Support\Decimal::trim((string) $r['rework_qty']) }} · {{ $r['machine'] }} {{ $r['notes'] }}</td></tr>
                @endforeach
                @foreach ($detail['downtime'] as $d)
                    <tr @class(['text-slate-400 line-through' => $d['voided_at']])><td class="px-4 py-2 font-mono text-xs">{{ $d['machine'] }}</td><td class="px-4 py-2">{{ $d['reason_name'] }} <span class="text-xs text-slate-500">({{ __("manufacturing::ui.setup.categories.{$d['category']}") }})</span></td>
                        <td class="px-4 py-2 text-xs">{{ \App\Support\Decimal::trim((string) $d['minutes']) }} {{ __('manufacturing::ui.orders.min') }} {{ $d['order_no'] }} {{ $d['notes'] }}</td></tr>
                @endforeach
            </tbody>
        </x-core::table>
    @endif
</x-core::layouts.app>
