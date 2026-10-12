@php
    $tones = ['draft' => 'neutral', 'pending_approval' => 'warning', 'approved' => 'info', 'completed' => 'success', 'cancelled' => 'danger'];
    $st = $doc['status'];
    $num = fn ($v) => str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v;
    $field = 'block w-full rounded-lg border-0 px-2 py-1.5 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $today = now(config('core.display_timezone'))->toDateString();
    $lines = collect($doc['lines']);
    $receivable = $lines->filter(fn ($l) => $l['stocked'])->map(fn ($l) => $l + ['open' => bcadd(bcsub($l['qty'], $l['qty_received'], 6), $l['qty_returned'], 6)])->filter(fn ($l) => bccomp($l['open'], '0', 6) > 0)->values();
    $billable = $lines->map(fn ($l) => $l + ['billable' => $l['stocked'] ? bcsub(bcsub($l['qty_received'], $l['qty_returned'], 6), $l['qty_billed'], 6) : bcsub($l['qty'], $l['qty_billed'], 6)])->filter(fn ($l) => bccomp($l['billable'], '0', 6) > 0)->values();
    $returnable = $lines->map(fn ($l) => $l + ['back' => $l['stocked'] ? bcsub($l['qty_received'], $l['qty_returned'], 6) : '0'])->filter(fn ($l) => bccomp($l['back'], '0', 6) > 0 || ! $l['stocked'])->values();
    $open = in_array($st, ['approved', 'completed'], true);
