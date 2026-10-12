@php
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $running = $order['status'] === 'in_progress';
    $open = in_array($order['status'], ['released', 'in_progress'], true);
    $today = now(config('core.display_timezone'))->toDateString();
    $currentShift = $options['current']['shift'] ?? '';
@endphp

<section class="mb-6">
    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-base font-semibold text-slate-900">{{ __('manufacturing::ui.orders.output') }}</h2>
        @if ($open && $can['report'])
            <a href="{{ route('manufacturing.report.show', $order['id']) }}" class="text-sm font-medium text-indigo-600 hover:underline">{{ __('manufacturing::ui.orders.report_output') }} →</a>
        @endif
    </div>
    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.common.date') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.report.shift') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.report.produced') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.report.rejected') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.report.rework') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.report.step') }} / {{ __('manufacturing::ui.setup.machines.one') }}</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($order['reports'] as $r)
                <tr @class(['text-slate-400 line-through' => $r['voided']])>
                    <td class="px-4 py-2">{{ $r['date'] }}</td>
                    <td class="px-4 py-2">{{ $r['shift'] }}</td>
                    <td class="px-4 py-2 text-end">{{ $r['kind'] === 'rework_done' ? '↺ ' : '' }}{{ $r['produced'] }}</td>
                    <td class="px-4 py-2 text-end">{{ $r['rejected'] }}</td>
                    <td class="px-4 py-2 text-end">{{ $r['rework'] }}</td>
                    <td class="px-4 py-2 text-xs">{{ $r['step'] ?? '—' }} / {{ $r['machine'] ?? '—' }}@if ($r['notes']) · {{ $r['notes'] }} @endif</td>
                    <td class="px-4 py-2 text-end text-sm">
                        @if ($r['voided']) <span class="no-underline">{{ __('manufacturing::ui.report.voided', ['reason' => $r['void_reason']]) }}</span>
                        @elseif ($open && $can['void'])
                            <form method="POST" action="{{ route('manufacturing.reports.void', $r['id']) }}" class="inline-flex items-center gap-1">@csrf
                                <input name="reason" required maxlength="255" class="w-32 rounded-lg border-0 px-2 py-1 text-xs ring-1 ring-inset ring-slate-300" placeholder="{{ __('manufacturing::ui.report.void_reason') }}">
                                <button class="text-red-600 hover:underline">{{ __('manufacturing::ui.report.void') }}</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-6 text-center text-slate-500">{{ __('manufacturing::ui.report.nothing_yet') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>

    @if ($running && $can['report'] && (float) $order['rework_qty'] > 0)
        <form method="POST" action="{{ route('manufacturing.orders.rework', $order['id']) }}" class="mt-3 grid grid-cols-2 items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-5">@csrf
            <div class="col-span-2 text-sm font-medium text-slate-700 sm:col-span-5">{{ __('manufacturing::ui.orders.rework_done_title', ['qty' => $order['rework_qty']]) }}</div>
            <div><label class="mb-1 block text-xs text-slate-600">{{ __('manufacturing::ui.report.shift') }}</label>
                <select name="shift" required class="{{ $field }}"><option value="">—</option>@foreach ($options['shifts'] as $s) <option value="{{ $s['code'] }}" @selected($currentShift === $s['code'])>{{ $s['name'] }}</option> @endforeach</select></div>
            <div><label class="mb-1 block text-xs text-slate-600">{{ __('manufacturing::ui.common.qty') }}</label><input name="qty" inputmode="decimal" required class="{{ $field }}"></div>
            <div class="col-span-2"><label class="mb-1 block text-xs text-slate-600">{{ __('manufacturing::ui.common.notes') }}</label><input name="notes" maxlength="500" class="{{ $field }}"></div>
            <x-core::button type="submit" variant="secondary">{{ __('manufacturing::ui.orders.rework_done') }}</x-core::button>
        </form>
    @endif
</section>

