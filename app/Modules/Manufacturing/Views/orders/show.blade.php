@php
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $tones = ['draft' => 'neutral', 'released' => 'info', 'in_progress' => 'warning', 'completed' => 'success', 'closed' => 'success', 'cancelled' => 'danger'];
    $open = in_array($order['status'], ['released', 'in_progress'], true);
    $today = now(config('core.display_timezone'))->toDateString();
    $anyHeld = collect($order['materials'])->contains(fn ($m) => (float) $m['net_qty'] > 0);
@endphp
<x-core::layouts.app :title="$order['order_no']">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="text-lg font-semibold text-slate-900"><span class="font-mono text-base">{{ $order['item'] }}</span> {{ $order['item_name'] }}</div>
            <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                <x-core::status-badge :tone="$tones[$order['status']] ?? 'neutral'">{{ __("manufacturing::ui.status.{$order['status']}") }}</x-core::status-badge>
                <span>{{ __('manufacturing::ui.orders.bom') }} <a class="text-indigo-600 hover:underline" href="{{ route('manufacturing.boms.show', $order['bom_id']) }}">{{ $order['bom'] }}</a>
                    @if ($order['bom_status'] !== 'active') ({{ __("manufacturing::ui.status.{$order['bom_status']}") }}) @endif</span>
                <span>· {{ $order['planned_start'] }} → {{ $order['planned_end'] }}</span>
                @if ($order['line']) <span>· {{ $order['line'] }}</span> @endif
            </div>
            @if ($order['notes']) <p class="mt-1 text-sm text-slate-600">{{ $order['notes'] }}</p> @endif
            @if ($order['cancel_reason']) <p class="mt-1 text-sm text-red-700">{{ __('manufacturing::ui.orders.cancelled_because', ['reason' => $order['cancel_reason']]) }}</p> @endif
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($order['status'] === 'draft' && $can['manage'])
                <a href="{{ route('manufacturing.orders.edit', $order['id']) }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('manufacturing::ui.common.edit') }}</a>
            @endif
            @if ($order['status'] === 'draft' && $can['release'])
                <form method="POST" action="{{ route('manufacturing.orders.release', $order['id']) }}">@csrf
                    <x-core::button type="submit">{{ __('manufacturing::ui.orders.release') }}</x-core::button></form>
            @endif
        </div>
    </div>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
        @foreach (['planned_qty', 'reported_qty', 'accepted_qty', 'rejected_qty', 'rework_qty', 'received_qty'] as $q)
            <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="text-xs text-slate-500">{{ __("manufacturing::ui.orders.q.{$q}") }}</div>
                <div class="text-lg font-semibold text-slate-900">{{ $order[$q] }} <span class="text-xs font-normal text-slate-500">{{ $order['uom'] }}</span></div>
            </div>
        @endforeach
    </div>

    <section class="mb-6">
        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-base font-semibold text-slate-900">{{ __('manufacturing::ui.orders.materials') }}</h2>
            <div class="text-sm text-slate-600">{{ __('manufacturing::ui.orders.from') }} {{ $order['source_warehouse'] }} · {{ __('manufacturing::ui.orders.wip') }}: <b><x-core::money :value="$order['wip_value']" /></b></div>
        </div>
        <form method="POST" id="issue-form" action="{{ route('manufacturing.orders.issue', $order['id']) }}">
            @csrf
            <x-core::table>
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 text-start font-medium">#</th>
                        <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.common.item') }}</th>
                        <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.orders.required') }}</th>
                        <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.orders.issued') }}</th>
                        <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.orders.returned') }}</th>
                        <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.orders.with_order') }}</th>
                        <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.orders.to_issue') }}</th>
                        @if ($open && $can['issue']) <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.orders.qty_now') }}</th> @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($order['materials'] as $i => $m)
                        <tr>
                            <td class="px-4 py-3 text-slate-500">{{ $m['line_no'] }}</td>
                            <td class="px-4 py-3"><span class="font-mono text-xs">{{ $m['item'] }}</span> {{ $m['item_name'] }}</td>
                            <td class="px-4 py-3 text-end">{{ $m['required_qty'] }} {{ $m['uom'] }}</td>
                            <td class="px-4 py-3 text-end">{{ $m['issued_qty'] }}</td>
                            <td class="px-4 py-3 text-end">{{ $m['returned_qty'] }}</td>
                            <td class="px-4 py-3 text-end font-medium">{{ $m['net_qty'] }}</td>
                            <td class="px-4 py-3 text-end">{{ $m['remaining_qty'] }}</td>
                            @if ($open && $can['issue'])
                                <td class="px-4 py-3">
                                    <input type="hidden" name="lines[{{ $i }}][material]" value="{{ $m['line_no'] }}">
                                    <div class="flex gap-1">
                                        <input name="lines[{{ $i }}][qty]" inputmode="decimal" class="{{ $field }} w-28" placeholder="{{ $m['remaining_qty'] }}" aria-label="{{ __('manufacturing::ui.orders.qty_now') }} {{ $m['item'] }}">
                                        @if ($m['tracks_lots']) <input name="lines[{{ $i }}][lot_no]" class="{{ $field }} w-28" placeholder="{{ __('manufacturing::ui.orders.lot') }}"> @endif
                                    </div>
                                </td>
                            @endif
                        </tr>
                        @foreach ($m['movements'] as $mv)
                            <tr class="bg-slate-50/60 text-xs text-slate-600">
                                <td></td>
                                <td class="px-4 py-1.5" colspan="{{ $open && $can['issue'] ? 7 : 6 }}">
                                    {{ $mv['date'] }} · {{ __("manufacturing::ui.orders.kind.{$mv['kind']}") }} {{ $mv['qty'] }} · <x-core::money :value="$mv['value']" /> · {{ $mv['document'] }}
                                    @if ($mv['over_issue']) · <span class="text-amber-700">{{ __('manufacturing::ui.orders.over') }}</span> @endif
                                    @if ($mv['reason']) · {{ $mv['reason'] }} @endif
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </x-core::table>

            @if ($open && $can['issue'])
                <div class="mt-3 grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-4">
                    <x-core::input name="date" type="date" :label="__('manufacturing::ui.common.date')" :value="old('date', $today)" />
                    <div class="sm:col-span-3"><x-core::input name="reason" :label="__('manufacturing::ui.orders.reason_label')" :value="old('reason')" :hint="$can['over'] ? __('manufacturing::ui.orders.reason_hint_over') : __('manufacturing::ui.orders.reason_hint')" /></div>
                    <div class="flex flex-wrap items-end gap-2 sm:col-span-4">
                        <x-core::button type="submit">{{ __('manufacturing::ui.orders.issue_selected') }}</x-core::button>
                        <button type="submit" formaction="{{ route('manufacturing.orders.return', $order['id']) }}" @disabled(! $anyHeld)
                                class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 disabled:opacity-50">{{ __('manufacturing::ui.orders.return_selected') }}</button>
                    </div>
                </div>
            @endif
        </form>
    </section>

    @include('manufacturing::orders.partials.shopfloor')

    <section class="mb-6">
        <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('manufacturing::ui.boms.routing') }}</h2>
        <x-core::table>
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.boms.step') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.common.name') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.setup.work-centers.one') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.setup.machines.one') }}</th>
                    <th class="px-4 py-3 text-end font-medium">{{ __('manufacturing::ui.orders.planned_min') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('manufacturing::ui.common.status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($order['operations'] as $o)
                    <tr>
                        <td class="px-4 py-3 text-slate-500">{{ $o['op_no'] }}</td>
                        <td class="px-4 py-3">{{ $o['name'] }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $o['work_center'] }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $o['machine'] ?? '—' }}</td>
                        <td class="px-4 py-3 text-end">{{ \App\Support\Decimal::trim(\App\Support\Decimal::add($o['setup_minutes'], $o['run_minutes'])) }}</td>
                        <td class="px-4 py-3">{{ __("manufacturing::ui.status.{$o['status']}") }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-slate-500">{{ __('manufacturing::ui.boms.no_routing') }}</td></tr>
                @endforelse
            </tbody>
        </x-core::table>
    </section>

    @if (in_array($order['status'], ['draft', 'released', 'in_progress'], true) && $can['manage'])
        <form method="POST" action="{{ route('manufacturing.orders.cancel', $order['id']) }}" class="flex flex-wrap items-end gap-2 rounded-xl border border-slate-200 bg-white p-4 shadow-sm" x-on:submit="if (! confirm(@js(__('manufacturing::ui.orders.confirm_cancel')))) $event.preventDefault()">
            @csrf
            <div class="min-w-64 flex-1">
                <label for="cancel-reason" class="mb-1 block text-sm font-medium text-slate-700">{{ __('manufacturing::ui.orders.cancel_reason') }}</label>
                <input id="cancel-reason" name="reason" required maxlength="255" class="{{ $field }}">
            </div>
            <x-core::button type="submit" variant="danger">{{ __('manufacturing::ui.orders.cancel') }}</x-core::button>
        </form>
    @endif
</x-core::layouts.app>
