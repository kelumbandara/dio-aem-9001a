@php
    $big = 'block w-full rounded-xl border-0 px-4 py-3 text-2xl shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $field = 'block w-full rounded-xl border-0 px-3 py-3 text-base shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $current = $options['current'];
    $shiftValue = old('shift', $current['shift'] ?? '');
    $dateValue = old('date', $current['date'] ?? $today);
    $made = (float) $order['reported_qty'];
    $planned = max((float) $order['planned_qty'], 0.000001);
    $percent = min(100, (int) round($made / $planned * 100));
@endphp
<x-core::layouts.app :title="$order['order_no']">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('manufacturing.report.index') }}" class="text-sm text-indigo-600 hover:underline">← {{ __('manufacturing::ui.report.title') }}</a>
        <a href="{{ route('manufacturing.orders.show', $order['id']) }}" class="text-sm text-indigo-600 hover:underline">{{ __('manufacturing::ui.report.order_page') }}</a>
    </div>

    <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="text-xs font-mono text-slate-500">{{ $order['order_no'] }}</div>
        <div class="text-2xl font-semibold text-slate-900">{{ $order['item_name'] }} <span class="text-base font-normal text-slate-500">{{ $order['item'] }}</span></div>
        <div class="mt-3 flex items-baseline gap-2 text-slate-700"><span class="text-3xl font-semibold">{{ $order['reported_qty'] }}</span> / {{ $order['planned_qty'] }} {{ $order['uom'] }} <span class="text-sm text-slate-500">{{ __('manufacturing::ui.orders.q.reported_qty') }}</span></div>
        <div class="mt-2 h-3 overflow-hidden rounded-full bg-slate-100"><div class="h-3 rounded-full bg-indigo-600" style="width: {{ $percent }}%"></div></div>
        <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-600">
            <span>{{ __('manufacturing::ui.orders.q.rejected_qty') }}: <b>{{ $order['rejected_qty'] }}</b></span>
            <span>{{ __('manufacturing::ui.orders.q.rework_qty') }}: <b>{{ $order['rework_qty'] }}</b></span>
            <span>{{ __('manufacturing::ui.orders.q.accepted_qty') }}: <b>{{ $order['accepted_qty'] }}</b></span>
            <span>{{ __('manufacturing::ui.orders.waiting_inspection') }}: <b>{{ $order['pending_qty'] }}</b></span>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <form method="POST" action="{{ route('manufacturing.report.output', $order['id']) }}" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            @csrf
            <h2 class="text-lg font-semibold text-slate-900">{{ __('manufacturing::ui.report.output') }}</h2>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="o-shift" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.report.shift') }}</label>
                    <select id="o-shift" name="shift" required class="{{ $field }}">
                        <option value="">—</option>
                        @foreach ($options['shifts'] as $s) <option value="{{ $s['code'] }}" @selected($shiftValue === $s['code'])>{{ $s['name'] }} ({{ $s['hours'] }})</option> @endforeach
                    </select>
                    @error('shift') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="o-date" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.report.shift_date') }}</label>
                    <input id="o-date" type="date" name="date" value="{{ $dateValue }}" max="{{ $today }}" class="{{ $field }}">
                    @error('date') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label for="o-produced" class="mb-1 block text-base font-medium text-slate-700">{{ __('manufacturing::ui.report.produced') }} ({{ $order['uom'] }})</label>
                <input id="o-produced" name="produced" value="{{ old('produced') }}" inputmode="decimal" required autocomplete="off" class="{{ $big }}" placeholder="0">
                <p class="mt-1 text-xs text-slate-500">{{ __('manufacturing::ui.report.produced_hint') }}</p>
                @error('produced') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="o-rejected" class="mb-1 block text-base font-medium text-slate-700">{{ __('manufacturing::ui.report.rejected') }}</label>
                    <input id="o-rejected" name="rejected" value="{{ old('rejected') }}" inputmode="decimal" autocomplete="off" class="{{ $big }}" placeholder="0">
                    @error('rejected') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="o-rework" class="mb-1 block text-base font-medium text-slate-700">{{ __('manufacturing::ui.report.rework') }}</label>
                    <input id="o-rework" name="rework" value="{{ old('rework') }}" inputmode="decimal" autocomplete="off" class="{{ $big }}" placeholder="0">
                    @error('rework') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="o-op" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.report.step') }}</label>
                    <select id="o-op" name="operation" class="{{ $field }}">
                        <option value="">—</option>
                        @foreach ($order['operations'] as $op) <option value="{{ $op['op_no'] }}" @selected((string) old('operation') === (string) $op['op_no'])>{{ $op['op_no'] }} · {{ $op['name'] }}</option> @endforeach
                    </select>
                    @error('operation') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="o-machine" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.setup.machines.one') }}</label>
                    <select id="o-machine" name="machine" class="{{ $field }}">
                        <option value="">—</option>
                        @foreach ($options['machines'] as $m) <option value="{{ $m['code'] }}" @selected(old('machine') === $m['code'])>{{ $m['code'] }} {{ $m['name'] }}</option> @endforeach
                    </select>
                    @error('machine') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <input name="notes" value="{{ old('notes') }}" maxlength="500" class="{{ $field }}" placeholder="{{ __('manufacturing::ui.common.notes') }}">
            <button type="submit" class="w-full rounded-xl bg-indigo-600 px-6 py-4 text-lg font-semibold text-white shadow hover:bg-indigo-500">{{ __('manufacturing::ui.report.save_output') }}</button>
        </form>

        @if ($canDowntime)
            <form method="POST" action="{{ route('manufacturing.report.downtime', $order['id']) }}" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" x-data="{ from: '{{ now(config('core.display_timezone'))->format('H:i') }}' }">
                @csrf
                <h2 class="text-lg font-semibold text-slate-900">{{ __('manufacturing::ui.report.downtime') }}</h2>
                <input type="hidden" name="shift" value="{{ $shiftValue }}"><input type="hidden" name="date" value="{{ $dateValue }}">
                <div>
                    <label for="d-machine" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.setup.machines.one') }}</label>
                    <select id="d-machine" name="machine" required class="{{ $field }}">
                        <option value="">—</option>
                        @foreach ($options['machines'] as $m) <option value="{{ $m['code'] }}">{{ $m['code'] }} {{ $m['name'] }}</option> @endforeach
                    </select>
                    @error('machine') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="d-reason" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.report.why_stopped') }}</label>
                    <select id="d-reason" name="reason" required class="{{ $field }}">
                        <option value="">—</option>
                        @foreach (collect($options['reasons'])->groupBy('category') as $category => $reasons)
                            <optgroup label="{{ __("manufacturing::ui.setup.categories.{$category}") }}">
                                @foreach ($reasons as $r) <option value="{{ $r['code'] }}">{{ $r['name'] }}</option> @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('reason') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="d-from" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.report.stopped_at') }}</label>
                        <input id="d-from" type="time" name="from" x-model="from" required class="{{ $field }}">
                        @error('from') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="d-minutes" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.report.minutes') }}</label>
                        <input id="d-minutes" name="minutes" inputmode="numeric" required autocomplete="off" class="{{ $big }}" placeholder="0">
                        @error('minutes') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <input name="notes" maxlength="500" class="{{ $field }}" placeholder="{{ __('manufacturing::ui.common.notes') }}">
                <button type="submit" class="w-full rounded-xl bg-amber-600 px-6 py-4 text-lg font-semibold text-white shadow hover:bg-amber-500">{{ __('manufacturing::ui.report.save_downtime') }}</button>
            </form>
        @endif
    </div>

    <section class="mt-6">
        <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('manufacturing::ui.report.recent') }}</h2>
        <x-core::table>
            <tbody class="divide-y divide-slate-100">
                @forelse ($recent as $r)
                    <tr>
                        <td class="px-4 py-3 text-xs text-slate-500">{{ $r['date'] }} · {{ $r['shift'] }}</td>
                        <td class="px-4 py-3">{{ $r['kind'] === 'output' ? __('manufacturing::ui.report.kind_output') : __('manufacturing::ui.report.kind_rework') }} <b>{{ $r['produced'] }}</b>
                            @if ((float) $r['rejected'] > 0) · {{ __('manufacturing::ui.report.rejected') }} {{ $r['rejected'] }} @endif
                            @if ((float) $r['rework'] > 0) · {{ __('manufacturing::ui.report.rework') }} {{ $r['rework'] }} @endif
                            @if ($r['machine']) · {{ $r['machine'] }} @endif</td>
                        <td class="px-4 py-3 text-end">
                            @if ($canVoid)
                                <form method="POST" action="{{ route('manufacturing.reports.void', $r['id']) }}" class="inline-flex items-center gap-1" onsubmit="return this.reason.value !== ''">
                                    @csrf <input name="reason" required maxlength="255" class="w-36 rounded-lg border-0 px-2 py-1 text-sm ring-1 ring-inset ring-slate-300" placeholder="{{ __('manufacturing::ui.report.void_reason') }}">
                                    <button class="text-sm text-red-600 hover:underline">{{ __('manufacturing::ui.report.void') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-6 text-center text-slate-500">{{ __('manufacturing::ui.report.nothing_yet') }}</td></tr>
                @endforelse
            </tbody>
        </x-core::table>
    </section>
</x-core::layouts.app>
