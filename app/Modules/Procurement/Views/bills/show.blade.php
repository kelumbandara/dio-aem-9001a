@php $num = fn ($v) => str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v; @endphp
<x-core::layouts.app :title="$doc['document_no']">
    <div class="mb-4 text-sm text-slate-600">
        <x-core::status-badge tone="success">{{ __('procurement::ui.status.posted') }}</x-core::status-badge>
        <span class="ms-2">{{ $doc['supplier_name'] }} <span class="font-mono text-xs">{{ $doc['supplier'] }}</span></span>
        <div class="mt-1">{{ __('procurement::ui.order.invoice_no') }}: <strong>{{ $doc['supplier_invoice_no'] }}</strong> · {{ __('procurement::ui.common.date') }}: {{ $doc['bill_date'] }} · {{ __('procurement::ui.order.due') }}: {{ $doc['due_date'] }} · <a class="text-indigo-600 hover:underline" href="{{ route('procurement.orders.show', $doc['order_id']) }}">{{ $doc['order'] }}</a></div>
        @if ((float) $doc['variance_total'] != 0) <div class="{{ (float) $doc['variance_total'] > 0 ? 'text-amber-700' : 'text-emerald-700' }}">{{ __('procurement::ui.bill.variance') }}: <x-core::money :value="$doc['variance_total']" /></div> @endif
        @if ($doc['override_reason']) <div class="text-amber-700">{{ __('procurement::ui.bill.overridden', ['reason' => $doc['override_reason']]) }}</div> @endif
        @if ($doc['notes']) <div>{{ $doc['notes'] }}</div> @endif
    </div>
    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-3 py-2 text-start font-medium">#</th><th class="px-3 py-2 text-start font-medium">{{ __('procurement::ui.common.item') }}</th>
                <th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.common.qty') }}</th><th class="px-3 py-2 text-end font-medium">{{ $doc['prices_include_vat'] ? __('procurement::ui.common.unit_price_incl') : __('procurement::ui.common.unit_price') }}</th>
                <th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.bill.goods_received') }}</th><th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.bill.variance') }}</th>
                <th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.common.amount') }}</th><th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.common.vat') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($doc['lines'] as $l)
                <tr>
                    <td class="px-3 py-2 text-slate-500">{{ $l['line'] }}</td><td class="px-3 py-2"><span class="font-mono text-xs">{{ $l['item'] }}</span> {{ $l['item_name'] }}</td>
                    <td class="px-3 py-2 text-end tabular-nums">{{ $num($l['qty']) }} {{ $l['uom'] }}</td><td class="px-3 py-2 text-end"><x-core::money :value="$l['unit_price']" /></td>
                    <td class="px-3 py-2 text-end"><x-core::money :value="$l['grni_amount']" /></td><td class="px-3 py-2 text-end"><x-core::money :value="$l['variance_amount']" /></td>
                    <td class="px-3 py-2 text-end"><x-core::money :value="$l['net_amount']" /></td><td class="px-3 py-2 text-end"><x-core::money :value="$l['vat_amount']" /></td>
                </tr>
            @endforeach
        </tbody>
    </x-core::table>
    @include('procurement::docs.totals', ['totals' => $doc['totals']])
    @if ($doc['returns'])
        <div class="mt-6 text-sm">
            <h3 class="font-semibold text-slate-900">{{ __('procurement::ui.titles.returns') }}</h3>
            <ul class="mt-2 space-y-1">@foreach ($doc['returns'] as $r) <li><a class="font-mono text-xs text-indigo-600 hover:underline" href="{{ route('procurement.returns.show', $r['id']) }}">{{ $r['document_no'] }}</a> · <x-core::money :value="$r['gross_total']" /></li> @endforeach</ul>
        </div>
    @endif
</x-core::layouts.app>
