@php $num = fn ($v) => rtrim(rtrim($v, '0'), '.') ?: '0'; @endphp
<x-core::layouts.app :title="$item->code.' — '.$item->name">
    <div class="mb-4 flex items-center justify-between gap-3">
        <a href="{{ route('inventory.stock.index') }}" class="text-sm text-indigo-600 hover:underline">← {{ __('inventory::ui.common.back') }}</a>
        <a href="{{ route('inventory.ledger.index', ['item' => $item->code]) }}" class="text-sm text-indigo-600 hover:underline">{{ __('inventory::ui.stock.full_ledger') }}</a>
    </div>

    <dl class="mb-6 grid max-w-3xl grid-cols-2 gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-4">
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('inventory::ui.stock.on_hand') }}</dt><dd class="mt-0.5 text-lg font-semibold">{{ $num($level->onHand) }} {{ $item->baseUomCode }}</dd></div>
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('inventory::ui.stock.reserved') }}</dt><dd class="mt-0.5 text-lg font-semibold">{{ $num($level->reserved) }}</dd></div>
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('inventory::ui.stock.available') }}</dt><dd class="mt-0.5 text-lg font-semibold">{{ $num($level->available) }}</dd></div>
        <div><dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('inventory::ui.stock.avg_cost') }}</dt><dd class="mt-0.5 text-lg font-semibold"><x-core::money :value="$avg" /></dd></div>
    </dl>

    <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('inventory::ui.stock.where') }}</h2>
    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.warehouse') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.location') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.lot') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.lots.expiry') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('inventory::ui.stock.on_hand') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($balances as $b)
                <tr>
                    <td class="px-4 py-3">{{ $b['warehouse'] }} — {{ $b['warehouse_name'] }}</td>
                    <td class="px-4 py-3">{{ $b['location'] ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $b['lot'] ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $b['expiry_date'] ?? '—' }}</td>
                    <td class="px-4 py-3 text-end">{{ $num($b['on_hand']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">{{ __('inventory::ui.stock.none_here') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>

    <h2 class="mb-2 mt-8 text-base font-semibold text-slate-900">{{ __('inventory::ui.stock.recent') }}</h2>
    @include('inventory::stock.partials.ledger-table', ['rows' => $ledger, 'showItem' => false])
</x-core::layouts.app>
