@php
    $num = fn ($v) => str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v;
    $field = 'block w-full rounded-lg border-0 px-2 py-1.5 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $today = now(config('core.display_timezone'))->toDateString();
    $creditable = collect($doc['lines'])->map(fn ($l) => $l + ['left' => bcsub($l['qty'], $l['qty_credited'], 6)])->filter(fn ($l) => bccomp($l['left'], '0', 6) > 0)->values();
    $hasStock = $creditable->contains(fn ($l) => $l['stocked']);
@endphp
<x-core::layouts.app :title="$doc['document_no']">
    @foreach (['form', 'lines', 'restock'] as $e) @error($e) <x-core::alert type="error" class="mb-4">{{ $message }}</x-core::alert> @enderror @endforeach
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3 text-sm text-slate-600">
        <div>
            <x-core::status-badge tone="success">{{ __('sales::ui.status.posted') }}</x-core::status-badge>
            <span class="ms-2">{{ $doc['customer_name'] }} <span class="font-mono text-xs">{{ $doc['customer'] }}</span></span>
            <div class="mt-1">{{ __('sales::ui.common.date') }}: {{ $doc['invoice_date'] }} · {{ __('sales::ui.invoice.due') }}: {{ $doc['due_date'] }} · <a class="text-indigo-600 hover:underline" href="{{ route('sales.orders.show', $doc['order_id']) }}">{{ $doc['order'] }}</a></div>
            @if ($doc['customer_ref']) <div>{{ __('sales::ui.form.customer_ref') }}: {{ $doc['customer_ref'] }}</div> @endif
        </div>
        <a href="{{ route('sales.invoices.print', $doc['id']) }}" target="_blank" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">{{ __('sales::ui.invoice.print') }}</a>
    </div>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-3 py-2 text-start font-medium">#</th><th class="px-3 py-2 text-start font-medium">{{ __('sales::ui.common.item') }}</th>
                <th class="px-3 py-2 text-end font-medium">{{ __('sales::ui.common.qty') }}</th><th class="px-3 py-2 text-end font-medium">{{ __('sales::ui.common.unit_price') }}</th>
                <th class="px-3 py-2 text-end font-medium">{{ __('sales::ui.common.discount') }}</th><th class="px-3 py-2 text-start font-medium">{{ __('sales::ui.common.tax') }}</th>
                <th class="px-3 py-2 text-end font-medium">{{ __('sales::ui.common.amount') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($doc['lines'] as $l)
                <tr>
                    <td class="px-3 py-2 text-slate-500">{{ $l['line'] }}</td>
                    <td class="px-3 py-2"><span class="font-mono text-xs">{{ $l['item'] }}</span> {{ $l['description'] ?: $l['item_name'] }}</td>
                    <td class="px-3 py-2 text-end tabular-nums">{{ $num($l['qty']) }} {{ $l['uom'] }}@if ((float) $l['qty_credited'] > 0)<div class="text-xs text-amber-700">{{ __('sales::ui.invoice.credited_qty', ['qty' => $num($l['qty_credited'])]) }}</div>@endif</td>
                    <td class="px-3 py-2 text-end"><x-core::money :value="$l['unit_price']" /></td>
                    <td class="px-3 py-2 text-end tabular-nums">{{ (float) $l['discount_pct'] > 0 ? $num($l['discount_pct']).'%' : '' }}</td>
                    <td class="px-3 py-2">{{ $l['tax_code'] ?? '—' }} @if ((float) $l['vat_rate'] > 0)<span class="text-xs text-slate-500">{{ $num($l['vat_rate']) }}%</span>@endif @unless ($l['levy_liable'])<span class="text-xs text-slate-500">({{ __('sales::ui.invoice.no_levy') }})</span>@endunless</td>
                    <td class="px-3 py-2 text-end"><x-core::money :value="$l['net_amount']" /></td>
                </tr>
            @endforeach
        </tbody>
    </x-core::table>

    @include('sales::docs.totals', ['totals' => $doc['totals'], 'taxes' => $doc['taxes']])

    @if ($doc['credit_notes'])
        <div class="mt-6 text-sm">
            <h3 class="font-semibold text-slate-900">{{ __('sales::ui.titles.credit-notes') }}</h3>
            <ul class="mt-2 space-y-1">@foreach ($doc['credit_notes'] as $c) <li><a class="font-mono text-xs text-indigo-600 hover:underline" href="{{ route('sales.credit-notes.show', $c['id']) }}">{{ $c['document_no'] }}</a> · {{ $c['date'] }} · <x-core::money :value="$c['gross_total']" /></li> @endforeach</ul>
        </div>
    @endif

    @if ($creditable->isNotEmpty())
        @can('sales.credit_note.post')
            <form method="POST" action="{{ route('sales.invoices.credit-notes.store', $doc['id']) }}" class="mt-8 max-w-3xl space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                @csrf
                <h2 class="text-base font-semibold text-slate-900">{{ __('sales::ui.actions.credit_note') }}</h2>
                <p class="text-xs text-slate-500">{{ __('sales::ui.invoice.credit_help') }}</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="sm:col-span-2"><x-core::input name="reason" :label="__('sales::ui.common.reason')" :value="old('reason')" required maxlength="255" /></div>
                    <x-core::input name="note_date" type="date" :label="__('sales::ui.common.date')" :value="old('note_date', $today)" />
                </div>
                @foreach ($creditable as $i => $l)
                    <div class="flex items-center gap-2 text-sm">
                        <input type="hidden" name="lines[{{ $i }}][line]" value="{{ $l['line'] }}">
                        <span class="w-6 text-slate-500">{{ $l['line'] }}</span>
                        <span class="flex-1">{{ $l['item'] }} {{ $l['item_name'] }} <span class="text-xs text-slate-500">({{ __('sales::ui.invoice.can_credit', ['qty' => $num($l['left'])]) }})</span></span>
                        <input name="lines[{{ $i }}][qty]" value="{{ old("lines.{$i}.qty") }}" inputmode="decimal" placeholder="0" class="{{ $field }} !w-24 text-end">
                        <span class="w-10 text-slate-500">{{ $l['uom'] }}</span>
                    </div>
                @endforeach
                @if ($hasStock)
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <label class="flex items-center gap-2"><input type="checkbox" name="restock" value="1" class="rounded border-slate-300 text-indigo-600" @checked(old('restock'))> {{ __('sales::ui.invoice.restock') }}</label>
                        <select name="warehouse" class="{{ $field }} !w-56">
                            <option value="">{{ __('sales::ui.invoice.same_warehouse') }}</option>
                            @foreach ($warehouses as $id => $label) <option value="{{ explode(' ', $label)[0] }}">{{ $label }}</option> @endforeach
                        </select>
                    </div>
                    <p class="text-xs text-slate-500">{{ __('sales::ui.invoice.restock_help') }}</p>
                @endif
                <x-core::button type="submit">{{ __('sales::ui.actions.credit_note') }}</x-core::button>
            </form>
        @endcan
    @endif
</x-core::layouts.app>
