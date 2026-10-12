@php $num = fn ($v) => str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v; @endphp
<x-core::layouts.app :title="$doc['document_no']">
    <div class="mb-4 text-sm text-slate-600">
        <x-core::status-badge tone="success">{{ __('procurement::ui.status.posted') }}</x-core::status-badge>
        <span class="ms-2">{{ $doc['supplier_name'] }} <span class="font-mono text-xs">{{ $doc['supplier'] }}</span></span>
        <div class="mt-1">{{ __('procurement::ui.common.date') }}: {{ $doc['return_date'] }} · <a class="text-indigo-600 hover:underline" href="{{ route('procurement.orders.show', $doc['order_id']) }}">{{ $doc['order'] }}</a>
            @if ($doc['bill']) · {{ __('procurement::ui.order.credit_bill') }}: <a class="text-indigo-600 hover:underline" href="{{ route('procurement.bills.show', $doc['bill_id']) }}">{{ $doc['bill'] }}</a> @endif</div>
        <div>{{ __('procurement::ui.common.reason') }}: {{ $doc['reason'] }}</div>
    </div>
    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-3 py-2 text-start font-medium">{{ __('procurement::ui.order.line') }}</th><th class="px-3 py-2 text-start font-medium">{{ __('procurement::ui.common.item') }}</th>
                <th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.common.qty') }}</th><th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.common.amount') }}</th><th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.common.vat') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($doc['lines'] as $l)
                <tr>
                    <td class="px-3 py-2">{{ $l['order_line'] }}</td><td class="px-3 py-2"><span class="font-mono text-xs">{{ $l['item'] }}</span> {{ $l['item_name'] }}</td>
                    <td class="px-3 py-2 text-end tabular-nums">{{ $num($l['qty']) }} {{ $l['uom'] }}</td>
                    <td class="px-3 py-2 text-end"><x-core::money :value="$l['net_amount']" /></td><td class="px-3 py-2 text-end"><x-core::money :value="$l['vat_amount']" /></td>
                </tr>
            @endforeach
        </tbody>
    </x-core::table>
    @if ($doc['bill']) @include('procurement::docs.totals', ['totals' => $doc['totals']]) @else <p class="mt-4 text-sm text-slate-500">{{ __('procurement::ui.order.no_debit_note') }}</p> @endif
</x-core::layouts.app>
