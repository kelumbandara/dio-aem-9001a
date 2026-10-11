@php $num = fn ($v) => str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v; @endphp
<x-core::layouts.app :title="$doc['document_no']">
    <div class="mb-4 text-sm text-slate-600">
        <x-core::status-badge tone="success">{{ __('sales::ui.status.posted') }}</x-core::status-badge>
        <span class="ms-2">{{ $doc['customer_name'] }} <span class="font-mono text-xs">{{ $doc['customer'] }}</span></span>
        <div class="mt-1">{{ __('sales::ui.common.date') }}: {{ $doc['note_date'] }} · {{ __('sales::ui.titles.invoices') }}: <a class="text-indigo-600 hover:underline" href="{{ route('sales.invoices.show', $doc['invoice_id']) }}">{{ $doc['invoice'] }}</a></div>
        <div>{{ __('sales::ui.common.reason') }}: {{ $doc['reason'] }}@if ($doc['restock']) · {{ __('sales::ui.invoice.restocked') }}@endif</div>
    </div>
    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-3 py-2 text-start font-medium">{{ __('sales::ui.invoice.invoice_line') }}</th><th class="px-3 py-2 text-start font-medium">{{ __('sales::ui.common.item') }}</th>
                <th class="px-3 py-2 text-end font-medium">{{ __('sales::ui.common.qty') }}</th><th class="px-3 py-2 text-end font-medium">{{ __('sales::ui.common.amount') }}</th><th class="px-3 py-2 text-end font-medium">{{ __('sales::ui.common.vat') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($doc['lines'] as $l)
                <tr>
                    <td class="px-3 py-2">{{ $l['invoice_line'] }}</td><td class="px-3 py-2"><span class="font-mono text-xs">{{ $l['item'] }}</span> {{ $l['item_name'] }}</td>
                    <td class="px-3 py-2 text-end tabular-nums">{{ $num($l['qty']) }} {{ $l['uom'] }}</td>
                    <td class="px-3 py-2 text-end"><x-core::money :value="$l['net_amount']" /></td><td class="px-3 py-2 text-end"><x-core::money :value="$l['vat_amount']" /></td>
                </tr>
            @endforeach
        </tbody>
    </x-core::table>
    @include('sales::docs.totals', ['totals' => $doc['totals'], 'taxes' => $doc['taxes']])
</x-core::layouts.app>