<section class="mb-6">
    <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('manufacturing::ui.orders.inspection') }}</h2>
    @if ($order['inspections'] !== [])
        <x-core::table>
            <tbody class="divide-y divide-slate-100">
                @foreach ($order['inspections'] as $i)
                    <tr>
                        <td class="px-4 py-2">{{ $i['date'] }}</td>
                        <td class="px-4 py-2">{{ __('manufacturing::ui.orders.q.accepted_qty') }} <b>{{ $i['accepted'] }}</b> · {{ __('manufacturing::ui.orders.q.rejected_qty') }} <b>{{ $i['rejected'] }}</b> · {{ __('manufacturing::ui.orders.q.rework_qty') }} <b>{{ $i['rework'] }}</b></td>
                        <td class="px-4 py-2 text-xs text-slate-600">{{ $i['reason'] }} {{ $i['notes'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-core::table>
    @endif
    @if ($running && $can['inspect'] && (float) $order['pending_qty'] > 0)
        <form method="POST" action="{{ route('manufacturing.orders.inspect', $order['id']) }}" class="mt-3 grid grid-cols-2 items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-6">@csrf
            <div class="col-span-2 text-sm font-medium text-slate-700 sm:col-span-6">{{ __('manufacturing::ui.orders.inspect_title', ['qty' => $order['pending_qty'], 'uom' => $order['uom']]) }}</div>
            <div><label class="mb-1 block text-xs text-slate-600">{{ __('manufacturing::ui.orders.q.accepted_qty') }}</label><input name="accepted" inputmode="decimal" class="{{ $field }}" placeholder="{{ $order['pending_qty'] }}"></div>
            <div><label class="mb-1 block text-xs text-slate-600">{{ __('manufacturing::ui.orders.q.rejected_qty') }}</label><input name="rejected" inputmode="decimal" class="{{ $field }}"></div>
            <div><label class="mb-1 block text-xs text-slate-600">{{ __('manufacturing::ui.orders.q.rework_qty') }}</label><input name="rework" inputmode="decimal" class="{{ $field }}"></div>
            <div class="col-span-2"><label class="mb-1 block text-xs text-slate-600">{{ __('manufacturing::ui.orders.reject_reason') }}</label><input name="reject_reason" maxlength="255" class="{{ $field }}"></div>
            <x-core::button type="submit">{{ __('manufacturing::ui.orders.record_inspection') }}</x-core::button>
        </form>
    @endif
</section>

<section class="mb-6">
    <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('manufacturing::ui.orders.finished_goods') }}</h2>
    @if ($order['receipts'] !== [])
        <x-core::table>
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.common.date') }}</th>
                    <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.common.qty') }}</th>
                    <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.orders.unit_cost') }}</th>
                    <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.orders.value') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.orders.stock_document') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($order['receipts'] as $r)
                    <tr><td class="px-4 py-2">{{ $r['date'] }}</td><td class="px-4 py-2 text-end">{{ $r['qty'] }} {{ $order['uom'] }}</td><td class="px-4 py-2 text-end"><x-core::money :value="$r['unit_cost']" /></td>
                        <td class="px-4 py-2 text-end"><x-core::money :value="$r['value']" /></td><td class="px-4 py-2 font-mono text-xs">{{ $r['document'] }}@if ($r['lot_no']) · {{ $r['lot_no'] }} @endif</td></tr>
                @endforeach
            </tbody>
        </x-core::table>
    @endif
    @if ($running && $can['receive'] && (float) $order['waiting_receipt_qty'] > 0)
        <form method="POST" action="{{ route('manufacturing.orders.receive', $order['id']) }}" class="mt-3 grid grid-cols-2 items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-5">@csrf
            <div class="col-span-2 text-sm font-medium text-slate-700 sm:col-span-5">{{ __('manufacturing::ui.orders.receive_title', ['qty' => $order['waiting_receipt_qty'], 'uom' => $order['uom'], 'warehouse' => $order['output_warehouse']]) }}</div>
            <div><label class="mb-1 block text-xs text-slate-600">{{ __('manufacturing::ui.common.qty') }}</label><input name="qty" inputmode="decimal" required class="{{ $field }}" value="{{ $order['waiting_receipt_qty'] }}"></div>
            <div><label class="mb-1 block text-xs text-slate-600">{{ __('manufacturing::ui.orders.lot') }}</label><input name="lot_no" maxlength="60" class="{{ $field }}"></div>
            <div><label class="mb-1 block text-xs text-slate-600">{{ __('manufacturing::ui.orders.expiry') }}</label><input type="date" name="expiry_date" class="{{ $field }}"></div>
            <div><label class="mb-1 block text-xs text-slate-600">{{ __('manufacturing::ui.common.date') }}</label><input type="date" name="date" value="{{ $today }}" max="{{ $today }}" class="{{ $field }}"></div>
            <x-core::button type="submit">{{ __('manufacturing::ui.orders.receive') }}</x-core::button>
        </form>
    @endif
</section>

<section class="mb-6">
    <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('manufacturing::ui.report.downtime') }}</h2>
    <x-core::table>
        <tbody class="divide-y divide-slate-100">
            @forelse ($order['downtime'] as $d)
                <tr @class(['text-slate-400 line-through' => $d['voided']])>
                    <td class="px-4 py-2">{{ $d['date'] }} · {{ $d['shift'] }}</td><td class="px-4 py-2 font-mono text-xs">{{ $d['machine'] }}</td><td class="px-4 py-2">{{ $d['reason'] }}</td>
                    <td class="px-4 py-2 text-end">{{ $d['minutes'] }} {{ __('manufacturing::ui.orders.min') }}</td>
                    <td class="px-4 py-2 text-end text-sm">
                        @if ($d['voided']) <span class="no-underline">{{ __('manufacturing::ui.report.voided', ['reason' => $d['void_reason']]) }}</span>
                        @elseif ($open && $can['void'])
                            <form method="POST" action="{{ route('manufacturing.downtime.void', $d['id']) }}" class="inline-flex items-center gap-1">@csrf
                                <input name="reason" required maxlength="255" class="w-32 rounded-lg border-0 px-2 py-1 text-xs ring-1 ring-inset ring-slate-300" placeholder="{{ __('manufacturing::ui.report.void_reason') }}">
                                <button class="text-red-600 hover:underline">{{ __('manufacturing::ui.report.void') }}</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td class="px-4 py-6 text-center text-slate-500">{{ __('manufacturing::ui.orders.no_downtime') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
</section>

@if ($order['status'] === 'completed')
    <section class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
        <b>{{ __('manufacturing::ui.orders.completed_on', ['date' => substr((string) $order['completed_at'], 0, 10)]) }}</b>
        · {{ __('manufacturing::ui.orders.fg_value') }}: <x-core::money :value="$order['fg_value']" />
        · {{ __('manufacturing::ui.orders.variance') }}: <x-core::money :value="$order['variance_value'] ?? '0'" />
        <p class="mt-1 text-xs">{{ __('manufacturing::ui.orders.variance_hint') }}</p>
    </section>
@elseif ($running && $can['complete'])
    <form method="POST" action="{{ route('manufacturing.orders.complete', $order['id']) }}" class="mb-6" onsubmit="return confirm(@js(__('manufacturing::ui.orders.confirm_complete')))">@csrf
        <x-core::button type="submit" variant="secondary">{{ __('manufacturing::ui.orders.complete') }}</x-core::button>
        <span class="ms-2 text-xs text-slate-500">{{ __('manufacturing::ui.orders.complete_hint') }}</span>
    </form>
@endif
