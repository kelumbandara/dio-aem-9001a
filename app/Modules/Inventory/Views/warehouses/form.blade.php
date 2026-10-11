@php
    $field = 'block w-full rounded-lg border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-indigo-600';
    $editing = $warehouse !== null;
@endphp
<x-core::layouts.app :title="$editing ? __('inventory::ui.warehouses.edit_title', ['code' => $warehouse->code]) : __('inventory::ui.warehouses.new')">
    <form method="POST" action="{{ $editing ? route('inventory.warehouses.update', $warehouse) : route('inventory.warehouses.store') }}" class="max-w-2xl space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        @csrf
        @if ($editing) @method('PUT') <input type="hidden" name="version" value="{{ $warehouse->version }}"> @endif
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if ($editing)
                <div><span class="mb-1 block text-sm font-medium text-slate-700">{{ __('inventory::ui.common.code') }}</span><p class="py-2 font-mono text-sm">{{ $warehouse->code }}</p></div>
            @else
                <x-core::input name="code" :label="__('inventory::ui.common.code')" :value="old('code')" required maxlength="20" />
            @endif
            <x-core::input name="name" :label="__('inventory::ui.common.name')" :value="old('name', $warehouse?->name)" required maxlength="150" />
            <div>
                <label for="f-branch" class="mb-1 block text-sm font-medium text-slate-700">{{ __('inventory::ui.warehouses.branch') }}</label>
                <select id="f-branch" name="branch" class="{{ $field }}">
                    <option value="">—</option>
                    @foreach ($branchChoices as $id => $label) @php $code = explode(' — ', $label)[0]; @endphp <option value="{{ $code }}" @selected(old('branch', $branchCode) === $code)>{{ $label }}</option> @endforeach
                </select>
                @error('branch') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <x-core::input name="notes" :label="__('inventory::ui.documents.notes')" :value="old('notes', $warehouse?->notes)" maxlength="500" />
        </div>
        <fieldset class="space-y-2 text-sm">
            <input type="hidden" name="uses_locations" value="0">
            <label class="flex items-center gap-2"><input type="checkbox" name="uses_locations" value="1" class="rounded border-slate-300 text-indigo-600" @checked((bool) old('uses_locations', $warehouse?->uses_locations ?? false))> {{ __('inventory::ui.warehouses.uses_locations') }}</label>
            <p class="ps-6 text-xs text-slate-500">{{ __('inventory::ui.warehouses.uses_locations_hint') }}</p>
            <input type="hidden" name="is_active" value="0">
            <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" class="rounded border-slate-300 text-indigo-600" @checked((bool) old('is_active', $warehouse?->is_active ?? true))> {{ __('inventory::ui.common.active') }}</label>
        </fieldset>
        <div class="flex gap-3 pt-2">
            <x-core::button type="submit">{{ __('inventory::ui.common.save') }}</x-core::button>
            <a href="{{ $editing ? route('inventory.warehouses.show', $warehouse) : route('inventory.warehouses.index') }}" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ __('inventory::ui.common.cancel') }}</a>
        </div>
    </form>
</x-core::layouts.app>
