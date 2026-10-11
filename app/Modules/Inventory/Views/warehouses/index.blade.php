<x-core::layouts.app :title="__('inventory::ui.warehouses.title')">
    <div class="mb-4 flex justify-end">
        @can('create', App\Modules\Inventory\Models\Warehouse::class)
            <a href="{{ route('inventory.warehouses.create') }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">{{ __('inventory::ui.warehouses.new') }}</a>
        @endcan
    </div>
    <x-core::table>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.code') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.name') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.warehouses.branch') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.warehouses.locations') }}</th>
                <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($warehouses as $w)
                <tr>
                    <td class="px-4 py-3 font-mono text-xs"><a href="{{ route('inventory.warehouses.show', $w) }}" class="text-indigo-600 hover:underline">{{ $w->code }}</a></td>
                    <td class="px-4 py-3">{{ $w->name }}</td>
                    <td class="px-4 py-3">{{ $branches[$w->branch_id] ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $w->uses_locations ? $w->locations->where('is_active', true)->count() : __('inventory::ui.warehouses.not_used') }}</td>
                    <td class="px-4 py-3"><x-core::status-badge :tone="$w->is_active ? 'success' : 'neutral'">{{ __($w->is_active ? 'inventory::ui.common.active' : 'inventory::ui.common.inactive') }}</x-core::status-badge></td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">{{ __('inventory::ui.warehouses.empty') }}</td></tr>
            @endforelse
        </tbody>
    </x-core::table>
</x-core::layouts.app>
