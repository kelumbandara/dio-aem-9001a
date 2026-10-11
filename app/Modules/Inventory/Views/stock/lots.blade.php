@php $num = fn ($v) => rtrim(rtrim($v, '0'), '.') ?: '0'; @endphp
<x-core::layouts.app :title="__('inventory::ui.lots.title')">
    <nav class="mb-4 flex flex-wrap gap-2 text-sm" aria-label="{{ __('inventory::ui.lots.title') }}">
        @foreach ([['all', null], ['expired', 0], ['d30', 30], ['d90', 90]] as [$label, $value])
            <a href="{{ route('inventory.lots.index', $value === null ? [] : ['days' => $value]) }}" class="rounded-lg px-3 py-1.5 {{ $days === $value ? 'bg-indigo-600 text-white' : 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50' }}">{{ __("inventory::ui.lots.filter_{$label}") }}</a>
        @endforeach
    </nav>
    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.item') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.lot') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.lots.received') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.lots.expiry') }}</th>
                <th class="px-4 py-3 text-end font-medium">{{ __('inventory::ui.stock.on_hand') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $r)
                <tr>
                    <td class="px-4 py-3"><span class="font-mono text-xs">{{ $r['item_code'] }}</span> {{ $r['item_name'] }}</td>
                    <td class="px-4 py-3">{{ $r['lot'] }}</td>
                    <td class="px-4 py-3">{{ $r['received_on'] }}</td>
                    <td class="px-4 py-3">
                        {{ $r['expiry_date'] ?? '—' }}
                        @if ($r['expired']) <x-core::status-badge tone="danger">{{ __('inventory::ui.lots.expired') }}</x-core::status-badge>
                        @elseif ($r['days_left'] !== null && $r['days_left'] <= 30) <x-core::status-badge tone="warning">{{ __('inventory::ui.lots.days_left', ['count' => $r['days_left']]) }}</x-core::status-badge> @endif
                    </td>
                    <td class="px-4 py-3 text-end">{{ $num($r['on_hand']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">{{ __('inventory::ui.lots.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
    <div class="mt-4">{{ $rows->links() }}</div>
</x-core::layouts.app>
