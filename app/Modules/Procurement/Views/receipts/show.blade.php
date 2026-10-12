@php $num = fn ($v) => str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v; @endphp
<x-core::layouts.app :title="$doc['document_no']">
    @error('form') <x-core::alert type="error" class="mb-4">{{ $message }}</x-core::alert> @enderror
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3 text-sm text-slate-600">
        <div>
            <x-core::status-badge :tone="$doc['status'] === 'posted' ? 'success' : 'warning'">{{ __('procurement::ui.status.'.$doc['status']) }}</x-core::status-badge>
            <span class="ms-2">{{ $doc['supplier_name'] }} <span class="font-mono text-xs">{{ $doc['supplier'] }}</span></span>
            <div class="mt-1">{{ __('procurement::ui.common.date') }}: {{ $doc['receipt_date'] }} · {{ __('procurement::ui.common.warehouse') }}: {{ $doc['warehouse'] ?? '—' }} · <a class="text-indigo-600 hover:underline" href="{{ route('procurement.orders.show', $doc['order_id']) }}">{{ $doc['order'] }}</a></div>
            @if ($doc['supplier_dn_ref']) <div>{{ __('procurement::ui.order.dn_ref') }}: {{ $doc['supplier_dn_ref'] }}</div> @endif
            @if ($doc['notes']) <div>{{ $doc['notes'] }}</div> @endif
        </div>
        @if ($doc['status'] === 'posted')
            @can('procurement.reverse')
                <form method="POST" action="{{ route('procurement.receipts.reverse', $doc['id']) }}" class="flex items-end gap-2">
                    @csrf
                    <input name="note" maxlength="500" placeholder="{{ __('procurement::ui.common.reason') }}" class="block rounded-lg border-0 px-3 py-1.5 text-sm shadow-sm ring-1 ring-inset ring-slate-300">
                    <x-core::button type="submit" variant="secondary" size="sm">{{ __('procurement::ui.actions.reverse') }}</x-core::button>
                </form>
            @endcan
        @endif
    </div>
    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-3 py-2 text-start font-medium">{{ __('procurement::ui.order.line') }}</th><th class="px-3 py-2 text-start font-medium">{{ __('procurement::ui.common.item') }}</th>
                <th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.common.qty') }}</th><th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.common.unit_cost') }}</th>
                <th class="px-3 py-2 text-end font-medium">{{ __('procurement::ui.common.value') }}</th><th class="px-3 py-2 text-start font-medium">{{ __('procurement::ui.order.lot') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($doc['lines'] as $l)
                <tr>
                    <td class="px-3 py-2">{{ $l['order_line'] }}</td><td class="px-3 py-2"><span class="font-mono text-xs">{{ $l['item'] }}</span> {{ $l['item_name'] }}</td>
                    <td class="px-3 py-2 text-end tabular-nums">{{ $num($l['qty']) }} {{ $l['uom'] }}</td><td class="px-3 py-2 text-end"><x-core::money :value="$l['unit_cost']" /></td>
                    <td class="px-3 py-2 text-end"><x-core::money :value="$l['value']" /></td><td class="px-3 py-2">{{ $l['lot'] }} {{ $l['expiry_date'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </x-core::table>
</x-core::layouts.app>
