@php
    $num = fn ($v) => $v === null ? '' : (str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v);
    $progress = $progress ?? false;
    $taxes = $taxes ?? [];
@endphp
<x-core::table>
    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
        <tr>
            <th class="px-3 py-2 text-start font-medium">#</th>
            <th class="px-3 py-2 text-start font-medium">{{ __('sales::ui.common.item') }}</th>
            <th class="px-3 py-2 text-end font-medium">{{ __('sales::ui.common.qty') }}</th>
            @if ($progress)
                <th class="px-3 py-2 text-end font-medium">{{ __('sales::ui.common.delivered') }}</th>
                <th class="px-3 py-2 text-end font-medium">{{ __('sales::ui.common.invoiced') }}</th>
            @endif
            <th class="px-3 py-2 text-end font-medium">{{ __('sales::ui.common.unit_price') }}</th>
            <th class="px-3 py-2 text-end font-medium">{{ __('sales::ui.common.discount') }}</th>
            <th class="px-3 py-2 text-start font-medium">{{ __('sales::ui.common.tax') }}</th>
            <th class="px-3 py-2 text-end font-medium">{{ __('sales::ui.common.amount') }}</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
        @foreach ($lines as $l)
            <tr>
                <td class="px-3 py-2 text-slate-500">{{ $l['line'] }}</td>
                <td class="px-3 py-2"><span class="font-mono text-xs">{{ $l['item'] }}</span> {{ $l['description'] ?: $l['item_name'] }}</td>
                <td class="px-3 py-2 text-end tabular-nums">{{ $num($l['qty']) }} {{ $l['uom'] }}</td>
                @if ($progress)
                    <td class="px-3 py-2 text-end tabular-nums">{{ $l['stocked'] ? $num($l['qty_delivered']) : '—' }}</td>
                    <td class="px-3 py-2 text-end tabular-nums">{{ $num($l['qty_invoiced']) }}</td>
                @endif
                <td class="px-3 py-2 text-end"><x-core::money :value="$l['unit_price']" /></td>
                <td class="px-3 py-2 text-end tabular-nums">{{ (float) $l['discount_pct'] > 0 ? $num($l['discount_pct']).'%' : '' }}</td>
                <td class="px-3 py-2">{{ $l['tax_code'] ?? '—' }}</td>
                <td class="px-3 py-2 text-end"><x-core::money :value="$l['net_amount']" /></td>
            </tr>
        @endforeach
    </tbody>
</x-core::table>

@include('sales::docs.totals', ['totals' => $totals, 'taxes' => $taxes])
