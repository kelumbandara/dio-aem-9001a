@php
    $num = fn ($v) => rtrim(rtrim($v, '0'), '.') ?: '0';
    $place = fn ($p) => $p ? $p['warehouse'].($p['location'] ? ' / '.$p['location'] : '') : '—';
@endphp
<x-core::layouts.app :title="$doc['document_no']">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2 text-sm text-slate-600">
            <x-core::status-badge :tone="$doc['status'] === 'posted' ? 'success' : 'neutral'">{{ __("inventory::ui.documents.status.{$doc['status']}") }}</x-core::status-badge>
            <span>{{ __("inventory::ui.documents.types.{$doc['type']}") }} · {{ $doc['posting_date'] }} · {{ __("inventory::ui.documents.reasons.{$doc['reason']}") }}</span>
            @if ($doc['reverses']) <span>· {{ __('inventory::ui.documents.reverses') }} {{ $doc['reverses'] }}</span> @endif
            @if ($doc['reversed_by']) <span>· {{ __('inventory::ui.documents.reversed_by') }} {{ $doc['reversed_by'] }}</span> @endif
        </div>
        <a href="{{ route('inventory.documents.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('inventory::ui.common.back') }}</a>
    </div>

    <dl class="mb-6 grid max-w-3xl grid-cols-2 gap-4 rounded-xl border border-slate-200 bg-white p-5 text-sm shadow-sm sm:grid-cols-4">
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('inventory::ui.stock.value') }}</dt><dd class="mt-0.5 font-semibold"><x-core::money :value="$doc['total_value']" /></dd></div>
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('inventory::ui.documents.reference') }}</dt><dd class="mt-0.5">{{ $doc['reference'] ?: '—' }}</dd></div>
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('inventory::ui.documents.posted_by') }}</dt><dd class="mt-0.5">{{ $doc['created_by'] ?: '—' }}</dd></div>
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('inventory::ui.documents.posted_at') }}</dt><dd class="mt-0.5"><x-core::datetime :value="Illuminate\Support\Carbon::parse($doc['created_at'])" /></dd></div>
        @if ($doc['notes']) <div class="col-span-full"><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('inventory::ui.documents.notes') }}</dt><dd class="mt-0.5 whitespace-pre-line">{{ $doc['notes'] }}</dd></div> @endif
    </dl>

    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">#</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.item') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('inventory::ui.stock.qty') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.documents.from') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.documents.to') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.lot') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('inventory::ui.stock.value') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($doc['lines'] as $l)
                <tr>
                    <td class="px-4 py-3">{{ $l['line_no'] }}</td>
                    <td class="px-4 py-3"><a href="{{ route('inventory.stock.item', $l['item_code']) }}" class="font-mono text-xs text-indigo-600 hover:underline">{{ $l['item_code'] }}</a> {{ $l['item_name'] }}@if ($l['note'])<div class="text-xs text-slate-500">{{ $l['note'] }}</div>@endif</td>
                    <td class="px-4 py-3 text-end">{{ $num($l['qty']) }} {{ $l['uom'] }}@if ($l['uom'] !== $l['base_uom'])<div class="text-xs text-slate-500">= {{ $num($l['base_qty']) }} {{ $l['base_uom'] }}</div>@endif</td>
                    <td class="px-4 py-3">{{ $place($l['from']) }}</td>
                    <td class="px-4 py-3">{{ $place($l['to']) }}</td>
                    <td class="px-4 py-3">{{ $l['lot'] ?? '—' }}@if ($l['expiry_date'])<div class="text-xs text-slate-500">{{ $l['expiry_date'] }}</div>@endif</td>
                    <td class="px-4 py-3 text-end"><x-core::money :value="$l['value']" /></td>
                </tr>
            @endforeach
        </tbody>
    </x-core::table>

    @can('reverse', $model)
        @if ($doc['status'] === 'posted' && ! $doc['reverses'])
            <form method="POST" action="{{ route('inventory.documents.reverse', $model) }}" class="mt-6 max-w-xl space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-sm" x-on:submit="if (! confirm(@js(__('inventory::ui.documents.reverse_confirm')))) $event.preventDefault()">
                @csrf
                <h2 class="text-base font-semibold text-slate-900">{{ __('inventory::ui.documents.reverse') }}</h2>
                <p class="text-sm text-slate-600">{{ __('inventory::ui.documents.reverse_hint') }}</p>
                <x-core::input name="note" :label="__('inventory::ui.documents.reverse_note')" maxlength="500" />
                <x-core::button type="submit" variant="danger">{{ __('inventory::ui.documents.reverse') }}</x-core::button>
            </form>
        @endif
    @endcan
</x-core::layouts.app>
