@php $num = fn ($v) => rtrim(rtrim($v, '0'), '.') ?: '0'; @endphp
<x-core::table>
    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
        <tr>
            <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.date') }}</th>
            <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.documents.document') }}</th>
            @if ($showItem) <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.item') }}</th> @endif
            <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.warehouse') }}</th>
            <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.lot') }}</th>
            <th class="px-4 py-3 text-end font-medium">{{ __('inventory::ui.stock.qty') }}</th>
            <th class="px-4 py-3 text-end font-medium">{{ __('inventory::ui.stock.value') }}</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-slate-100">
        @forelse ($rows as $r)
            <tr>
                <td class="px-4 py-3">{{ $r['posting_date'] }}</td>
                <td class="px-4 py-3"><a href="{{ route('inventory.documents.show', $r['document_id']) }}" class="font-mono text-xs text-indigo-600 hover:underline">{{ $r['document_no'] }}</a> <span class="text-xs text-slate-500">{{ __("inventory::ui.documents.types.{$r['type']}") }}</span></td>
                @if ($showItem) <td class="px-4 py-3"><span class="font-mono text-xs">{{ $r['item_code'] }}</span> {{ $r['item_name'] }}</td> @endif
                <td class="px-4 py-3">{{ $r['warehouse'] }}@if ($r['location']) / {{ $r['location'] }}@endif</td>
                <td class="px-4 py-3">{{ $r['lot'] ?? '—' }}</td>
                <td class="px-4 py-3 text-end {{ str_starts_with($r['qty'], '-') ? 'text-red-700' : 'text-emerald-700' }}">{{ $num($r['qty']) }}</td>
                <td class="px-4 py-3 text-end"><x-core::money :value="$r['value']" /></td>
            </tr>
        @empty
            <tr><td colspan="{{ $showItem ? 7 : 6 }}" class="px-4 py-6 text-center text-slate-500">{{ __('inventory::ui.stock.no_movements') }}</td></tr>
        @endforelse
    </tbody>
</x-core::table>
<div class="mt-4">{{ $rows->links() }}</div>
