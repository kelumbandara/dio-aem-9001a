@php $canManage = auth()->user()->can('update', $warehouse); @endphp
<x-core::layouts.app :title="$warehouse->code.' — '.$warehouse->name">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2 text-sm text-slate-600">
            <x-core::status-badge :tone="$warehouse->is_active ? 'success' : 'neutral'">{{ __($warehouse->is_active ? 'inventory::ui.common.active' : 'inventory::ui.common.inactive') }}</x-core::status-badge>
            <span>{{ $branch ?? __('inventory::ui.warehouses.no_branch') }}</span>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('inventory.warehouses.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('inventory::ui.common.back') }}</a>
            @if ($canManage)
                <a href="{{ route('inventory.warehouses.edit', $warehouse) }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">{{ __('inventory::ui.common.edit') }}</a>
            @endif
        </div>
    </div>
    @if ($warehouse->notes) <p class="mb-4 text-sm text-slate-700">{{ $warehouse->notes }}</p> @endif

    <section class="max-w-3xl">
        <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('inventory::ui.warehouses.locations') }}</h2>
        @unless ($warehouse->uses_locations) <p class="mb-2 text-sm text-slate-600">{{ __('inventory::ui.warehouses.uses_locations_hint') }}</p> @endunless
        <x-core::table>
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.code') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.name') }}</th>
                    <th class="px-4 py-3 text-start font-medium">{{ __('inventory::ui.common.status') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($warehouse->locations as $l)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs">{{ $l->code }}@if ($l->is_default) <x-core::status-badge tone="info">{{ __('inventory::ui.warehouses.default') }}</x-core::status-badge>@endif</td>
                        <td class="px-4 py-3">{{ $l->name }}</td>
                        <td class="px-4 py-3"><x-core::status-badge :tone="$l->is_active ? 'success' : 'neutral'">{{ __($l->is_active ? 'inventory::ui.common.active' : 'inventory::ui.common.inactive') }}</x-core::status-badge></td>
                        <td class="px-4 py-3 text-end">
                            @if ($canManage && ! $l->is_default)
                                <form method="POST" action="{{ route('inventory.warehouses.locations.update', [$warehouse, $l]) }}">@csrf @method('PUT')
                                    <input type="hidden" name="is_active" value="{{ $l->is_active ? 0 : 1 }}">
                                    <button type="submit" class="text-sm text-indigo-600 hover:underline">{{ __($l->is_active ? 'inventory::ui.warehouses.deactivate' : 'inventory::ui.warehouses.activate') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-core::table>

        @if ($canManage && $warehouse->is_active)
            <form method="POST" action="{{ route('inventory.warehouses.locations.store', $warehouse) }}" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                @csrf
                <x-core::input name="code" :label="__('inventory::ui.common.code')" :value="old('code')" required maxlength="30" />
                <x-core::input name="name" :label="__('inventory::ui.common.name')" :value="old('name')" required maxlength="150" />
                <div class="flex items-end"><x-core::button type="submit">{{ __('inventory::ui.warehouses.add_location') }}</x-core::button></div>
            </form>
        @endif
    </section>
</x-core::layouts.app>