@endphp
<x-core::layouts.app :title="$doc['document_no']">
    @foreach (['form', 'version', 'lines', 'price'] as $e) @error($e) <x-core::alert type="error" class="mb-4">{{ $message }}</x-core::alert> @enderror @endforeach

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div class="text-sm text-slate-600">
            <x-core::status-badge :tone="$tones[$st] ?? 'neutral'">{{ __('procurement::ui.status.'.$st) }}</x-core::status-badge>
            <span class="ms-2">{{ $doc['supplier_name'] }} <span class="font-mono text-xs">{{ $doc['supplier'] }}</span></span>
            <div class="mt-1">{{ __('procurement::ui.common.date') }}: {{ $doc['order_date'] }}@if ($doc['expected_date']) · {{ __('procurement::ui.form.expected_date') }}: {{ $doc['expected_date'] }}@endif · {{ __('procurement::ui.form.deliver_to') }}: {{ $doc['warehouse'] ?? '—' }} · {{ __('procurement::ui.form.terms_days') }}: {{ $doc['payment_terms_days'] }}</div>
            @if ($doc['supplier_ref']) <div>{{ __('procurement::ui.form.supplier_ref') }}: {{ $doc['supplier_ref'] }}</div> @endif
            @if ($doc['cancel_reason']) <div class="text-red-700">{{ $doc['cancel_reason'] }}</div> @endif
            @if ($st === 'pending_approval') <div class="text-amber-700">{{ __('procurement::ui.order.waiting') }}</div> @endif
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($open)
                <a href="{{ route('procurement.orders.print', $doc['id']) }}" target="_blank" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('procurement::ui.order.print') }}</a>
            @endif
            @can('procurement.order.manage')
                @if ($st === 'draft')
                    <a href="{{ route('procurement.orders.edit', $doc['id']) }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('procurement::ui.common.edit') }}</a>
                    <form method="POST" action="{{ route('procurement.orders.submit', $doc['id']) }}">@csrf <x-core::button type="submit" size="sm">{{ __('procurement::ui.actions.submit') }}</x-core::button></form>
                @elseif ($st === 'pending_approval')
                    <form method="POST" action="{{ route('procurement.orders.withdraw', $doc['id']) }}">@csrf <x-core::button type="submit" size="sm" variant="secondary">{{ __('procurement::ui.actions.withdraw') }}</x-core::button></form>
                @endif
            @endcan
        </div>
    </div>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-3 py-2 text-start font-medium">#</th><th class="px-3 py-2 text-start font-medium">{{ __('procurement::ui.common.item') }}</th>
                <th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.common.qty') }}</th>
                @if ($open) <th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.common.received') }}</th><th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.common.returned') }}</th><th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.common.billed') }}</th> @endif
                <th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.common.unit_price') }}</th><th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.common.discount') }}</th>
                <th class="px-3 py-2 text-start font-medium">{{ __('procurement::ui.common.tax') }}</th><th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.common.amount') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($doc['lines'] as $l)
                <tr>
                    <td class="px-3 py-2 text-slate-500">{{ $l['line'] }}</td>
                    <td class="px-3 py-2"><span class="font-mono text-xs">{{ $l['item'] }}</span> {{ $l['description'] ?: $l['item_name'] }}</td>
                    <td class="px-3 py-2 text-end tabular-nums">{{ $num($l['qty']) }} {{ $l['uom'] }}</td>
                    @if ($open)
                        <td class="px-3 py-2 text-end tabular-nums">{{ $l['stocked'] ? $num($l['qty_received']) : '—' }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ $l['stocked'] ? $num($l['qty_returned']) : '—' }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ $num($l['qty_billed']) }}</td>
                    @endif
                    <td class="px-3 py-2 text-end"><x-core::money :value="$l['unit_price']" /></td>
                    <td class="px-3 py-2 text-end tabular-nums">{{ (float) $l['discount_pct'] > 0 ? $num($l['discount_pct']).'%' : '' }}</td>
                    <td class="px-3 py-2">{{ $l['tax_code'] ?? '—' }}</td>
                    <td class="px-3 py-2 text-end"><x-core::money :value="$l['net_amount']" /></td>
                </tr>
            @endforeach
        </tbody>
    </x-core::table>
    @include('procurement::docs.totals', ['totals' => $doc['totals']])

    @if ($st === 'approved')
        <div class="mt-8 grid gap-6 lg:grid-cols-2">
            @if ($receivable->isNotEmpty())
                @can('procurement.receipt.post')
                    <form method="POST" action="{{ route('procurement.orders.receive', $doc['id']) }}" class="space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        @csrf
                        <h2 class="text-base font-semibold text-slate-900">{{ __('procurement::ui.actions.receive') }}</h2>
                        <p class="text-xs text-slate-500">{{ __('procurement::ui.order.receive_help') }}</p>
                        <div class="grid grid-cols-2 gap-3">
                            <x-core::input name="receipt_date" type="date" :label="__('procurement::ui.common.date')" :value="$today" />
                            <x-core::input name="supplier_dn_ref" :label="__('procurement::ui.order.dn_ref')" maxlength="100" />
                        </div>
                        @foreach ($receivable as $i => $l)
                            <div class="grid grid-cols-12 items-center gap-2 text-sm">
                                <input type="hidden" name="lines[{{ $i }}][line]" value="{{ $l['line'] }}">
                                <span class="col-span-1 text-slate-500">{{ $l['line'] }}</span>
                                <span class="col-span-4">{{ $l['item'] }} {{ $l['item_name'] }}</span>
                                <input name="lines[{{ $i }}][qty]" value="{{ $num($l['open']) }}" inputmode="decimal" class="{{ $field }} col-span-2 text-end">
                                <span class="col-span-1 text-slate-500">{{ $l['uom'] }}</span>
                                <input name="lines[{{ $i }}][lot]" placeholder="{{ __('procurement::ui.order.lot') }}" class="{{ $field }} col-span-2">
                                <input name="lines[{{ $i }}][expiry_date]" type="date" title="{{ __('procurement::ui.order.expiry') }}" class="{{ $field }} col-span-2">
                            </div>
                        @endforeach
                        <x-core::input name="notes" :label="__('procurement::ui.form.notes')" />
                        <x-core::button type="submit">{{ __('procurement::ui.actions.receive') }}</x-core::button>
                    </form>
                @endcan
            @endif

            @if ($billable->isNotEmpty())
                @can('procurement.bill.post')
                    <form method="POST" action="{{ route('procurement.orders.bill', $doc['id']) }}" class="space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        @csrf
                        <h2 class="text-base font-semibold text-slate-900">{{ __('procurement::ui.actions.bill') }}</h2>
                        <p class="text-xs text-slate-500">{{ __('procurement::ui.order.bill_help', ['pct' => config('procurement.price_tolerance_pct')]) }}</p>
                        <div class="grid grid-cols-2 gap-3">
                            <x-core::input name="supplier_invoice_no" :label="__('procurement::ui.order.invoice_no')" :value="old('supplier_invoice_no')" required maxlength="60" />
                            <x-core::input name="bill_date" type="date" :label="__('procurement::ui.common.date')" :value="$today" />
                            <x-core::input name="due_date" type="date" :label="__('procurement::ui.order.due')" :hint="__('procurement::ui.order.due_hint')" />
                        </div>
                        @foreach ($billable as $i => $l)
                            <div class="grid grid-cols-12 items-center gap-2 text-sm">
                                <input type="hidden" name="lines[{{ $i }}][line]" value="{{ $l['line'] }}">
                                <span class="col-span-1 text-slate-500">{{ $l['line'] }}</span>
                                <span class="col-span-4">{{ $l['item'] }} {{ $l['item_name'] }}</span>
                                <input name="lines[{{ $i }}][qty]" value="{{ $num($l['billable']) }}" inputmode="decimal" class="{{ $field }} col-span-2 text-end">
                                <span class="col-span-1 text-slate-500">{{ $l['uom'] }}</span>
                                <input name="lines[{{ $i }}][unit_price]" inputmode="decimal" placeholder="{{ __('procurement::ui.order.as_ordered') }}" class="{{ $field }} col-span-4 text-end">
                            </div>
                        @endforeach
                        @can('procurement.bill.override')
                            <x-core::input name="override_reason" :label="__('procurement::ui.order.override_reason')" :hint="__('procurement::ui.order.override_hint')" maxlength="255" />
                        @endcan
                        <x-core::input name="notes" :label="__('procurement::ui.form.notes')" />
                        <x-core::button type="submit">{{ __('procurement::ui.actions.bill') }}</x-core::button>
                    </form>
                @endcan
            @endif
        </div>
    @endif

    @if ($open && $returnable->isNotEmpty())
        @can('procurement.return.post')
            <form method="POST" action="{{ route('procurement.orders.return', $doc['id']) }}" class="mt-6 max-w-3xl space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                @csrf
                <h2 class="text-base font-semibold text-slate-900">{{ __('procurement::ui.actions.return') }}</h2>
                <p class="text-xs text-slate-500">{{ __('procurement::ui.order.return_help') }}</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="sm:col-span-2"><x-core::input name="reason" :label="__('procurement::ui.common.reason')" :value="old('reason')" required maxlength="255" /></div>
                    <x-core::input name="return_date" type="date" :label="__('procurement::ui.common.date')" :value="$today" />
                    <div class="sm:col-span-3">
                        <label for="bill" class="mb-1 block text-sm font-medium text-slate-700">{{ __('procurement::ui.order.credit_bill') }}</label>
                        <select id="bill" name="bill" class="{{ $field }}">
                            <option value="">{{ __('procurement::ui.order.not_billed') }}</option>
                            @foreach ($doc['bills'] as $b) <option value="{{ $b['supplier_invoice_no'] }}" @selected(old('bill') === $b['supplier_invoice_no'])>{{ $b['supplier_invoice_no'] }} — {{ $b['date'] }}</option> @endforeach
                        </select>
                    </div>
                </div>
                @foreach ($returnable as $i => $l)
                    <div class="flex items-center gap-2 text-sm">
                        <input type="hidden" name="lines[{{ $i }}][line]" value="{{ $l['line'] }}">
                        <span class="w-6 text-slate-500">{{ $l['line'] }}</span>
                        <span class="flex-1">{{ $l['item'] }} {{ $l['item_name'] }} @if ($l['stocked']) <span class="text-xs text-slate-500">({{ __('procurement::ui.order.can_return', ['qty' => $num($l['back'])]) }})</span> @endif</span>
                        <input name="lines[{{ $i }}][qty]" inputmode="decimal" placeholder="0" class="{{ $field }} !w-24 text-end">
                        <span class="w-10 text-slate-500">{{ $l['uom'] }}</span>
                    </div>
                @endforeach
                <x-core::button type="submit" variant="secondary">{{ __('procurement::ui.actions.return') }}</x-core::button>
            </form>
        @endcan
    @endif

    @if ($doc['receipts'] || $doc['bills'] || $doc['returns'])
        <div class="mt-8 grid gap-6 text-sm sm:grid-cols-3">
            <div>
                <h3 class="font-semibold text-slate-900">{{ __('procurement::ui.titles.receipts') }}</h3>
                <ul class="mt-2 space-y-1">@forelse ($doc['receipts'] as $r) <li><a class="font-mono text-xs text-indigo-600 hover:underline" href="{{ route('procurement.receipts.show', $r['id']) }}">{{ $r['document_no'] }}</a> · {{ $r['date'] }} · {{ __('procurement::ui.status.'.$r['status']) }}</li> @empty <li class="text-slate-500">—</li> @endforelse</ul>
            </div>
            <div>
                <h3 class="font-semibold text-slate-900">{{ __('procurement::ui.titles.bills') }}</h3>
                <ul class="mt-2 space-y-1">@forelse ($doc['bills'] as $b) <li><a class="font-mono text-xs text-indigo-600 hover:underline" href="{{ route('procurement.bills.show', $b['id']) }}">{{ $b['supplier_invoice_no'] }}</a> · {{ $b['date'] }} · <x-core::money :value="$b['gross_total']" /></li> @empty <li class="text-slate-500">—</li> @endforelse</ul>
            </div>
            <div>
                <h3 class="font-semibold text-slate-900">{{ __('procurement::ui.titles.returns') }}</h3>
                <ul class="mt-2 space-y-1">@forelse ($doc['returns'] as $r) <li><a class="font-mono text-xs text-indigo-600 hover:underline" href="{{ route('procurement.returns.show', $r['id']) }}">{{ $r['document_no'] }}</a> · {{ $r['date'] }}</li> @empty <li class="text-slate-500">—</li> @endforelse</ul>
            </div>
        </div>
    @endif

    @if (in_array($st, ['draft', 'pending_approval', 'approved'], true))
        @can('procurement.order.manage')
            <form method="POST" action="{{ route('procurement.orders.cancel', $doc['id']) }}" class="mt-8 flex flex-wrap items-end gap-2 text-sm">
                @csrf
                <div>
                    <label for="reason2" class="mb-1 block text-xs font-medium text-slate-600">{{ __('procurement::ui.common.reason') }}</label>
                    <input id="reason2" name="reason" required maxlength="255" class="{{ $field }} !w-72">
                </div>
                <x-core::button type="submit" variant="danger" size="sm">{{ __('procurement::ui.actions.cancel_order') }}</x-core::button>
            </form>
        @endcan
    @endif
</x-core::layouts.app>
